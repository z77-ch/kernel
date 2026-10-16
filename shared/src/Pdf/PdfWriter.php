<?php

namespace Z77\Shared\Pdf;

/**
 * The vendored writer, bent to the facade: the running header and footer
 * become hooks the LAYOUT sets ({@see PdfDocument::onPageStart()} /
 * {@see PdfDocument::onPageEnd()}) instead of a subclass per document, and
 * three readings the facade needs are exposed (the break margin, the font
 * size in mm, the line count of a wrapped text).
 *
 * @internal package use only — consumers draw through {@see PdfDocument}.
 */
final class PdfWriter extends \FPDF
{
    /** @var callable|null fn(PdfDocument): void */
    public $onPageStart = null;

    /** @var callable|null fn(PdfDocument): void */
    public $onPageEnd = null;

    /** @var list<int> section index → its first page number; section 0 starts at page 1 */
    private array $sectionStarts = [1];

    /** The open page already got its footer ({@see startSection()}) — the next `AddPage()` must not draw another. */
    private bool $pageEnded = false;

    public function __construct(private readonly PdfDocument $document, string $orientation, string $unit, string|array $size)
    {
        parent::__construct($orientation, $unit, $size);
    }

    /** Called by the writer after each AddPage(). */
    public function Header(): void
    {
        $this->pageEnded = false;
        if ($this->onPageStart !== null) {
            ($this->onPageStart)($this->document);
        }
    }

    /** Called by the writer before a page is left and at Close(). */
    public function Footer(): void
    {
        if ($this->pageEnded) {
            return;   // finished by startSection() with the previous section's hook
        }
        if ($this->onPageEnd !== null) {
            ($this->onPageEnd)($this->document);
        }
    }

    /** The usable bottom edge: the automatic break trigger, or the page height when the break is off. */
    public function breakAt(): float
    {
        return $this->AutoPageBreak ? $this->PageBreakTrigger : $this->h;
    }

    /** The current font size in user units (mm). */
    public function fontSizeMm(): float
    {
        return $this->FontSize;
    }

    /**
     * How many lines MultiCell() would use for $text at $width — the
     * writer's own wrapping rule (the `NbLines` of the FPDF scripts),
     * so a box can be sized before it is drawn.
     */
    public function countLines(float $width, string $text): int
    {
        if ($width <= 0) {
            $width = $this->w - $this->rMargin - $this->x;
        }
        $wmax  = ($width - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s     = str_replace("\r", '', $text);
        $nb    = strlen($s);
        if ($nb > 0 && $s[$nb - 1] === "\n") {
            $nb--;
        }
        $sep = -1;
        $i   = 0;
        $j   = 0;
        $l   = 0;
        $nl  = 1;
        $cw  = $this->CurrentFont['cw'];
        while ($i < $nb) {
            $c = $s[$i];
            if ($c === "\n") {
                $i++;
                $sep = -1;
                $j   = $i;
                $l   = 0;
                $nl++;
                continue;
            }
            if ($c === ' ') {
                $sep = $i;
            }
            $l += $cw[$c];
            if ($l > $wmax) {
                if ($sep === -1) {
                    if ($i === $j) {
                        $i++;
                    }
                } else {
                    $i = $sep + 1;
                }
                $sep = -1;
                $j   = $i;
                $l   = 0;
                $nl++;
            } else {
                $i++;
            }
        }

        return $nl;
    }

    /**
     * $text (already encoded) with its baseline starting at ($x, $y), turned
     * $degrees counter-clockwise about ($cx, $cy) — the rotation as the
     * writer's scripts do it: the current transformation matrix is changed
     * by a `cm` inside a saved graphics state (`q … Q`), so nothing drawn
     * afterwards is turned. Text colour applies as in `Text()`.
     */
    public function rotatedText(float $x, float $y, string $text, float $degrees, float $cx, float $cy): void
    {
        $rad = deg2rad($degrees);
        $c   = cos($rad);
        $s   = sin($rad);
        $px  = $cx * $this->k;
        $py  = ($this->h - $cy) * $this->k;
        $this->_out(sprintf('q %.5F %.5F %.5F %.5F %.2F %.2F cm 1 0 0 1 %.2F %.2F cm', $c, $s, -$s, $c, $px, $py, -$px, -$py));
        $this->Text($x, $y, $text);
        $this->_out('Q');
    }

    /**
     * Ends the current section: the open page gets its footer NOW, from the hook
     * it was drawn with, and is flagged as finished — the next section sets its
     * own hooks BEFORE its `addPage()`, and the writer's `AddPage()` would
     * otherwise draw the new section's footer on the old page. Both hooks are
     * cleared; {@see Header()} lifts the flag on the new page.
     * The next page starts the new section. Before the first page there is
     * nothing to end — section 0 simply starts at page 1.
     */
    public function startSection(): void
    {
        if ($this->page === 0) {
            return;
        }
        if ($this->onPageEnd !== null) {
            $this->InFooter = true;
            ($this->onPageEnd)($this->document);
            $this->InFooter = false;
        }
        $this->pageEnded       = true;
        $this->onPageStart     = null;
        $this->onPageEnd       = null;
        $this->sectionStarts[] = $this->page + 1;
    }

    /** The index of the current section (0 for a single document). */
    public function sectionIndex(): int
    {
        return count($this->sectionStarts) - 1;
    }

    /** The current page's number within its section. */
    public function sectionPageNo(): int
    {
        return $this->page - $this->sectionStarts[$this->sectionIndex()] + 1;
    }

    /** The placeholder replaced by the page count of the CURRENT section at output (`{nb}` counts the whole file). */
    public function sectionAlias(): string
    {
        return '{nb:' . $this->sectionIndex() . '}';
    }

    /** Replaces each section's alias with that section's page count, then lets the writer put the pages. */
    protected function _putpages()
    {
        $total = $this->page;
        foreach ($this->sectionStarts as $index => $first) {
            $last  = isset($this->sectionStarts[$index + 1]) ? $this->sectionStarts[$index + 1] - 1 : $total;
            $alias = '{nb:' . $index . '}';
            for ($n = $first; $n <= $last; $n++) {
                $this->pages[$n] = str_replace($alias, (string) ($last - $first + 1), $this->pages[$n]);
            }
        }
        parent::_putpages();
    }
}
