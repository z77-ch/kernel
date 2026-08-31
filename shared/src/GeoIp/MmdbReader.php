<?php

namespace Z77\Shared\GeoIp;

/**
 * Minimal reader for the MaxMind DB (MMDB) binary format — enough to answer
 * "which country does this IP belong to", and deliberately nothing more.
 *
 * Why hand-written instead of a dependency: the format is small and closed
 * (a binary search tree over IP bits, plus a typed data section), the whole
 * consumer surface is {@see CountryLookup}, and a country map is a peripheral
 * feature that must not drag a package tree into the kernel. The decoder is
 * complete for the types MaxMind actually emits, so it degrades on nothing.
 *
 * Layout, for the next reader of this file:
 *
 *   [ search tree ][ 16 zero bytes ][ data section ][ magic ][ metadata ]
 *
 * A node holds two records of `record_size` bits. Walking one bit of the
 * address picks a record. A record BELOW node_count is the next node; equal
 * to node_count means "no data"; above it points into the data section, at
 * `searchTreeSize + record - node_count`.
 *
 * ⚠️ File handles are per instance and the class is NOT thread-shared; one
 * request opens it, looks up, and lets it close. Nothing here throws for bad
 * INPUT (an unparseable address is simply "no answer") — only a broken FILE
 * raises, because that is an installation fault, not a visitor's doing.
 */
final class MmdbReader
{
    private const MAGIC        = "\xAB\xCD\xEF" . "MaxMind.com";
    private const METADATA_MAX = 131072; // spec: metadata lives in the last 128 KB
    private const DATA_GAP     = 16;     // zero bytes between tree and data section

    /** @var resource */
    private $handle;

    private array $metadata;
    private int $nodeCount;
    private int $recordSize;
    private int $nodeBytes;
    private int $searchTreeSize;
    private int $ipVersion;

    /** Lazily walked: where the IPv4 subtree begins inside an IPv6 database. */
    private ?int $ipv4Start = null;

    public function __construct(private readonly string $file)
    {
        if (!is_file($this->file) || !is_readable($this->file)) {
            throw new \RuntimeException("MMDB nicht lesbar: {$this->file}");
        }

        $handle = @fopen($this->file, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("MMDB nicht zu oeffnen: {$this->file}");
        }
        $this->handle = $handle;

        $this->metadata   = $this->readMetadata();
        $this->nodeCount  = (int)($this->metadata['node_count'] ?? 0);
        $this->recordSize = (int)($this->metadata['record_size'] ?? 0);
        $this->ipVersion  = (int)($this->metadata['ip_version'] ?? 6);

        if (!in_array($this->recordSize, [24, 28, 32], true) || $this->nodeCount <= 0) {
            throw new \RuntimeException("MMDB-Kopf unplausibel: {$this->file}");
        }

        $this->nodeBytes      = intdiv($this->recordSize, 4); // two records per node
        $this->searchTreeSize = $this->nodeCount * $this->nodeBytes;
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    /** @return array<string, mixed> the database's own metadata record */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /**
     * The record stored for $ip, or null when the address is unparseable or
     * the tree holds nothing for it (unassigned ranges, private networks).
     *
     * @return array<string, mixed>|null
     */
    public function get(string $ip): ?array
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return null;
        }

        $bits = strlen($packed) * 8; // 32 for IPv4, 128 for IPv6

        if ($bits === 128 && $this->ipVersion === 4) {
            return null; // an IPv6 address in an IPv4-only database
        }

        if ($bits === 32 && $this->ipVersion === 6) {
            // MaxMind stores IPv4 inside the IPv6 tree. Rather than guessing a
            // mapped prefix (::ffff:/96 vs ::/96 — databases differ), walk the
            // 96 zero bits once and remember where that lands.
            $node = $this->ipv4StartNode();
            if ($node === null) {
                return null;
            }
        } else {
            $node = 0;
        }

        $offset = $this->walk($packed, $bits, $node);

        return $offset === null ? null : $this->decodeRecord($offset);
    }

    /**
     * Follows the address bit by bit. Returns the ABSOLUTE data-section offset
     * of the record, or null when the tree has no data for this address.
     */
    private function walk(string $packed, int $bits, int $node): ?int
    {
        for ($i = 0; $i < $bits; $i++) {
            if ($node >= $this->nodeCount) {
                break;
            }
            $byte = ord($packed[$i >> 3]);
            $bit  = ($byte >> (7 - ($i % 8))) & 1;
            $node = $this->readRecord($node, $bit);
        }

        if ($node === $this->nodeCount) {
            return null; // the documented "nothing here" marker
        }
        if ($node < $this->nodeCount) {
            // Ran out of bits while still inside the tree — the database is
            // inconsistent for this address; say "unknown" rather than guess.
            return null;
        }

        return $this->searchTreeSize + $node - $this->nodeCount;
    }

    /** Where the IPv4 branch starts in an IPv6 tree: 96 zero bits down. */
    private function ipv4StartNode(): ?int
    {
        if ($this->ipv4Start !== null) {
            return $this->ipv4Start === -1 ? null : $this->ipv4Start;
        }

        $node = 0;
        for ($i = 0; $i < 96 && $node < $this->nodeCount; $i++) {
            $node = $this->readRecord($node, 0);
        }

        $this->ipv4Start = $node < $this->nodeCount ? $node : -1;

        return $this->ipv4Start === -1 ? null : $this->ipv4Start;
    }

    /** One record (left = 0, right = 1) out of a node. */
    private function readRecord(int $node, int $bit): int
    {
        $raw = $this->readAt($node * $this->nodeBytes, $this->nodeBytes);

        return match ($this->recordSize) {
            24 => $this->uint($bit === 0 ? substr($raw, 0, 3) : substr($raw, 3, 3)),
            32 => $this->uint($bit === 0 ? substr($raw, 0, 4) : substr($raw, 4, 4)),
            // 28 bits: the middle byte carries the high nibble of BOTH records —
            // high nibble belongs to the left, low nibble to the right.
            default => $bit === 0
                ? ((ord($raw[3]) & 0xF0) << 20) | $this->uint(substr($raw, 0, 3))
                : ((ord($raw[3]) & 0x0F) << 24) | $this->uint(substr($raw, 4, 3)),
        };
    }

    /**
     * walk() already hands back an ABSOLUTE file offset: the spec defines a
     * data record's value as `dataOffset + node_count + 16`, and the data
     * section starts at `searchTreeSize + 16` — the two 16s cancel, leaving
     * `searchTreeSize + record - node_count`. Nothing to add here.
     *
     * @return array<string, mixed>|null
     */
    private function decodeRecord(int $absolute): ?array
    {
        [$value] = $this->decode($absolute);

        return is_array($value) ? $value : null;
    }

    private function dataStart(): int
    {
        return $this->searchTreeSize + self::DATA_GAP;
    }

    /**
     * Decodes one value at an absolute file offset.
     *
     * @return array{0: mixed, 1: int} value and the offset just after it
     */
    private function decode(int $offset): array
    {
        $control = ord($this->readAt($offset, 1));
        $offset++;
        $type = $control >> 5;

        if ($type === 0) { // extended type: the next byte carries the real one
            $type = ord($this->readAt($offset, 1)) + 7;
            $offset++;
        }

        if ($type === 1) { // pointer — its size bits mean something else entirely
            return $this->decodePointer($control, $offset);
        }

        [$size, $offset] = $this->decodeSize($control, $offset);

        switch ($type) {
            case 2: // utf8_string
                return [$size === 0 ? '' : $this->readAt($offset, $size), $offset + $size];

            case 5: // uint16
            case 6: // uint32
            case 9: // uint64
                return [$size === 0 ? 0 : $this->uint($this->readAt($offset, $size)), $offset + $size];

            case 7: // map
                $map = [];
                for ($i = 0; $i < $size; $i++) {
                    [$key, $offset]   = $this->decode($offset);
                    [$value, $offset] = $this->decode($offset);
                    $map[(string)$key] = $value;
                }

                return [$map, $offset];

            case 11: // array
                $list = [];
                for ($i = 0; $i < $size; $i++) {
                    [$value, $offset] = $this->decode($offset);
                    $list[] = $value;
                }

                return [$list, $offset];

            case 14: // boolean — the size field IS the value
                return [$size === 1, $offset];

            case 8: // int32, two's complement
                $raw = $size === 0 ? 0 : $this->uint($this->readAt($offset, $size));
                if ($size === 4 && $raw > 0x7FFFFFFF) {
                    $raw -= 0x100000000;
                }

                return [$raw, $offset + $size];

            case 3: // double
                return [$size === 8 ? unpack('E', $this->readAt($offset, 8))[1] : 0.0, $offset + $size];

            case 15: // float
                return [$size === 4 ? unpack('G', $this->readAt($offset, 4))[1] : 0.0, $offset + $size];

            default: // bytes (4), uint128 (10), container (12), end marker (13)
                return [$size === 0 ? '' : $this->readAt($offset, $size), $offset + $size];
        }
    }

    /**
     * Pointers pack their own size into the control byte: two bits say how
     * many extra bytes follow, three bits are the pointer's high bits. The
     * added constants are the format's way of not wasting the short forms.
     *
     * @return array{0: mixed, 1: int}
     */
    private function decodePointer(int $control, int $offset): array
    {
        $size  = ($control >> 3) & 0x3;
        $high  = $control & 0x7;
        $extra = $size + 1;
        $raw   = $this->uint($this->readAt($offset, $extra));
        $after = $offset + $extra;

        $pointer = match ($size) {
            0       => ($high << 8) | $raw,
            1       => (($high << 16) | $raw) + 2048,
            2       => (($high << 24) | $raw) + 526336,
            default => $raw, // 4 extra bytes: the control byte's bits are unused
        };

        // A pointer is relative to the data section, and it may point at
        // another pointer — decode() handles that by recursing.
        [$value] = $this->decode($this->dataStart() + $pointer);

        return [$value, $after];
    }

    /** @return array{0: int, 1: int} size and the offset after any size bytes */
    private function decodeSize(int $control, int $offset): array
    {
        $size = $control & 0x1F;

        if ($size < 29) {
            return [$size, $offset];
        }
        if ($size === 29) {
            return [29 + ord($this->readAt($offset, 1)), $offset + 1];
        }
        if ($size === 30) {
            return [285 + $this->uint($this->readAt($offset, 2)), $offset + 2];
        }

        return [65821 + $this->uint($this->readAt($offset, 3)), $offset + 3];
    }

    /** @return array<string, mixed> */
    private function readMetadata(): array
    {
        $size = filesize($this->file);
        if ($size === false || $size < 20) {
            throw new \RuntimeException("MMDB zu klein: {$this->file}");
        }

        $tailLength = (int)min(self::METADATA_MAX, $size);
        $tail       = $this->readAt($size - $tailLength, $tailLength);
        $at         = strrpos($tail, self::MAGIC);

        if ($at === false) {
            throw new \RuntimeException("MMDB ohne Kennung — keine MaxMind-Datei? {$this->file}");
        }

        $start = $size - $tailLength + $at + strlen(self::MAGIC);
        [$meta] = $this->decode($start);

        if (!is_array($meta)) {
            throw new \RuntimeException("MMDB-Metadaten unlesbar: {$this->file}");
        }

        return $meta;
    }

    private function readAt(int $offset, int $length): string
    {
        if ($length <= 0) {
            return '';
        }
        fseek($this->handle, $offset);
        $raw = fread($this->handle, $length);

        if ($raw === false || strlen($raw) !== $length) {
            throw new \RuntimeException("MMDB abgeschnitten bei {$offset}+{$length}: {$this->file}");
        }

        return $raw;
    }

    /** Big-endian unsigned integer of arbitrary byte length. */
    private function uint(string $bytes): int
    {
        $value = 0;
        for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
            $value = ($value << 8) | ord($bytes[$i]);
        }

        return $value;
    }
}
