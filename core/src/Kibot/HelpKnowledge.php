<?php
namespace Z77\Core\Kibot;

use Z77\Core\Libraries\FileFinder,
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
 */
final class HelpKnowledge
{
    public const SUFFIX = '.help.tpl.php';
    public const INDEX  = 'topics.json';

    private const LOCK         = '.build.lock';
    private const DESC_MAX     = 600;
    private const INTRO_MAX    = 240;

    /** @var null|\Closure(string): void */
    private ?\Closure $log;

    /**
     * @param array<string, list<string>> $roots  namespace => template roots in lookup order (override first)
     * @param string                      $outDir directory of the generated topic set (outside public/)
     * @param null|callable(string): void $log    receives one line per skipped or statically rendered template
     */
    public function __construct(
        private array $roots,
        private string $outDir,
        ?callable $log = null,
    ) {
        $this->outDir = rtrim(str_replace('\\', '/', $outDir), '/');
        $this->log    = $log === null ? null : \Closure::fromCallable($log);
    }

    /** The installation's template roots, from the FileFinder's namespace table (override first). */
    public static function fromFileFinder(FileFinder $finder, string $outDir, ?callable $log = null): self
    {
        $tplDir = $finder->getTplDir();
        $roots  = [];
        foreach ($finder->getAllNamespaces() as $ns => $resources) {
            foreach ($resources['sourcePaths'] ?? [] as $path) {
                $roots[$ns][] = rtrim(str_replace('\\', '/', $path), '/') . '/' . $tplDir;
            }
        }
        return new self($roots, $outDir, $log);
    }

    public function outDir(): string
    {
        return $this->outDir;
    }

    /**
     * Every help template, one per topic id; per namespace the first root that has a path wins.
     *
     * @return array<string, array{id: string, nameSpace: string, path: string, file: string, mtime: int}>
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
                    $found[$id] = ['id' => $id, 'nameSpace' => $ns, 'path' => $path, 'file' => $file, 'mtime' => (int) filemtime($file)];
                }
            }
        }
        ksort($found);
        return $found;
    }

    /** Whether the index is missing, built from another set of templates, or older than one of them. */
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
                $text = self::toText($html);
                if ($text === '') {
                    $report['skipped'][] = $template['file'];
                    $this->log("help index: {$template['file']} skipped — no text");
                    continue;
                }
                $title = self::title($html, $template['path']);
                self::write($this->outDir . '/' . $id . '.txt',
                    'Hilfetext: ' . $title . "\nBildschirm: " . self::screen($template['path']) . "\n\n" . $text . "\n");
                $topics[] = [
                    'id'           => $id,
                    'titel'        => $title,
                    'beschreibung' => self::describe($html, $template['path']),
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

    /** What the routing model sees: the opening sentences, the section headings, the screen. */
    private static function describe(string $html, string $path): string
    {
        $intro = '';
        if (preg_match('#<p\b[^>]*>(.*?)</p>#is', $html, $m) === 1) {
            $intro = self::plain($m[1]);
            if (mb_strlen($intro) > self::INTRO_MAX) {
                $cut   = mb_substr($intro, 0, self::INTRO_MAX);
                $stop  = max((int) mb_strrpos($cut, '. '), (int) mb_strrpos($cut, '; '));
                $intro = $stop > 40 ? mb_substr($cut, 0, $stop + 1) : $cut . ' …';
            }
        }
        preg_match_all('#<h[1-6]\b[^>]*>(.*?)</h[1-6]>#is', $html, $all);
        $headings = array_values(array_unique(array_filter(array_map([self::class, 'plain'], array_slice($all[1], 1)))));
        $text     = trim($intro . ($headings !== [] ? ' Abschnitte: ' . implode(', ', $headings) . '.' : ''));
        $text    .= ' (Bildschirm ' . self::screen($path) . ')';
        return mb_strlen($text) > self::DESC_MAX ? mb_substr($text, 0, self::DESC_MAX - 1) . '…' : trim($text);
    }

    private static function plain(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /** @param array<string, array{file: string}> $found */
    private static function signature(array $found): string
    {
        return md5(implode("\n", array_map(static fn (array $t): string => $t['id'] . '=' . $t['file'], $found)));
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
