# vendored — frozen third-party source

Libraries the framework ships as source snapshots — no Composer dependency,
no CDN, byte-identical to the released upstream version (auditable, and an
update is a deliberate re-copy, never a side effect of an install).

| Library | Version | License | Source of the copy | Consumed via |
|---|---|---|---|---|
| bacon/bacon-qr-code | v3.0.1 | BSD-2-Clause | wdv-622 vendor snapshot, 2026-08-06 | `Z77\Shared\Qr\QrCode` facade |
| dasprid/enum | 1.0.6 | BSD-2-Clause | wdv-622 vendor snapshot, 2026-08-06 | bacon-qr-code dependency |
| setasign/fpdf (FPDF) | 1.9 | MIT (permissive, `LICENSE`) | github.com/Setasign/FPDF tag 1.9.0, 2026-10-06 | `Z77\Shared\Pdf\PdfDocument` facade |

Rules:

- Never edit files under `vendored/` — the facade in `Z77\Shared\…` is the
  place for our behaviour. An edited snapshot is unauditable.
- Keep each library's LICENSE file next to its `src/`.
- Consumers use the Z77 facade, never `BaconQrCode\*` directly — the facade
  is the seam that would survive swapping the encoder.

Why a PDF writer in the framework: documents leave the system as PDF — the
invoice with its QR-bill (module-debtor), later data sheets, reports — and
one writer serves them all through the facade; the layouts are ordinary
templates (`res/view/templates/pdf/…`, overridable per project), not code in
the writer. FPDF: ~1 900 lines, no dependency, the 14 core fonts (cp1252).

Why a QR encoder in the framework at all: the QR standard (ISO/IEC 18004) is
frozen, and there are two server-side consumers — the member module's TOTP
setup (B8) and the Swiss QR-bill (payload + Swiss cross overlay on top of the
same encoder, when invoicing arrives).
