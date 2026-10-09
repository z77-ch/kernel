<?php

namespace Z77\Shared\Upload;

use Z77\Shared\ValueObjects\UploadedFile;

/**
 * What a controller allows an upload to be — ONE object that writes the client's
 * attributes AND performs the server's own check, so the two cannot drift apart.
 *
 * The client gate is a convenience (it spares the user a pointless round trip);
 * {@see check()} is the decision. A client without JavaScript, a crafted request or a
 * renamed file all arrive at the same method.
 *
 * The three SHAPES are the same component, not three implementations (see
 * `partials/upload`):
 *
 *   - `drop`  the drop zone inside the work area — the default
 *   - `cell`  a button in the shell's action cell; the whole work area is the drop target
 *   - `field` one file in a form, with its preview and «entfernen»
 *
 * `onConflict` is the controller's per-endpoint decision (owner, 2026-10-09): the Drive
 * asks before overwriting, an import never overwrites, a logo always does. The CLIENT only
 * reads it to know whether to show the prompt; which answer is honoured is decided by the
 * endpoint that receives the second request.
 */
final class UploadPolicy
{
    public const CONFLICT_ASK       = 'ask';
    public const CONFLICT_OVERWRITE = 'overwrite';
    public const CONFLICT_SKIP      = 'skip';
    public const CONFLICT_ERROR     = 'error';

    /**
     * @param string               $endpoint    POST target — one request per file
     * @param list<string>         $accept      `.xml`, `image/*`, `application/pdf` … empty = anything
     * @param int|null             $maxBytes    per file; null = the transport cap of this host
     * @param array<string, int>   $maxBytesPer a SMALLER cap for some kinds, pattern => bytes
     *                                          (`image/*` => 8 MB). The Drive's case: every
     *                                          file is bounded by the transport cap, but an
     *                                          image must also fit in memory because GD
     *                                          decodes its pixels — one limit cannot say
     *                                          both, and a client that knows only the bigger
     *                                          one lets the user wait for a refusal.
     * @param string               $shape       `drop` | `cell` | `field`
     */
    public function __construct(
        public readonly string $endpoint,
        public readonly string $field = 'files[]',
        public readonly bool $multiple = true,
        public readonly array $accept = [],
        public readonly ?int $maxBytes = null,
        public readonly string $onConflict = self::CONFLICT_ASK,
        public readonly string $shape = 'drop',
        public readonly string $label = '',
        public readonly string $hint = '',
        public readonly array $maxBytesPer = [],
    ) {
        if ($endpoint === '') {
            throw new \InvalidArgumentException('UploadPolicy needs an endpoint.');
        }
        if (!in_array($shape, ['drop', 'cell', 'field'], true)) {
            throw new \InvalidArgumentException("UploadPolicy: unknown shape '{$shape}'.");
        }
        if (!in_array($onConflict, [self::CONFLICT_ASK, self::CONFLICT_OVERWRITE, self::CONFLICT_SKIP, self::CONFLICT_ERROR], true)) {
            throw new \InvalidArgumentException("UploadPolicy: unknown conflict mode '{$onConflict}'.");
        }
    }

    /**
     * The same policy in another shape — one endpoint is often reachable twice on a page
     * (the action cell's button AND the drop zone in the work area, exactly what the design
     * draws). The limits must then be the same object, not two that drift.
     */
    public function withShape(string $shape): self
    {
        return new self(
            endpoint:    $this->endpoint,
            field:       $this->field,
            multiple:    $this->multiple,
            accept:      $this->accept,
            maxBytes:    $this->maxBytes,
            onConflict:  $this->onConflict,
            shape:       $shape,
            label:       $this->label,
            hint:        $this->hint,
            maxBytesPer: $this->maxBytesPer,
        );
    }

    /** The per-file byte cap that actually applies: the configured one, never above the transport cap. */
    public function effectiveMaxBytes(): int
    {
        $transport = self::transportMaxBytes();

        return $this->maxBytes === null ? $transport : min($this->maxBytes, $transport);
    }

    /**
     * The cap for ONE file: the general one, lowered by the first matching entry of
     * `maxBytesPer`. Patterns are the `accept` vocabulary (`.png`, `image/*`,
     * `application/pdf`), so a developer learns one syntax, not two.
     */
    public function maxBytesFor(string $name, string $mimeType): int
    {
        $general   = $this->effectiveMaxBytes();
        $extension = '.' . strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime      = strtolower($mimeType);

        foreach ($this->maxBytesPer as $pattern => $bytes) {
            $pattern = strtolower(trim((string) $pattern));
            $hit = match (true) {
                $pattern === ''                 => false,
                $pattern[0] === '.'             => $pattern === $extension,
                str_ends_with($pattern, '/*')   => $mime !== '' && str_starts_with($mime, substr($pattern, 0, -1)),
                default                         => $pattern === $mime,
            };
            if ($hit) {
                return min($general, (int) $bytes);
            }
        }

        return $general;
    }

    /**
     * What PHP will accept over the wire at all — the smaller of `upload_max_filesize`
     * and `post_max_size`. A larger `maxBytes` is a promise the host cannot keep: the
     * request is cut before any code of ours runs, which looks like a silent failure.
     */
    public static function transportMaxBytes(): int
    {
        $upload = self::iniBytes('upload_max_filesize');
        $post   = self::iniBytes('post_max_size');
        $both   = array_values(array_filter([$upload, $post], static fn(int $v): bool => $v > 0));

        return $both === [] ? 2 * 1024 * 1024 : min($both);
    }

    /**
     * The attributes the client reads, ready for the partial. Everything the JS needs is
     * here — it never guesses a limit and never carries a default of its own.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [
            'data-upload'             => $this->shape,
            'data-upload-max'         => (string) $this->effectiveMaxBytes(),
            'data-upload-conflict'    => $this->onConflict,
            'action'                  => $this->endpoint,
        ];
        if ($this->accept !== []) {
            $attributes['data-upload-accept'] = implode(',', $this->accept);
        }
        if ($this->maxBytesPer !== []) {
            // JSON, because the value is a map and an attribute per kind would grow a
            // second vocabulary. The client lowers its own gate with it; the decision is
            // still `maxBytesFor()` on the server.
            $capped = [];
            foreach ($this->maxBytesPer as $pattern => $bytes) {
                $capped[(string) $pattern] = min($this->effectiveMaxBytes(), (int) $bytes);
            }
            $attributes['data-upload-max-per'] = (string) json_encode($capped);
        }
        if ($this->multiple) {
            $attributes['data-upload-multiple'] = '1';
        }

        return $attributes;
    }

    /**
     * The server's verdict on ONE file: null = acceptable, else the message the row shows.
     *
     * German, because it is shown to the user as it is (the rest of the framework's
     * messages are built the same way). Deliberately names WHAT is wrong and not the
     * limit's origin — «zu gross (max. 5 MB)» is actionable, «post_max_size» is not.
     */
    public function check(UploadedFile $file): ?string
    {
        $limit = $this->maxBytesFor($file->originalName, $file->isOk() ? $file->sniffMime() : $file->clientMimeType);

        if ($file->error === UPLOAD_ERR_INI_SIZE || $file->error === UPLOAD_ERR_FORM_SIZE) {
            return 'zu gross (max. ' . self::formatBytes($limit) . ')';
        }
        if ($file->error === UPLOAD_ERR_NO_FILE) {
            return 'keine Datei empfangen';
        }
        if (!$file->isOk()) {
            return 'Übertragung fehlgeschlagen (Fehler ' . $file->error . ')';
        }
        if ($file->size <= 0) {
            return 'leere Datei';
        }
        if ($file->size > $limit) {
            return 'zu gross (max. ' . self::formatBytes($limit) . ')';
        }
        if (!$this->accepts($file)) {
            return 'nicht erlaubt (' . implode(', ', $this->accept) . ')';
        }

        return null;
    }

    /**
     * Does the file match `accept`? An extension entry (`.xml`) is compared against the
     * name, a type entry (`image/*`, `application/pdf`) against the SNIFFED type — never
     * against the client's claim, which is attacker-chosen.
     *
     * A sniffer that answers `application/octet-stream` for a text format (XML, CSV) is
     * normal, so an extension entry remains the way to allow those.
     */
    public function accepts(UploadedFile $file): bool
    {
        if ($this->accept === []) {
            return true;
        }

        $extension = '.' . strtolower($file->extension());
        $mime      = strtolower($file->sniffMime());

        foreach ($this->accept as $entry) {
            $entry = strtolower(trim($entry));
            if ($entry === '') {
                continue;
            }
            if ($entry[0] === '.') {
                if ($entry === $extension) {
                    return true;
                }
                continue;
            }
            if (str_ends_with($entry, '/*')) {
                if (str_starts_with($mime, substr($entry, 0, -1))) {
                    return true;
                }
                continue;
            }
            if ($entry === $mime) {
                return true;
            }
        }

        return false;
    }

    /** «5 MB» / «900 KB» — the hint and the error message say the same thing. */
    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            $mb = $bytes / (1024 * 1024);

            return rtrim(rtrim(number_format($mb, $mb < 10 ? 1 : 0, '.', ''), '0'), '.') . ' MB';
        }

        return max(1, (int) round($bytes / 1024)) . ' KB';
    }

    private static function iniBytes(string $key): int
    {
        $value = trim((string) ini_get($key));
        if ($value === '') {
            return 0;
        }

        $unit   = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g'     => $number * 1024 * 1024 * 1024,
            'm'     => $number * 1024 * 1024,
            'k'     => $number * 1024,
            default => $number,
        };
    }
}
