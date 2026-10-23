<?php

namespace Z77\Shared\Pdf;

/**
 * Several PDF FILES written by {@see PdfDocument} joined into ONE file, page for page, the bytes
 * of every page left untouched (Faktura-Journal, ADR-054 decision 25 — owner 2026-10-23: an
 * issued invoice is delivered as the ARCHIVED file, never re-rendered, also in «Alle als ein
 * PDF»). The content streams, fonts and images of each file are copied as they are; only the
 * object numbers are renumbered and the page tree is rebuilt.
 *
 * Deliberately narrow — not a general PDF library: it reads the shape the vendored writer
 * (FPDF 1.9) produces and refuses everything else with an \InvalidArgumentException — a classic
 * cross-reference table (no xref stream, no object stream, no incremental update), objects
 * written one after the other, one page tree with its pages as direct kids, the trailer naming
 * `/Root` and `/Info`. A file of another producer is not «repaired»: the caller keeps it apart.
 *
 * What it writes: the highest `%PDF-1.x` of the inputs, then every object of every file except
 * its page-tree root, catalog and info (renumbered, references rewritten outside strings and
 * outside stream data), one new page tree over all pages in input order (a page without its own
 * MediaBox gets its file's default, so files of different sizes keep their pages), a new info
 * with the title, a new catalog, a new cross-reference table.
 */
final class PdfConcat
{
    /**
     * @param list<string> $pdfs the files' bytes, in page order — at least one
     * @throws \InvalidArgumentException a file that is not in the writer's shape
     */
    public static function of(array $pdfs, string $title = ''): string
    {
        if ($pdfs === []) {
            throw new \InvalidArgumentException('PdfConcat needs at least one PDF');
        }
        $version = '1.3';
        $objects = [];   // new number => bytes between «n 0 obj\n» and «endobj»
        $kids    = [];
        $next    = 2;    // 1 = the new page tree
        foreach (array_values($pdfs) as $f => $pdf) {
            if (!is_string($pdf) || preg_match('/^%PDF-(1\.\d)\n/', $pdf, $m) !== 1) {
                throw new \InvalidArgumentException("PdfConcat: input {$f} is no PDF of the writer (header)");
            }
            $version = version_compare($m[1], $version, '>') ? $m[1] : $version;
            $file    = self::read($pdf, $f);
            $map     = [$file['pages'] => 1];
            foreach (array_keys($file['objects']) as $old) {
                if ($old !== $file['pages'] && $old !== $file['root'] && $old !== $file['info']) {
                    $map[$old] = $next++;
                }
            }
            foreach ($file['objects'] as $old => $body) {
                if (!isset($map[$old]) || $old === $file['pages']) {
                    continue;
                }
                $body = self::renumber($body, $map, $f);
                if (in_array($old, $file['kids'], true) && !str_contains(self::dictOf($body), '/MediaBox') && $file['mediaBox'] !== null) {
                    $body = preg_replace('/^<<\/Type \/Page\b/', '<</Type /Page' . "\n/MediaBox " . $file['mediaBox'], $body, 1) ?? $body;
                }
                $objects[$map[$old]] = $body;
            }
            foreach ($file['kids'] as $kid) {
                $kids[] = $map[$kid];
            }
        }
        $info    = $next++;
        $catalog = $next++;
        $objects[1] = "<</Type /Pages\n/Kids [" . implode(' ', array_map(static fn(int $n) => $n . ' 0 R', $kids)) . "]\n/Count " . count($kids) . "\n>>\n";
        $objects[$info] = "<<\n/Producer (z77)\n" . ($title !== '' ? '/Title ' . self::textString($title) . "\n" : '') . ">>\n";
        $objects[$catalog] = "<<\n/Type /Catalog\n/Pages 1 0 R\n>>\n";
        ksort($objects);

        $out     = '%PDF-' . $version . "\n";
        $offsets = [];
        foreach ($objects as $n => $body) {
            $offsets[$n] = strlen($out);
            $out .= $n . " 0 obj\n" . $body . "endobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 " . ($catalog + 1) . "\n0000000000 65535 f \n";
        for ($n = 1; $n <= $catalog; $n++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$n]);
        }

        return $out . "trailer\n<<\n/Size " . ($catalog + 1) . "\n/Root {$catalog} 0 R\n/Info {$info} 0 R\n>>\nstartxref\n{$xref}\n%%EOF\n";
    }

    /**
     * The objects of one file by number, its catalog, info, page tree, kids and default MediaBox.
     *
     * @return array{objects: array<int, string>, root: int, info: int, pages: int, kids: list<int>, mediaBox: ?string}
     */
    private static function read(string $pdf, int $f): array
    {
        if (preg_match('/startxref\n(\d+)\n%%EOF\n?$/', $pdf, $m) !== 1) {
            throw new \InvalidArgumentException("PdfConcat: input {$f} has no single trailer (incremental update or another writer)");
        }
        $xref = (int) $m[1];
        if (preg_match('/\Gxref\n0 (\d+)\n/', $pdf, $h, 0, $xref) !== 1) {
            throw new \InvalidArgumentException("PdfConcat: input {$f} has no classic cross-reference table");
        }
        $count   = (int) $h[1];
        $entries = substr($pdf, $xref + strlen($h[0]), $count * 20);
        $offsets = [];
        for ($n = 1; $n < $count; $n++) {
            $entry = substr($entries, $n * 20, 20);
            if (preg_match('/^(\d{10}) 00000 n \n$/', $entry, $e) !== 1) {
                throw new \InvalidArgumentException("PdfConcat: input {$f} — cross-reference entry {$n} is not in use");
            }
            $offsets[$n] = (int) $e[1];
        }
        $sorted = $offsets;
        asort($sorted);
        $ends    = array_values($sorted);
        $ends[]  = $xref;
        $objects = [];
        $i       = 0;
        foreach ($sorted as $n => $start) {
            $chunk = substr($pdf, $start, $ends[++$i] - $start);
            $head  = $n . " 0 obj\n";
            if (!str_starts_with($chunk, $head) || !str_ends_with($chunk, "endobj\n")) {
                throw new \InvalidArgumentException("PdfConcat: input {$f} — object {$n} is not where its cross-reference says");
            }
            $objects[$n] = substr($chunk, strlen($head), -strlen("endobj\n"));
        }
        $trailer = substr($pdf, $xref + strlen($h[0]) + $count * 20);
        if (preg_match('/\/Root (\d+) 0 R/', $trailer, $r) !== 1 || preg_match('/\/Info (\d+) 0 R/', $trailer, $in) !== 1) {
            throw new \InvalidArgumentException("PdfConcat: input {$f} — the trailer names no /Root or /Info");
        }
        $root = (int) $r[1];
        if (!isset($objects[$root]) || preg_match('/\/Pages (\d+) 0 R/', $objects[$root], $p) !== 1 || !isset($objects[(int) $p[1]])) {
            throw new \InvalidArgumentException("PdfConcat: input {$f} — the catalog names no page tree");
        }
        $pages = (int) $p[1];
        if (preg_match('/\/Kids \[([0-9 R]*)\]/', $objects[$pages], $k) !== 1) {
            throw new \InvalidArgumentException("PdfConcat: input {$f} — the page tree has no kids");
        }
        preg_match_all('/(\d+) 0 R/', $k[1], $refs);
        $kids = array_map('intval', $refs[1]);
        foreach ($kids as $kid) {
            if (!isset($objects[$kid]) || !str_starts_with($objects[$kid], '<</Type /Page')) {
                throw new \InvalidArgumentException("PdfConcat: input {$f} — kid {$kid} is no page (nested page trees are not read)");
            }
        }
        $mediaBox = preg_match('/\/MediaBox (\[[0-9. ]+\])/', $objects[$pages], $mb) === 1 ? $mb[1] : null;

        return ['objects' => $objects, 'root' => $root, 'info' => (int) $in[1], 'pages' => $pages, 'kids' => $kids, 'mediaBox' => $mediaBox];
    }

    /** The dictionary part of an object body — everything before its stream data. */
    private static function dictOf(string $body): string
    {
        $at = strpos($body, ">>\nstream\n");

        return $at === false ? $body : substr($body, 0, $at + 2);
    }

    /**
     * Every «n 0 R» of $body rewritten through $map — in the dictionary part only (never in the
     * stream data), and never inside a literal string «( … )».
     *
     * @param array<int, int> $map
     */
    private static function renumber(string $body, array $map, int $f): string
    {
        $dict   = self::dictOf($body);
        $stream = substr($body, strlen($dict));
        $out    = '';
        $plain  = '';
        $len    = strlen($dict);
        $swap   = static function (string $text) use ($map, $f): string {
            return preg_replace_callback('/(?<![0-9.])(\d+) 0 R\b/', static function (array $m) use ($map, $f): string {
                $old = (int) $m[1];
                if (!isset($map[$old])) {
                    throw new \InvalidArgumentException("PdfConcat: input {$f} refers to object {$old}, which it does not contain");
                }

                return $map[$old] . ' 0 R';
            }, $text) ?? $text;
        };
        for ($i = 0; $i < $len; $i++) {
            $c = $dict[$i];
            if ($c !== '(') {
                $plain .= $c;
                continue;
            }
            // A literal string: copied as it is, nesting and backslash escapes honoured.
            $out  .= $swap($plain);
            $plain = '';
            $depth = 0;
            for (; $i < $len; $i++) {
                $out .= $dict[$i];
                if ($dict[$i] === '\\') {
                    $out .= $dict[++$i] ?? '';
                } elseif ($dict[$i] === '(') {
                    $depth++;
                } elseif ($dict[$i] === ')' && --$depth === 0) {
                    break;
                }
            }
        }

        return $out . $swap($plain) . $stream;
    }

    /** A UTF-16BE text string with byte order mark, hex-encoded — any title, no escaping rules. */
    private static function textString(string $text): string
    {
        $utf16 = function_exists('mb_convert_encoding') ? mb_convert_encoding($text, 'UTF-16BE', 'UTF-8') : $text;

        return '<FEFF' . strtoupper(bin2hex((string) $utf16)) . '>';
    }
}
