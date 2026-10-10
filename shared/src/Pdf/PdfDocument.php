<?php

namespace Z77\Shared\Pdf;

use Z77\Core\Services\TemplateRenderer;
use Z77\Shared\Qr\QrCode;

/**
 * PDF generation — the framework facade over the vendored FPDF writer (see
 * kernel vendored/README.md). Consumers use THIS class, never `\FPDF`
 * directly: the facade is the seam that would survive swapping the writer,
 * and it is the one place that knows the writer's units, encoding and
 * page-break mechanics.
 *
 * The idea (owner 2026-10-06: «ein zentrales PDF-Tool … schöne Layouts über
 * Partials»): the WRITER is here, the LAYOUTS are templates. A document is
 * drawn by PDF PARTIALS — ordinary `.tpl.php` files under
 * `res/view/templates/pdf/` of a module, resolved by the FileFinder like any
 * partial (so a project overrides a layout under `override/`, CE-first) —
 * that receive `$pdf` (this facade) plus their context and call its drawing
 * API. A PDF partial ECHOES NOTHING: output is the page, not a string;
 * stray output is refused ({@see partial()}). A partial that draws a flow
 * block (a table, an address, a paragraph) leaves the cursor BELOW what it
 * drew (`setY()`), so the next block continues from `y()` — the convention
 * the kernel's `pdf/table` and `pdf/addressWindow` follow.
 *
 * Units are MILLIMETRES, origin top-left, A4 portrait by default. Text is
 * UTF-8 at the API and converted ONCE here to the core fonts' cp1252
 * ({@see enc()}): what cp1252 lacks is transliterated (`Ș` → `S`), the
 * typographic dashes and quotes mapped — a layout never sees the encoding.
 * The one font family is Helvetica (the 14 core fonts need no embedding; the
 * QR-bill guidelines ask for Helvetica / Arial / Liberation Sans). An image
 * is PNG / JPEG / GIF by path.
 *
 * Page breaks: {@see autoPageBreak()} sets the bottom margin at which the
 * writer breaks inside a flowed text block; {@see ensureSpace()} breaks
 * BEFORE a block that must not split (a totals block, the payment part).
 * {@see onPageStart()} / {@see onPageEnd()} hook every page (running header
 * and footer); the key-value bag ({@see set()} / {@see get()}) lets a layout
 * tell its hooks what a page carries («no footer on the page with the
 * payment part»). `{nb}` in a text is replaced by the page count at output.
 */
final class PdfDocument
{
    public const FONT = 'Helvetica';

    private PdfWriter $writer;

    private ?TemplateRenderer $renderer = null;

    /** @var array<string, mixed> the layout's bag, read by its hooks */
    private array $bag = [];

    /** The automatic break margin in mm, 0 = off ({@see autoPageBreak()}). */
    private float $breakMargin = 0;

    /** @param string|array{0: float, 1: float} $size */
    private function __construct(string $orientation, string|array $size)
    {
        $this->writer = new PdfWriter($this, $orientation, 'mm', $size);
        $this->writer->SetMargins(0, 0, 0);
        $this->writer->SetAutoPageBreak(false);
        $this->writer->AliasNbPages();
        $this->writer->SetFont(self::FONT, '', 10);
        $this->writer->SetCreator('z77');
    }

    /**
     * A new, empty document — call {@see addPage()} before drawing. $size
     * is a writer size name (`A4`, `A5`, `Letter`) or [width, height] in mm.
     *
     * @param string|array{0: float, 1: float} $size
     */
    public static function create(string $title, string $author = '', string $orientation = 'P', string|array $size = 'A4'): self
    {
        $doc = new self($orientation, $size);
        $doc->writer->SetTitle($title, true);
        if ($author !== '') {
            $doc->writer->SetAuthor($author, true);
        }

        return $doc;
    }

    // ── pages ───────────────────────────────────────────────────────────

    public function addPage(): self
    {
        $this->writer->AddPage();

        return $this;
    }

    public function pageNo(): int { return $this->writer->PageNo(); }
    public function pageWidth(): float { return $this->writer->GetPageWidth(); }
    public function pageHeight(): float { return $this->writer->GetPageHeight(); }

    /**
     * The bottom margin at which a flowed block ({@see paragraph()}, the
     * table partial) breaks to a new page; 0 switches the automatic break
     * off (a fixed layout that places everything itself).
     */
    public function autoPageBreak(float $bottomMargin): self
    {
        $this->breakMargin = max(0, $bottomMargin);
        $this->writer->SetAutoPageBreak($this->breakMargin > 0, $this->breakMargin);

        return $this;
    }

    /** Where the automatic break happens: the usable bottom edge in mm (page height when the break is off). */
    public function breakAt(): float
    {
        return $this->writer->breakAt();
    }

    /** Starts a new page unless $heightMm still fits above the break margin — for a block that must not split. */
    public function ensureSpace(float $heightMm): self
    {
        if ($this->writer->GetY() + $heightMm > $this->breakAt()) {
            $this->addPage();
        }

        return $this;
    }

    /** Runs on every new page AFTER it was added (the running header); receives this document. */
    public function onPageStart(callable $hook): self
    {
        $this->writer->onPageStart = $hook;

        return $this;
    }

    /** Runs on every page when it is left or at output (the running footer); receives this document. */
    public function onPageEnd(callable $hook): self
    {
        $this->writer->onPageEnd = $hook;

        return $this;
    }

    // ── the layout's bag ────────────────────────────────────────────────

    /** A value the layout leaves for its hooks («this page carries no footer»). */
    public function set(string $key, mixed $value): self
    {
        $this->bag[$key] = $value;

        return $this;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->bag[$key] ?? $default;
    }

    // ── cursor ──────────────────────────────────────────────────────────

    public function x(): float { return $this->writer->GetX(); }
    public function y(): float { return $this->writer->GetY(); }

    public function setXY(float $x, float $y): self
    {
        $this->writer->SetXY($x, $y);

        return $this;
    }

    public function setY(float $y): self
    {
        $this->writer->SetY($y, false);

        return $this;
    }

    // ── style ───────────────────────────────────────────────────────────

    /** Helvetica in $style '' | 'B' | 'I' | 'BI' at $size points. */
    public function font(string $style = '', float $size = 10): self
    {
        $this->writer->SetFont(self::FONT, $style, $size);

        return $this;
    }

    public function textColor(int $r, int $g = -1, int $b = -1): self
    {
        $this->writer->SetTextColor($r, $g < 0 ? null : $g, $b < 0 ? null : $b);

        return $this;
    }

    public function drawColor(int $r, int $g = -1, int $b = -1): self
    {
        $this->writer->SetDrawColor($r, $g < 0 ? null : $g, $b < 0 ? null : $b);

        return $this;
    }

    public function fillColor(int $r, int $g = -1, int $b = -1): self
    {
        $this->writer->SetFillColor($r, $g < 0 ? null : $g, $b < 0 ? null : $b);

        return $this;
    }

    public function lineWidth(float $mm): self
    {
        $this->writer->SetLineWidth($mm);

        return $this;
    }

    // ── text ────────────────────────────────────────────────────────────

    /**
     * One line of text with its BASELINE-TOP at $y (the line box starts at
     * $y, like HTML). $align 'L' places it at $x; 'R' / 'C' need $width (the
     * box the text is aligned in). Does not move the cursor.
     */
    public function text(float $x, float $y, string $text, string $align = 'L', float $width = 0): self
    {
        $h = $this->lineHeightOf();
        // A placed line never breaks the page — only a FLOWED block does (paragraph(), the table partial).
        $this->writer->SetAutoPageBreak(false);
        $this->writer->SetXY($x, $y);
        $this->writer->Cell($width > 0 ? $width : $this->writer->GetStringWidth($this->enc($text)), $h, $this->enc($text), 0, 0, $align);
        $this->writer->SetAutoPageBreak($this->breakMargin > 0, $this->breakMargin);

        return $this;
    }

    /**
     * Several lines, one under the other from ($x, $y) at $lineHeight mm;
     * answers the y BELOW the last line. Empty lines are skipped.
     *
     * @param list<string> $lines
     */
    public function lines(float $x, float $y, array $lines, float $lineHeight): float
    {
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $this->text($x, $y, $line);
            $y += $lineHeight;
        }

        return $y;
    }

    /**
     * A wrapped block of width $width from ($x, $y) — line breaks at word
     * boundaries and at "\n", $lineHeight mm per line, the automatic page
     * break applies. Answers the y below the block; the cursor is left there.
     */
    public function paragraph(float $x, float $y, float $width, string $text, float $lineHeight, string $align = 'L'): float
    {
        $this->writer->SetXY($x, $y);
        $this->writer->SetLeftMargin($x);
        $this->writer->MultiCell($width, $lineHeight, $this->enc($text), 0, $align);
        $this->writer->SetLeftMargin(0);
        $this->writer->SetX($x);

        return $this->writer->GetY();
    }

    /**
     * $text cut to $width mm in the current font, ending in «…» when it had to be cut — the
     * one-line cell of a table (owner 2026-10-10: «zu lange Titel werden mit Ellipsis
     * dargestellt»). Unchanged when it fits; cut on characters, not bytes (UTF-8 in).
     */
    public function fit(string $text, float $width): string
    {
        if ($width <= 0 || $this->textWidth($text) <= $width) {
            return $text;
        }
        $chars = mb_str_split($text);
        while ($chars !== [] && $this->textWidth(rtrim(implode('', $chars)) . '…') > $width) {
            array_pop($chars);
        }

        return rtrim(implode('', $chars)) . '…';
    }

    /** The width of $text in the current font, mm. */
    public function textWidth(string $text): float
    {
        return $this->writer->GetStringWidth($this->enc($text));
    }

    /** The lines $text wraps into at $width in the current font — to size a box before drawing it. */
    public function lineCount(string $text, float $width): int
    {
        return $this->writer->countLines($width, $this->enc($text));
    }

    /** The line box of the current font size in mm (1.2 × the size). */
    public function lineHeightOf(): float
    {
        return $this->writer->fontSizeMm() * 1.2;
    }

    // ── shapes and images ───────────────────────────────────────────────

    public function line(float $x1, float $y1, float $x2, float $y2): self
    {
        $this->writer->Line($x1, $y1, $x2, $y2);

        return $this;
    }

    /** A dashed line ($dash mm on, $gap mm off) — the writer has none; drawn as segments. */
    public function dashedLine(float $x1, float $y1, float $x2, float $y2, float $dash = 2, float $gap = 1.5): self
    {
        $length = sqrt(($x2 - $x1) ** 2 + ($y2 - $y1) ** 2);
        if ($length <= 0) {
            return $this;
        }
        $ux = ($x2 - $x1) / $length;
        $uy = ($y2 - $y1) / $length;
        for ($at = 0; $at < $length; $at += $dash + $gap) {
            $end = min($at + $dash, $length);
            $this->writer->Line($x1 + $ux * $at, $y1 + $uy * $at, $x1 + $ux * $end, $y1 + $uy * $end);
        }

        return $this;
    }

    /** A rectangle: outline, or filled with the fill colour ($fill), or both. */
    public function rect(float $x, float $y, float $w, float $h, bool $fill = false, bool $outline = true): self
    {
        $this->writer->Rect($x, $y, $w, $h, $fill ? ($outline ? 'DF' : 'F') : 'D');

        return $this;
    }

    /** An image file (PNG / JPEG / GIF) at ($x, $y); one of $w / $h = 0 keeps the ratio. */
    public function image(string $path, float $x, float $y, float $w = 0, float $h = 0): self
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException("PDF image not found: {$path}");
        }
        $this->writer->Image($path, $x, $y, $w, $h);

        return $this;
    }

    /**
     * A QR code as VECTOR squares, $size mm a side, from the kernel's QR
     * facade — sharp at any size, no raster. $swissCross overlays the 7 mm
     * Swiss cross the QR-bill prescribes (black square, white cross, a
     * white rim) — level M tolerates it, as the guidelines intend.
     */
    public function qrCode(string $payload, float $x, float $y, float $size, string $ecc = 'M', bool $swissCross = false): self
    {
        $matrix = QrCode::matrix($payload, $ecc);
        $n      = count($matrix);
        $module = $size / $n;
        $this->writer->SetFillColor(0, 0, 0);
        foreach ($matrix as $row => $cells) {
            $runStart = null;
            foreach ($cells as $col => $dark) {
                // Runs of dark modules become one rectangle — fewer objects, no hairline gaps.
                if ($dark && $runStart === null) {
                    $runStart = $col;
                }
                if ((!$dark || $col === $n - 1) && $runStart !== null) {
                    $runEnd = $dark ? $col + 1 : $col;
                    $this->writer->Rect($x + $runStart * $module, $y + $row * $module, ($runEnd - $runStart) * $module, $module, 'F');
                    $runStart = null;
                }
            }
        }
        if ($swissCross) {
            $this->swissCross($x + $size / 2, $y + $size / 2);
        }

        return $this;
    }

    /**
     * The Swiss cross of the QR-bill, 7 × 7 mm centred on ($cx, $cy): the
     * flag's proportions (a 32-unit field, the cross 20 units long and 6
     * units wide), black field, white cross, a white rim so the field stands
     * off the modules.
     */
    private function swissCross(float $cx, float $cy): void
    {
        $side = 7.0;
        $rim  = 0.4;
        $unit = $side / 32;
        $this->writer->SetFillColor(255, 255, 255);
        $this->writer->Rect($cx - $side / 2 - $rim, $cy - $side / 2 - $rim, $side + 2 * $rim, $side + 2 * $rim, 'F');
        $this->writer->SetFillColor(0, 0, 0);
        $this->writer->Rect($cx - $side / 2, $cy - $side / 2, $side, $side, 'F');
        $this->writer->SetFillColor(255, 255, 255);
        $this->writer->Rect($cx - 10 * $unit, $cy - 3 * $unit, 20 * $unit, 6 * $unit, 'F');
        $this->writer->Rect($cx - 3 * $unit, $cy - 10 * $unit, 6 * $unit, 20 * $unit, 'F');
    }

    // ── layouts ─────────────────────────────────────────────────────────

    /**
     * Draws a PDF PARTIAL: the template at $path (FileFinder syntax, no
     * extension, e.g. `pdf/invoice`) of namespace $nameSpace, with `$pdf`
     * (this) and $context as its variables. The template draws through the
     * API and echoes nothing; whitespace is tolerated, anything else is a
     * bug and refused — the page is the output.
     *
     * @param array<string, mixed> $context
     * @throws \LogicException the partial produced output
     */
    public function partial(string $path, array $context = [], string $nameSpace = 'Z77\\Shared'): self
    {
        $this->renderer ??= new TemplateRenderer($nameSpace);
        $output = $this->renderer->partial($path, ['pdf' => $this] + $context, $nameSpace);
        if (trim($output) !== '') {
            throw new \LogicException("PDF partial '{$path}' must draw, not print — it produced output: " . mb_substr(trim($output), 0, 80));
        }

        return $this;
    }

    // ── output ──────────────────────────────────────────────────────────

    /** Page streams uncompressed — for a harness that reads the text back; the browser does not care. */
    public function withoutCompression(): self
    {
        $this->writer->SetCompression(false);

        return $this;
    }

    /** The PDF bytes. The page-end hook runs for the last page here. */
    public function output(): string
    {
        return $this->writer->Output('S');
    }

    /** The writer's text encoding — UTF-8 in, cp1252 out, the rest transliterated. */
    public function enc(string $text): string
    {
        $mapped = strtr($text, [
            "\u{2212}" => '-',  // minus sign (AmountFormat)
            "\u{2013}" => '–',  // en dash — cp1252 has it, keep
            "\u{2014}" => '—',
            "\u{2018}" => '‘', "\u{2019}" => '’', "\u{201C}" => '“', "\u{201D}" => '”',
            "\u{00A0}" => ' ',
        ]);
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $mapped);
        if ($converted !== false) {
            return $converted;
        }
        // Something has no cp1252 counterpart (CJK, an emoji): keep every character that
        // converts, put a «?» for each that does not — never an empty string, never a fatal.
        return preg_replace_callback(
            '/[^\x00-\x7F]+/u',
            static function (array $m): string {
                $out = '';
                foreach (mb_str_split($m[0]) as $char) {
                    $one  = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $char);
                    $out .= ($one === false || $one === '') ? '?' : $one;
                }

                return $out;
            },
            $mapped
        ) ?? '?';
    }
}
