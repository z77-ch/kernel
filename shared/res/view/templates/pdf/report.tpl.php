<?php
/**
 * PDF layout: a REPORT — a title, the range it covers, and one or more tables. The shared
 * layout for every module's list-shaped document (financial statements, a trial balance, an
 * account statement, an export of a list); the module only builds the blocks (FIN-PDF-001,
 * owner 2026-10-10: «sauberer und sofort downloadbar ohne Browsereinfluss»).
 *
 * Every page: a running head (the issuer left, the title and the range right, a rule under
 * it) and a foot (printed at left, «Seite x von n» right). Then the blocks, each a
 * `pdf/table` — a block's title in bold above it, a block that would start in the last
 * 25 mm moves to the next page. Columns are the SAME widths in every block of a report when
 * the builder passes the same `columns`, so amounts stand in one line from top to bottom.
 *
 * Context:
 *   - `title`      string — «Bilanz»
 *   - `subtitle`   string — «Geschäftsjahr 2026 · per 31.12.2026»
 *   - `issuer`     string — the company name in the running head ('' = none)
 *   - `printedAt`  string — «10.10.2026 12:27»
 *   - `blocks`     list<array{title?: string, columns: list<array>, rows: list<array>, gapAfter?: float, header?: bool, wrap?: int}>
 *                  `columns` / `rows` exactly as `pdf/table` takes them
 *   - `notice`     string — an error line in red above the first block ('' = none), e.g.
 *                  «Aktiven ≠ Passiven — Differenz 12.00»
 *   - `marginLeft` / `marginRight`  mm (default 12 / 10)
 *   - `marginLeft` / `marginRight`  mm (default 12 / 10)
 *   - `orientation` is the creator's (`PdfDocument::create(…, 'L')` for a wide journal)
 *
 * Draws through `$pdf` only — echoes nothing.
 *
 * @var \Z77\Shared\Pdf\PdfDocument $pdf
 * @var string $title
 */
$subtitle  = (string) ($subtitle ?? '');
$issuer    = (string) ($issuer ?? '');
$printedAt = (string) ($printedAt ?? '');
$blocks    = $blocks ?? [];
$notice    = (string) ($notice ?? '');

// Margins (owner 2026-10-10): left 12 mm, right 10 mm — a caller may set others.
$left   = (float) ($marginLeft ?? 12.0);
$right  = $pdf->pageWidth() - (float) ($marginRight ?? 10.0);
$width  = $right - $left;
$footY  = $pdf->pageHeight() - 12.0;

$pdf->onPageStart(static function ($pdf) use ($left, $width, $right, $title, $subtitle, $issuer): void {
    $pdf->font('', 8)->textColor(110)->text($left, 10, $issuer)->textColor(0);
    $pdf->font('B', 12)->text($left, 9, $title, 'R', $width);
    if ($subtitle !== '') {
        $pdf->font('', 8.5)->textColor(80)->text($left, 14.5, $subtitle, 'R', $width)->textColor(0);
    }
    $pdf->drawColor(0)->lineWidth(0.3)->line($left, 20, $right, 20);
    $pdf->setY(26);
});
$pdf->onPageEnd(static function ($pdf) use ($left, $width, $right, $footY, $printedAt): void {
    $pdf->drawColor(170)->lineWidth(0.2)->line($left, $footY - 2, $right, $footY - 2);
    $pdf->font('', 7.5)->textColor(110);
    if ($printedAt !== '') {
        $pdf->text($left, $footY, 'Gedruckt ' . $printedAt);
    }
    $pdf->text($left, $footY, 'Seite ' . $pdf->pageNo() . ' von {nb}', 'R', $width)->textColor(0);
});

$pdf->autoPageBreak(20)->addPage();

if ($notice !== '') {
    $after = $pdf->font('B', 9)->textColor(179, 38, 30)->paragraph($left, $pdf->y(), $width, $notice, 4.5);
    $pdf->textColor(0)->setY($after + 3);
}

foreach ($blocks as $block) {
    $pdf->ensureSpace(25);
    if (($block['title'] ?? '') !== '') {
        $pdf->font('B', 10)->text($left, $pdf->y(), (string) $block['title']);
        $pdf->setY($pdf->y() + 6);
    }
    $pdf->partial('pdf/table', [
        'columns' => $block['columns'],
        'rows'    => $block['rows'],
        'header'  => $block['header'] ?? true,
        'wrap'    => array_key_exists('wrap', $block) ? $block['wrap'] : 0,   // null = no wrap (`??` would turn it into 0)
        'x'       => $left,
        'y'       => $pdf->y(),
    ], 'Z77\\Shared');
    $pdf->setY($pdf->y() + (float) ($block['gapAfter'] ?? 6));
}
