<?php
/**
 * PDF partial: a table that flows down the page — the shared building block
 * for any list a document prints (invoice lines, a data sheet's properties,
 * a report). Fixed column widths in mm, a header row repeated after a page
 * break, ONE column wraps (`wrap`, default the first — the text column), the
 * others are one line.
 * Leaves the cursor BELOW the last row (`$pdf->setY()`), the convention of
 * PDF partials. Draws through `$pdf` only — echoes nothing.
 *
 * Context:
 *   - `columns`   list<array{label: string, width: float, align?: 'L'|'R'|'C'}> — widths in mm
 *   - `rows`      list<array{cells: list<string>, bold?: bool, indent?: float, muted?: bool, rule?: bool}>
 *                 `indent` shifts the first cell (a sub-line); `rule` draws a line ABOVE the row (a totals row)
 *   - `x`, `y`    where the table starts (default: the cursor)
 *   - `fontSize`  points (default 9); `lineHeight` mm (default 4.5)
 *   - `wrap`      int|null, the index of the column that wraps (default 0); `indent` applies to it.
 *                 null = no column wraps. A one-line cell that is too long ends in «…» (`fit()`).
 *   - `header`    bool, draw the header row (default true)
 *   - `headerFill` bool, a light grey band behind the header (default true)
 *
 * @var \Z77\Shared\Pdf\PdfDocument $pdf
 * @var list<array<string, mixed>> $columns
 * @var list<array<string, mixed>> $rows
 */
$x          = $x ?? $pdf->x();
$y          = $y ?? $pdf->y();
$fontSize   = $fontSize ?? 9;
$lineHeight = $lineHeight ?? 4.5;
$header     = $header ?? true;
$headerFill = $headerFill ?? true;
$pad        = 1.5;
$width      = array_sum(array_map(static fn(array $c) => (float) $c['width'], $columns));

$drawHeader = static function (float $atY) use ($pdf, $columns, $x, $fontSize, $lineHeight, $headerFill, $pad, $width): float {
    $pdf->font('B', $fontSize);
    if ($headerFill) {
        $pdf->fillColor(235, 235, 235)->rect($x, $atY, $width, $lineHeight + 1, true, false);
    }
    $cx = $x;
    foreach ($columns as $column) {
        $pdf->text($cx + $pad, $atY + 0.5, (string) $column['label'], $column['align'] ?? 'L', (float) $column['width'] - 2 * $pad);
        $cx += (float) $column['width'];
    }
    $pdf->drawColor(120, 120, 120)->lineWidth(0.2)->line($x, $atY + $lineHeight + 1, $x + $width, $atY + $lineHeight + 1);

    return $atY + $lineHeight + 1.5;
};

if ($header) {
    $y = $drawHeader($y);
}

$wrap      = array_key_exists('wrap', get_defined_vars()) && $wrap === null ? null : (int) ($wrap ?? 0);
$wrapWidth = $wrap === null ? 0.0 : (float) $columns[$wrap]['width'];
foreach ($rows as $row) {
    $cells  = array_values($row['cells']);
    $indent = (float) ($row['indent'] ?? 0);
    $pdf->font(!empty($row['bold']) ? 'B' : '', $fontSize);
    $lines  = $wrap === null ? 1 : $pdf->lineCount((string) ($cells[$wrap] ?? ''), $wrapWidth - 2 * $pad - $indent);
    $height = $lines * $lineHeight + 1;

    // A row never splits; a row that does not fit moves to a new page, the header with it.
    if ($y + $height > $pdf->breakAt()) {
        $pdf->addPage();
        $y = $header ? $drawHeader($pdf->y()) : $pdf->y();
        $pdf->font(!empty($row['bold']) ? 'B' : '', $fontSize);
    }
    if (!empty($row['rule'])) {
        $pdf->drawColor(120, 120, 120)->lineWidth(0.2)->line($x, $y, $x + $width, $y);
        $y += 0.8;
    }
    $pdf->textColor(!empty($row['muted']) ? 110 : 0);

    $cx = $x;
    foreach ($columns as $i => $column) {
        $w    = (float) $column['width'];
        $text = (string) ($cells[$i] ?? '');
        if ($i === $wrap) {
            $pdf->paragraph($cx + $pad + $indent, $y + 0.5, $w - 2 * $pad - $indent, $text, $lineHeight);
        } elseif ($text !== '') {
            $shift = $wrap === null && $i === 0 ? $indent : 0.0;
            $pdf->text($cx + $pad + $shift, $y + 0.5, $pdf->fit($text, $w - 2 * $pad - $shift), $column['align'] ?? 'L', $w - 2 * $pad - $shift);
        }
        $cx += $w;
    }
    $y += $height;
}
$pdf->textColor(0)->font('', $fontSize)->setY($y);
