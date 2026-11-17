<?php

namespace Z77\Shared\Payment\Camt;

/**
 * The XML files inside a ZIP a bank hands out (owner 2026-11-13: a Swiss bank delivers its camt
 * files as `<BANK>_camt054_<timestamp>.zip`). Reads the archive from its bytes and returns the
 * `.xml` entries — nothing is written to the project, the archive goes to a temp file only
 * because `ZipArchive` opens paths, and that file is removed before the method returns.
 *
 * Bounded, because the bytes come from an upload: at most `MAX_ENTRIES` XML entries, each at
 * most `MAX_ENTRY_BYTES` uncompressed (a month's camt.054 is a few hundred KB, a year's
 * camt.053 below 2 MB), all together at most `MAX_TOTAL_BYTES` — the sizes are read from the
 * central directory BEFORE an entry is inflated, and the read itself is capped too (a lying
 * directory gains nothing). Directories, other file types and macOS metadata (`__MACOSX/`,
 * `._*`) are skipped. An entry's name is reduced to its base name; it is a label for the row,
 * never a path.
 *
 * Shared by debtor's camt.054 import and financial's camt.053 import — moved from module-debtor
 * together with the parser (owner decision 12 of the bank account service, 2026-11-15).
 */
final class CamtArchive
{
    public const MAX_ENTRIES     = 100;
    public const MAX_ENTRY_BYTES = 5 * 1024 * 1024;
    public const MAX_TOTAL_BYTES = 50 * 1024 * 1024;

    /**
     * @return list<array{name: string, xml: string}> in archive order
     * @throws CamtFormatException ARCHIVE — not a readable ZIP, no XML inside, over a limit
     */
    public static function xmlFiles(string $zipBytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'z77camt');
        if ($path === false || file_put_contents($path, $zipBytes) === false) {
            throw new \RuntimeException('Cannot write a temp file for the uploaded archive.');
        }
        $zip    = new \ZipArchive();
        $opened = false;
        try {
            $opened = $zip->open($path, \ZipArchive::RDONLY) === true;
            if (!$opened) {
                throw new CamtFormatException(CamtFormatException::ARCHIVE, 'Die ZIP-Datei lässt sich nicht öffnen.');
            }
            $files = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = is_array($stat) ? (string) $stat['name'] : '';
                $base = basename(str_replace('\\', '/', $name));
                if ($name === '' || str_ends_with($name, '/') || str_starts_with($name, '__MACOSX/') || str_starts_with($base, '._')
                    || strtolower(pathinfo($base, PATHINFO_EXTENSION)) !== 'xml') {
                    continue;
                }
                $size = (int) $stat['size'];
                if (count($files) >= self::MAX_ENTRIES) {
                    throw new CamtFormatException(CamtFormatException::ARCHIVE, 'Die ZIP-Datei enthält mehr als ' . self::MAX_ENTRIES . ' XML-Dateien — bitte aufteilen.');
                }
                if ($size > self::MAX_ENTRY_BYTES || $total + $size > self::MAX_TOTAL_BYTES) {
                    throw new CamtFormatException(CamtFormatException::ARCHIVE, $base . ' in der ZIP-Datei ist zu gross.');
                }
                $xml = $zip->getFromIndex($i, self::MAX_ENTRY_BYTES + 1);
                if ($xml === false || strlen($xml) > self::MAX_ENTRY_BYTES) {
                    throw new CamtFormatException(CamtFormatException::ARCHIVE, $base . ' in der ZIP-Datei lässt sich nicht lesen.');
                }
                $total  += strlen($xml);
                $files[] = ['name' => $base, 'xml' => $xml];
            }
            if ($files === []) {
                throw new CamtFormatException(CamtFormatException::ARCHIVE, 'Die ZIP-Datei enthält keine XML-Datei.');
            }

            return $files;
        } finally {
            if ($opened) {
                $zip->close();
            }
            @unlink($path);
        }
    }
}
