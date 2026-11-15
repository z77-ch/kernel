<?php
namespace Z77\Core\Kibot;

use Z77\Core\Libraries\FileFinder,
    Z77\Core\Services\HelpAddition,
    Z77\Core\Services\HelpService,
    Z77\Core\Services\TemplateRenderer,
    Z77\KiBot\Topics
;

/**
 * The knowledge of the KI-Assistent (ADR-055): every help template of the installation
 * (`*.help.tpl.php`, ADR-048) as a KiBot topic set — generated, never maintained by hand.
 *
 *   <outDir>/topics.json          {"version": 1, "signature": …, "themen": [{id, titel, beschreibung, quellen}]}
 *   <outDir>/<topic-id>.txt       the help as plain text (headings kept as «## …», list items as «- …»)
 *
 * Discovery walks the template roots of every FileFinder namespace in lookup order, so a
 * project's override under `override/` replaces the package's template of the same path
 * (Rule 1) and a project's own help template joins. The topic id is
 * {@see HelpService::topicIdFor()} — the same id the help block of a screen carries as
 * `data-help-topic`, so the assistant knows which help the user is looking at.
 *
 * Rendering: a help template expects the variables its controller passes (`$form`,
 * `$ledger`, …). It is rendered with an EMPTY context and every notice turned into an
 * exception; a template that needs its context falls back to its static text (the HTML
 * outside the PHP blocks — every variant of a conditional stays in, which suits a
 * knowledge base). A template that does not even parse, or yields no text, is skipped and
 * logged. No business data is involved: nothing from a request, a session or a record.
 *
 * Freshness: `signature` hashes the discovered template files; the index is rebuilt LAZILY
 * when that set changes or a template is newer than the index (a deploy with changed help
 * texts updates the knowledge on the first question). A lock keeps two requests from
 * building at once; the loser keeps answering from the existing index.
 *
 * Additions (owner 2026-11-15, «Hilfe ergänzen»): with a {@see HelpAddition} store, the
 * installation's own text to a help is appended to that topic's text under «## Ergänzung»,
 * and its opening goes into the catalog line (`Ergänzung: …`) — the router sees what the
 * admin added. The signature hashes each addition's CONTENT (not its mtime: a save within the
 * second the index was built would be missed), so a save, a change or a removal makes the
 * index stale and the next question rebuilds it.
 */
final class HelpKnowledge
{
    public const SUFFIX = '.help.tpl.php';
    public const INDEX  = 'topics.json';

    private const LOCK         = '.build.lock';
    private const DESC_MAX     = 600;
    private const INTRO_MAX    = 240;
    private const ADDITION_MAX = 200;

    /** @var null|\Closure(string): void */
    private ?\Closure $log;

    /**
     * @param array<string, list<string>> $roots  namespace => template roots in lookup order (override first)
     * @param string                      $outDir directory of the generated topic set (outside public/)
     * @param null|callable(string): void $log    receives one line per skipped or statically rendered template
     * @param null|HelpAddition           $additions the installation's additions to the helps; null = none
     */
    public function __construct(
        private array $roots,
        private string $outDir,
        ?callable $log = null,
        private ?HelpAddition $additions = null,
    ) {
        $this->outDir = rtrim(str_replace('\\', '/', $outDir), '/');
        $this->log    = $log === null ? null : \Closure::fromCallable($log);
    }

    /** The installation's template roots, from the FileFinder's namespace table (override first). */
    public static function fromFileFinder(FileFinder $finder, string $outDir, ?callable $log = null, ?HelpAddition $additions = null): self
    {
        $tplDir = $finder->getTplDir();
        $roots  = [];
        foreach ($finder->getAllNamespaces() as $ns => $resources) {
            foreach ($resources['sourcePaths'] ?? [] as $path) {
                $roots[$ns][] = rtrim(str_replace('\\', '/', $path), '/') . '/' . $tplDir;
            }
        }
        return new self($roots, $outDir, $log, $additions);
    }

    /**
     * The help templates of the installation, by topic id — what a help addition may be written
     * for (the backend's `HelpController` validates the id against it). Discovery only: nothing
     * is rendered or written.
     *
     * @return array<string, array{id: string, nameSpace: string, path: string, file: string, mtime: int, addition: string}>
     */
    public static function templates(FileFinder $finder): array
    {
        return self::fromFileFinder($finder, '')->discover();
    }

    /**
     * The title a help has in the index, from its STATIC text (tokenizer — nothing is
     * executed): for a window that names the help without rendering it.
     *
     * @param array{file: string, path: string} $template one entry of {@see discover()}
     */
    public static function staticTitle(array $template): string
    {
        return self::title(self::staticHtml($template['file']), $template['path']);
    }

    public function outDir(): string
    {
        return $this->outDir;
    }

    /**
     * Every help template, one per topic id; per namespace the first root that has a path wins.
     *
     * `addition` = the file of the help's addition, '' when it has none.
     *
     * @return array<string, array{id: string, nameSpace: string, path: string, file: string, mtime: int, addition: string}>
     */
    public function discover(): array
    {
        $found = [];
        foreach ($this->roots as $ns => $dirs) {
            $seen = [];
            foreach ($dirs as $dir) {
                $dir = rtrim(str_replace('\\', '/', $dir), '/');
                if (!is_dir($dir)) {
                    continue;
                }
                $files = [];
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $entry) {
                    if ($entry->isFile() && str_ends_with($entry->getFilename(), self::SUFFIX)) {
                        $files[] = str_replace('\\', '/', $entry->getPathname());
                    }
                }
                sort($files);
                foreach ($files as $file) {
                    $relative = substr($file, strlen($dir) + 1);
                    if (isset($seen[$relative])) {
                        continue; // an override earlier in the lookup order wins (Rule 1)
                    }
                    $seen[$relative] = true;
                    $path = substr($relative, 0, -strlen('.tpl.php'));
                    $id   = HelpService::topicIdFor($path, $ns);
                    if (isset($found[$id])) {
                        $this->log("help index: {$file} skipped — topic id {$id} already taken by {$found[$id]['file']}");
                        continue;
                    }
                    $addition   = $this->additions !== null && HelpAddition::isValidId($id) ? $this->additions->file($id) : '';
                    $found[$id] = ['id' => $id, 'nameSpace' => $ns, 'path' => $path, 'file' => $file, 'mtime' => (int) filemtime($file),
                        'addition' => $addition !== '' && is_file($addition) ? $addition : ''];
                }
            }
        }
        ksort($found);
        return $found;
    }

    /** Whether the index is missing, built from another set of templates or additions, or older than a template. */
    public function isStale(?array $found = null): bool
    {
        $found ??= $this->discover();
        $index   = $this->outDir . '/' . self::INDEX;
        if (!is_file($index)) {
            return true;
        }
        $data = json_decode((string) file_get_contents($index), true);
        if (!is_array($data) || ($data['signature'] ?? null) !== self::signature($found)) {
            return true;
        }
        $built = (int) filemtime($index);
        foreach ($found as $template) {
            if ($template['mtime'] > $built) {
                return true;
            }
        }
        return false;
    }

    /** Rebuilds when stale; true when it did. Cheap when fresh: one directory walk and one stat per template. */
    public function ensureFresh(): bool
    {
        $found = $this->discover();
        return $this->isStale($found) && $this->build($found) !== null;
    }

    /**
     * Writes the topic set. Returns the counts, or null when another request is building.
     *
     * @return null|array{topics: int, static: list<string>, skipped: list<string>}
     * @throws \RuntimeException when the output directory cannot be created
     */
    public function build(?array $found = null): ?array
    {
        if (!is_dir($this->outDir) && !@mkdir($this->outDir, 0775, true) && !is_dir($this->outDir)) {
            throw new \RuntimeException('help index: cannot create ' . $this->outDir);
        }
        $lock = fopen($this->outDir . '/' . self::LOCK, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return null;
        }

        try {
            $found ??= $this->discover();
            $topics  = [];
            $report  = ['topics' => 0, 'static' => [], 'skipped' => []];
            foreach ($found as $id => $template) {
                $html = $this->render($template, $report);
                if ($html === null) {
                    continue;
                }
                $text     = self::toText($html);
                $addition = $this->addition($template);
                if ($text === '' && $addition === '') {
                    $report['skipped'][] = $template['file'];
                    $this->log("help index: {$template['file']} skipped — no text");
                    continue;
                }
                if ($addition !== '') {
                    // The installation's own text, below the help as in the help window.
                    $text = trim($text . "\n\n## Ergänzung\n" . $addition);
                }
                $title = self::title($html, $template['path']);
                self::write($this->outDir . '/' . $id . '.txt',
                    'Hilfetext: ' . $title . "\nBildschirm: " . self::screen($template['path']) . "\n\n" . $text . "\n");
                $topics[] = [
                    'id'           => $id,
                    'titel'        => $title,
                    'beschreibung' => self::describe($html, $template['path'], $title, $addition),
                    'quellen'      => [$id . '.txt'],
                ];
            }

            // Texts of topics that are gone (template deleted or skipped now).
            $keep = array_flip(array_map(static fn (array $t): string => $t['id'] . '.txt', $topics));
            foreach (glob($this->outDir . '/*.txt') ?: [] as $file) {
                if (!isset($keep[basename($file)])) {
                    @unlink($file);
                }
            }

            self::write($this->outDir . '/' . self::INDEX, (string) json_encode([
                'version'   => 1,
                'generated' => date('c'),
                'signature' => self::signature($found),
                'themen'    => $topics,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

            $report['topics'] = count($topics);
            return $report;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * The template's HTML: rendered with an empty context, or — when it needs its controller's
     * variables — its static text. null = skipped (does not parse).
     */
    private function render(array $template, array &$report): ?string
    {
        $level = ob_get_level();
        set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            return (new TemplateRenderer($template['nameSpace']))->render($template['file'], []);
        } catch (\ParseError $e) {
            $report['skipped'][] = $template['file'];
            $this->log("help index: {$template['file']} skipped — does not parse: {$e->getMessage()}");
            return null;
        } catch (\Throwable $e) {
            $report['static'][] = $template['file'];
            $this->log("help index: {$template['file']} needs its context ({$e->getMessage()}) — static text used");
            return self::staticHtml($template['file']);
        } finally {
            restore_error_handler();
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    /** The HTML outside the PHP blocks — read through the tokenizer, nothing is executed. */
    public static function staticHtml(string $file): string
    {
        $html = '';
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token) && $token[0] === T_INLINE_HTML) {
                $html .= $token[1];
            }
        }
        return $html;
    }

    /** Help HTML → knowledge text: headings as «## …», list items as «- …», the rest plain. */
    public static function toText(string $html): string
    {
        $html = preg_replace('#<h[1-6]\b[^>]*>(.*?)</h[1-6]>#is', "\n\n## $1\n", $html) ?? $html;
        $html = preg_replace('#<li\b[^>]*>#i', "\n- ", $html) ?? $html;
        return Topics::htmlToText($html);
    }

    /**
     * The first non-empty heading of the GENERAL part (before the first field section),
     * else a name from the path: `Backend/InvoiceJournalController/list.help` → `Invoice Journal – list`.
     */
    private static function title(string $html, string $path): string
    {
        $general = preg_split('#<section\b#i', $html, 2)[0] ?? $html;
        // A heading whose text depends on controller variables names its static title for the index (`data-help-title`).
        if (preg_match('#<h[1-3]\b[^>]*\sdata-help-title="([^"]+)"#i', $general, $named) === 1) {
            return self::plain($named[1]);
        }
        preg_match_all('#<h[1-3]\b[^>]*>(.*?)</h[1-3]>#is', $general, $all);
        foreach ($all[1] as $heading) {
            $title = self::plain($heading);
            if ($title !== '') {
                return $title;
            }
        }
        $parts      = explode('/', self::screen($path));
        $action     = (string) array_pop($parts);
        $controller = preg_replace('/Controller$/', '', (string) array_pop($parts)) ?? '';
        $controller = trim(preg_replace('/(?<!^)[A-Z]/', ' $0', $controller) ?? $controller);
        return $controller === '' ? $action : $controller . ' – ' . $action;
    }

    /** The screen a help belongs to: its path without `.help`. */
    private static function screen(string $path): string
    {
        return preg_replace('/\.help$/', '', $path) ?? $path;
    }

    /**
     * What the routing model sees: the opening sentences, the section headings, the opening of
     * the installation's addition, the screen.
     *
     * `Abschnitte` leaves out only the heading that IS the title (the catalog line already
     * starts with it) — not blindly the first heading: without a general heading the title
     * comes from the path and the first field heading belongs in the list. The screen tag is
     * reserved first; a long line shortens its description part, never the screen.
     */
    private static function describe(string $html, string $path, string $title, string $addition = ''): string
    {
        $intro = '';
        if (preg_match('#<p\b[^>]*>(.*?)</p>#is', $html, $m) === 1) {
            $intro = self::lead(self::plain($m[1]), self::INTRO_MAX);
        }
        preg_match_all('#<h[1-6]\b[^>]*>(.*?)</h[1-6]>#is', $html, $all);
        $headings = array_values(array_unique(array_filter(
            array_map([self::class, 'plain'], $all[1]),
            static fn (string $heading): bool => $heading !== '' && $heading !== $title
        )));
        $text     = trim($intro . ($headings !== [] ? ' Abschnitte: ' . implode(', ', $headings) . '.' : ''));
        if ($addition !== '') {
            $flat = trim(preg_replace('/\s+/u', ' ', $addition) ?? $addition);
            $text = trim($text . ' Ergänzung: ' . self::lead($flat, self::ADDITION_MAX));
        }
        $screen = '(Bildschirm ' . self::screen($path) . ')';
        $room   = self::DESC_MAX - mb_strlen($screen) - 1;   // 1 = the space before the screen tag
        if (mb_strlen($text) > $room) {
            $text = rtrim(mb_substr($text, 0, max(0, $room - 1))) . '…';
        }
        return trim($text . ' ' . $screen);
    }

    /** At most `$max` characters, cut back to the last `. ` / `; ` after char 40, else ` …`. */
    private static function lead(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut  = mb_substr($text, 0, $max);
        $stop = max((int) mb_strrpos($cut, '. '), (int) mb_strrpos($cut, '; '));
        return $stop > 40 ? mb_substr($cut, 0, $stop + 1) : $cut . ' …';
    }

    /** The addition of a discovered help (normalized), '' without. */
    private function addition(array $template): string
    {
        return $this->additions === null || ($template['addition'] ?? '') === '' ? '' : $this->additions->load($template['id']);
    }

    private static function plain(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * The template set plus each addition's content hash — a saved, changed or removed
     * addition changes it (content, not mtime: one-second mtimes would miss a quick save).
     *
     * @param array<string, array{id: string, file: string, addition?: string}> $found
     */
    private static function signature(array $found): string
    {
        return md5(implode("\n", array_map(static function (array $t): string {
            $addition = ($t['addition'] ?? '') !== '' ? '+' . (string) @md5_file($t['addition']) : '';
            return $t['id'] . '=' . $t['file'] . $addition;
        }, $found)));
    }

    /** Write-then-rename: a reader never sees half a file. */
    private static function write(string $file, string $content): void
    {
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $content) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException('help index: cannot write ' . $file);
        }
    }

    private function log(string $line): void
    {
        if ($this->log !== null) {
            ($this->log)($line);
        }
    }
}
