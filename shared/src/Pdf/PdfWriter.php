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

    public function __construct(private readonly PdfDocument $document, string $orientation, string $unit, string|array $size)
    {
        parent::__construct($orientation, $unit, $size);
    }

    /** Called by the writer after each AddPage(). */
    public function Header(): void
    {
        if ($this->onPageStart !== null) {
            ($this->onPageStart)($this->document);
        }
    }

    /** Called by the writer before a page is left and at Close(). */
    public function Footer(): void
    {
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
}
