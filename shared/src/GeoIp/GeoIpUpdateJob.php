<?php

namespace Z77\Shared\GeoIp;

use Z77\Shared\Jobs\Job;
use Z77\Shared\Jobs\JobContext;
use Z77\Shared\Jobs\JobResult;

/**
 * Keeps the GeoLite2 database current — and that is a LICENCE OBLIGATION, not
 * a convenience. The GeoLite EULA requires the data to be kept up to date and
 * the previous version to be destroyed within 30 days, which is why this job
 * REPLACES the file and never leaves a second copy beside it.
 *
 * It is also the only way the database ever reaches a server: `data/` is never
 * deployed and the EULA forbids redistribution, so every installation fetches
 * its own. On a fresh installation with a licence key the first pass installs
 * it; without a key the job stays quiet for ever and {@see CountryLookup}
 * simply answers null.
 *
 * ⚠️ NOTHING here may cost a registration. The lookup is optional by design;
 * a missing key, an expired key, a MaxMind outage or a corrupt download all
 * end with the PREVIOUS database still in place and in use. The job reports
 * the failure to the operator and changes nothing else.
 *
 * ⚠️ The integrity check is not a checksum — it is {@see MmdbReader}. Before
 * anything is swapped in, the downloaded file is OPENED and asked for its
 * metadata. That catches a truncated transfer, a gzip that is really an HTML
 * error page, and an edition that does not parse, all with one test, and it
 * tests exactly the property that matters: can we read it afterwards.
 *
 * Configuration lives in `config/geoip.inc.php` (machine-local, never in git
 * and never deployed). Two ways to supply the key, and a key in the config
 * wins over MaxMind's ready-made `GeoIP.conf` in the database directory.
 *
 * Payload keys (both optional):
 *   force  — fetch even though the local database is still young
 *   dryRun — say what would happen, download and replace nothing
 */
final class GeoIpUpdateJob implements Job
{
    /** MaxMind's permalink endpoint. The edition and key travel as parameters. */
    private const ENDPOINT = 'https://download.maxmind.com/app/geoip_download';

    private const DEFAULT_EDITION = 'GeoLite2-Country';
    private const DEFAULT_MAX_AGE = 30;

    /** A country database is ~9 MB; ten times that is a runaway, not a database. */
    private const MAX_DOWNLOAD_BYTES = 100 * 1024 * 1024;

    /** Seconds the whole transfer may take before curl gives up. */
    private const TIMEOUT = 120;

    public function run(JobContext $context): JobResult
    {
        if (!defined('ABS_BASE_PATH')) {
            return JobResult::failed('ABS_BASE_PATH ist nicht gesetzt');
        }

        $config  = $this->config();
        $edition = (string) ($config['edition'] ?? self::DEFAULT_EDITION);
        $maxAge  = max(1, (int) ($config['maxAgeDays'] ?? self::DEFAULT_MAX_AGE));
        $payload = $context->getPayload();
        $force   = (bool) ($payload['force'] ?? false);
        $dryRun  = (bool) ($payload['dryRun'] ?? false);

        $key = $this->licenceKey($config);
        if ($key === '') {
            // Not a failure: an installation may deliberately run without a
            // country map. Saying so once per pass is the whole report.
            return JobResult::done(
                'kein Lizenzschluessel (config/geoip.inc.php oder GeoIP.conf) — Datenbank bleibt, wie sie ist'
            );
        }

        $ageDays = $this->localAgeDays();
        if (!$force && $ageDays !== null && $ageDays < $maxAge) {
            return JobResult::done(sprintf(
                'Datenbank ist %d Tage alt (Grenze %d) — nichts zu holen',
                $ageDays,
                $maxAge
            ));
        }

        $reason = $ageDays === null ? 'keine Datenbank vorhanden' : "Datenbank {$ageDays} Tage alt";
        $context->log("Abruf faellig: {$reason}");

        if ($dryRun) {
            return JobResult::done("Probelauf — {$reason}, es wuerde {$edition} geholt werden");
        }

        // The transfer cannot be sliced, so it needs room in THIS pass rather
        // than being started and abandoned halfway.
        if (!$context->hasTimeLeft(30)) {
            return JobResult::again(null, 0, 'zu wenig Zeit im Durchgang — Abruf folgt im naechsten');
        }

        return $this->fetchAndInstall($edition, $key, $context);
    }

    /**
     * Downloads and unpacks; {@see install()} does the rest. Every early
     * return leaves the installed database untouched.
     */
    private function fetchAndInstall(string $edition, string $key, JobContext $context): JobResult
    {
        if (!function_exists('curl_init')) {
            return JobResult::failed('curl fehlt — Abruf nicht moeglich');
        }

        $dir = $this->directory();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return JobResult::failed("Verzeichnis nicht anlegbar: {$dir}");
        }
        if (!is_writable($dir)) {
            return JobResult::failed("Verzeichnis nicht beschreibbar: {$dir}");
        }

        $archive = $this->download($edition, $key, $error);
        if ($archive === null) {
            return JobResult::failed($error ?? 'Abruf fehlgeschlagen');
        }

        $mmdb = $this->extractDatabase($archive, $error);
        if ($mmdb === null) {
            return JobResult::failed($error ?? 'Archiv enthaelt keine .mmdb');
        }

        return $this->install($mmdb, $edition, $context);
    }

    /**
     * Proves the bytes readable, then swaps them in — the half that decides
     * whether the installation ends up with a working database.
     *
     * Deliberately separate from the transfer above so it can be exercised
     * without a network: the property worth testing is «a corrupt download
     * never replaces a working database», and that is decided HERE.
     */
    private function install(string $mmdb, string $edition, JobContext $context): JobResult
    {
        $dir = $this->directory();

        // Write the candidate beside its target — same filesystem, so the
        // rename below is atomic — and prove it readable BEFORE it counts.
        $candidate = $dir . '/.incoming-' . bin2hex(random_bytes(6)) . '.mmdb.part';
        if (@file_put_contents($candidate, $mmdb) !== strlen($mmdb)) {
            @unlink($candidate);

            return JobResult::failed('Zwischendatei nicht schreibbar');
        }

        try {
            $built = (new MmdbReader($candidate))->metadata()['build_epoch'] ?? null;
        } catch (\Throwable $e) {
            @unlink($candidate);

            return JobResult::failed('geholte Datei ist keine lesbare MMDB: ' . $e->getMessage());
        }

        $target = $dir . '/' . $edition . '.mmdb';

        // ⚠️ BEFORE the rename, not after. CountryLookup memoises its reader,
        // and that reader holds an OPEN HANDLE on exactly this file — Windows
        // refuses to rename over an open file, so a process that had already
        // answered one lookup could never install an update. Dropping the memo
        // here serves both ends: it releases the handle, and the next lookup
        // re-resolves the directory and therefore finds the new file.
        CountryLookup::forget();

        if (!@rename($candidate, $target)) {
            @unlink($candidate);

            return JobResult::failed("Ersetzen fehlgeschlagen: {$target}");
        }

        // Licence obligation: the previous version is DESTROYED, not archived.
        // Any other *.mmdb would also be a second candidate for CountryLookup,
        // which takes the alphabetically first — an old file left behind could
        // outrank the new one.
        $dropped = $this->removeOtherDatabases($dir, $target);
        foreach ($dropped as $old) {
            $context->log('alte Datenbank entfernt: ' . basename($old));
        }

        return JobResult::done(sprintf(
            '%s erneuert (%s), %d alte Datei(en) entfernt',
            $edition,
            is_int($built) && $built > 0 ? 'Stand ' . date('d.m.Y', $built) : 'Stand unbekannt',
            count($dropped)
        ));
    }

    /** @return string|null the archive bytes, or null with $error set */
    private function download(string $edition, string $key, ?string &$error): ?string
    {
        $url = self::ENDPOINT . '?' . http_build_query([
            'edition_id'  => $edition,
            'license_key' => $key,
            'suffix'      => 'tar.gz',
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_USERAGENT      => 'z77-geoip-update',
        ]);
        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch); // curl_close() is a deprecated no-op since PHP 8

        if ($raw === false) {
            // ⚠️ The URL carries the licence key. It must never reach a log
            // line, so the message names the edition and nothing else.
            $error = "Abruf fehlgeschlagen ({$edition}): {$err}";

            return null;
        }
        if ($http === 401 || $http === 403) {
            $error = "Lizenzschluessel abgelehnt (HTTP {$http}) — vorhandene Datenbank bleibt in Gebrauch";

            return null;
        }
        if ($http >= 400) {
            $error = "MaxMind antwortete HTTP {$http}";

            return null;
        }
        if (strlen($raw) > self::MAX_DOWNLOAD_BYTES) {
            $error = 'Antwort unplausibel gross — abgebrochen';

            return null;
        }

        return $raw;
    }

    /**
     * Pulls the single `.mmdb` out of MaxMind's `tar.gz`.
     *
     * Hand-walked rather than via `PharData`: a tar header is 512 bytes of
     * fixed fields, phar can be disabled on shared hosting, and extracting to
     * a directory would spill the archive's other files (the licence texts)
     * into the database folder, where {@see CountryLookup} globs for `*.mmdb`.
     *
     * @return string|null the database bytes, or null with $error set
     */
    private function extractDatabase(string $archive, ?string &$error): ?string
    {
        $tar = @gzdecode($archive);
        if ($tar === false || $tar === '') {
            $error = 'Antwort ist kein gzip-Archiv (Schluessel falsch, oder eine Fehlerseite)';

            return null;
        }

        $offset = 0;
        $length = strlen($tar);

        while ($offset + 512 <= $length) {
            $header = substr($tar, $offset, 512);
            $offset += 512;

            $name = rtrim(substr($header, 0, 100), "\0");
            if ($name === '') {
                break; // two zero blocks end the archive
            }

            // Size is octal, space/NUL padded. A malformed field means we can
            // no longer trust our position in the stream — stop rather than
            // walk into the middle of a file.
            $size = (int) octdec(trim(substr($header, 124, 12), " \0"));
            if ($size < 0 || $offset + $size > $length) {
                $error = 'Archiv ist unvollstaendig';

                return null;
            }

            if (str_ends_with($name, '.mmdb')) {
                return substr($tar, $offset, $size);
            }

            $offset += (int) (ceil($size / 512) * 512); // files are block padded
        }

        $error = 'keine .mmdb im Archiv gefunden';

        return null;
    }

    /**
     * Removes every other `*.mmdb` in the directory.
     *
     * @return list<string> the files that were removed
     */
    private function removeOtherDatabases(string $dir, string $keep): array
    {
        $keep    = realpath($keep) ?: $keep;
        $dropped = [];

        foreach (glob($dir . '/*.mmdb') ?: [] as $file) {
            if ((realpath($file) ?: $file) === $keep) {
                continue;
            }
            if (@unlink($file)) {
                $dropped[] = $file;
            }
        }

        return $dropped;
    }

    /**
     * How old the installed database is, in days — measured from MaxMind's own
     * build stamp, not the file's mtime: copying a file forward would
     * otherwise make a year-old database look fresh, which is precisely the
     * state the licence forbids.
     *
     * Null means "no readable database at all", which is always due.
     */
    private function localAgeDays(): ?int
    {
        $built = CountryLookup::databaseBuiltAt();
        if ($built === null) {
            return null;
        }

        return (int) floor((time() - $built) / 86400);
    }

    /**
     * The key from `config/geoip.inc.php`, or MaxMind's `GeoIP.conf` in the
     * database directory as the fallback — so whoever downloads their config
     * from MaxMind can drop it in unchanged.
     */
    private function licenceKey(array $config): string
    {
        $key = trim((string) ($config['licenseKey'] ?? ''));
        if ($key !== '') {
            return $key;
        }

        $conf = $this->directory() . '/GeoIP.conf';
        if (!is_file($conf)) {
            return '';
        }

        $raw = @file_get_contents($conf);
        if ($raw === false) {
            return '';
        }

        // `LicenseKey xxxx`, one directive per line, comments start with '#'.
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^LicenseKey\s+(\S+)/i', $line, $m)) {
                return $m[1];
            }
        }

        return '';
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        // Client tier first, legacy flat fallback (ADR-036 config split).
        $file = \Z77\Shared\Libraries\ConfigLocator::path('geoip.inc.php');
        if ($file === null) {
            return [];
        }

        $config = require $file;

        return is_array($config) ? $config : [];
    }

    private function directory(): string
    {
        return rtrim(str_replace('\\', '/', ABS_BASE_PATH), '/') . '/' . CountryLookup::DIR;
    }
}
