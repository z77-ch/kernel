<?php
/**
 * PDF partial: the recipient's address in the window of a C5 envelope
 * (Swiss Post, left window: the block starts 20 mm from the left edge and
 * ~50 mm from the top, and stays within 100 × 45 mm). Any letter a module
 * prints puts its address block here — the invoice first. Leaves the cursor
 * below the block. Draws through `$pdf` only — echoes nothing.
 *
 * Context:
 *   - `lines`       list<string>  the printed lines (empty ones are skipped)
 *   - `x`, `y`      top-left of the block (default 20 / 50 — the left window)
 *   - `fontSize`    points (default 10); `lineHeight` mm (default 4.6)
 *
 * @var \Z77\Shared\Pdf\PdfDocument $pdf
 * @var list<string> $lines
 */
$x          = $x ?? 20.0;
$y          = $y ?? 50.0;
$fontSize   = $fontSize ?? 10;
$lineHeight = $lineHeight ?? 4.6;

$pdf->font('', $fontSize)->textColor(0);
$pdf->setY($pdf->lines($x, $y, $lines, $lineHeight));
