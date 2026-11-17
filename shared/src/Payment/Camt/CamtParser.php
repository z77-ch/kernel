<?php

namespace Z77\Shared\Payment\Camt;

use Z77\Shared\Payment\Iban;

/**
 * Reads an ISO 20022 camt account report as Swiss banks deliver it (SIX «Swiss Payment
 * Standards», versions .001.04 and .001.08) into {@see CamtMessage}: camt.053 (statement),
 * camt.054 (debit / credit notification) and camt.052 (account report) share one entry model,
 * so they share one parser (owner decision 12 of the bank account service, 2026-11-15 — two
 * readers of the same standard drift apart, as the .08 party fix of 2026-10-13 showed).
 *
 * The parser knows the SHAPE and nothing else — pure data, nothing resolved, no refusal for
 * business reasons. What it reads:
 *
 *     Document/{BkToCstmrStmt | BkToCstmrDbtCdtNtfctn | BkToCstmrAcctRpt}
 *       GrpHdr/MsgId, GrpHdr/CreDtTm
 *       {Stmt | Ntfctn | Rpt} (one per account report)
 *         Id, ElctrncSeqNb, LglSeqNb, FrToDt/FrDtTm + ToDtTm
 *         Acct/Id/IBAN, Acct/Ccy
 *         Bal (Tp/CdOrPrtry/Cd, Amt @Ccy, CdtDbtInd, Dt/Dt)          — statements and reports
 *         Ntry (one per booking)
 *           Amt @Ccy, CdtDbtInd, RvslInd, Sts/Cd (.08) | Sts (.04), BookgDt/Dt, ValDt/Dt,
 *           AcctSvcrRef, BkTxCd/Domn (Cd, Fmly/Cd, Fmly/SubFmlyCd), AddtlNtryInf,
 *           NtryDtls/Btch/NbOfTxs
 *           NtryDtls/TxDtls (one per transaction)
 *             Refs/AcctSvcrRef | TxId | EndToEndId
 *             Amt @Ccy, AmtDtls/InstdAmt/Amt @Ccy + CcyXchg (SrcCcy, TrgtCcy, XchgRate)
 *             RltdPties/{Dbtr | UltmtDbtr | Cdtr | UltmtCdtr} (Nm, PstlAdr/TwnNm; .08 nests
 *               them under …/Pty — read there when present), DbtrAcct / CdtrAcct (Id/IBAN)
 *             RmtInf/Strd/CdtrRefInf (Tp/CdOrPrtry/Prtry | Cd: QRR | SCOR, Ref), RmtInf/Ustrd
 *
 * The XML is parsed without network access and without external entities (`LIBXML_NONET`, no
 * entity loading). Every value is foreign input: a consumer escapes it on output.
 */
final class CamtParser
{
    private const CONTAINERS = [
        'BkToCstmrStmt'         => [CamtMessage::KIND_STATEMENT, 'Stmt'],
        'BkToCstmrDbtCdtNtfctn' => [CamtMessage::KIND_NOTIFICATION, 'Ntfctn'],
        'BkToCstmrAcctRpt'      => [CamtMessage::KIND_REPORT, 'Rpt'],
    ];

    /** @throws CamtFormatException NOT_XML | NOT_CAMT */
    public static function parse(string $xml): CamtMessage
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $root = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_NOBLANKS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($root === false) {
            throw new CamtFormatException(CamtFormatException::NOT_XML, 'Die Datei ist kein lesbares XML.');
        }
        if ($root->getName() === 'Document') {
            foreach (self::CONTAINERS as $element => [$kind, $reportElement]) {
                $container = $root->{$element};
                if (!isset($container->GrpHdr)) {
                    continue;
                }
                $reports = [];
                foreach ($container->{$reportElement} as $report) {
                    $reports[] = self::report($report);
                }

                return new CamtMessage($kind, trim((string) $container->GrpHdr->MsgId), self::dateTime((string) $container->GrpHdr->CreDtTm), $reports);
            }
        }
        throw new CamtFormatException(CamtFormatException::NOT_CAMT, 'Die Datei ist keine camt-Meldung (Document mit BkToCstmrStmt, BkToCstmrDbtCdtNtfctn oder BkToCstmrAcctRpt und GrpHdr erwartet).');
    }

    private static function report(\SimpleXMLElement $report): CamtReport
    {
        $balances = [];
        foreach ($report->Bal as $balance) {
            $amount     = self::decimal((string) $balance->Amt);
            $balances[] = new CamtBalance(
                strtoupper(trim((string) $balance->Tp?->CdOrPrtry?->Cd)),
                self::date((string) $balance->Dt?->Dt) ?? self::date((string) $balance->Dt?->DtTm),
                strtoupper(trim((string) $balance->CdtDbtInd)) === 'DBIT' && $amount !== '0.00' ? '-' . $amount : $amount,
                strtoupper(trim((string) $balance->Amt['Ccy'])),
            );
        }
        $entries = [];
        foreach ($report->Ntry as $entry) {
            $entries[] = self::entry($entry);
        }

        return new CamtReport(
            trim((string) $report->Id),
            self::sequence((string) $report->ElctrncSeqNb),
            self::sequence((string) $report->LglSeqNb),
            self::dateTime((string) $report->FrToDt?->FrDtTm),
            self::dateTime((string) $report->FrToDt?->ToDtTm),
            Iban::normalize((string) $report->Acct?->Id?->IBAN),
            strtoupper(trim((string) $report->Acct?->Ccy)),
            $balances,
            $entries,
        );
    }

    private static function entry(\SimpleXMLElement $entry): CamtEntry
    {
        $transactions = [];
        if (isset($entry->NtryDtls)) {
            foreach ($entry->NtryDtls->TxDtls as $detail) {
                $transactions[] = self::transaction($detail);
            }
        }
        // `Sts` is a code element in .08 (`Sts/Cd`) and a plain value in .04.
        $status = trim((string) $entry->Sts?->Cd);
        $domain = $entry->BkTxCd?->Domn;
        $batch  = trim((string) $entry->NtryDtls?->Btch?->NbOfTxs);

        return new CamtEntry(
            self::decimal((string) $entry->Amt),
            strtoupper(trim((string) $entry->Amt['Ccy'])),
            strtoupper(trim((string) $entry->CdtDbtInd)) === 'CRDT',
            in_array(strtolower(trim((string) $entry->RvslInd)), ['true', '1'], true),
            strtoupper($status !== '' ? $status : trim((string) $entry->Sts)),
            self::date((string) $entry->BookgDt?->Dt) ?? self::date((string) $entry->BookgDt?->DtTm),
            self::date((string) $entry->ValDt?->Dt) ?? self::date((string) $entry->ValDt?->DtTm),
            trim((string) $entry->AcctSvcrRef),
            implode('/', array_filter(
                [trim((string) $domain?->Cd), trim((string) $domain?->Fmly?->Cd), trim((string) $domain?->Fmly?->SubFmlyCd)],
                static fn(string $part) => $part !== ''
            )),
            trim((string) $entry->AddtlNtryInf),
            ctype_digit($batch) ? (int) $batch : null,
            $transactions,
        );
    }

    private static function transaction(\SimpleXMLElement $detail): CamtTransaction
    {
        [$type, $reference, $remittance] = self::remittance($detail);
        $hasAmount  = isset($detail->Amt);
        $instructed = $detail->AmtDtls?->InstdAmt;
        $hasOrder   = $instructed !== null && isset($instructed->Amt);
        $exchange   = $instructed?->CcyXchg;
        $parties    = $detail->RltdPties;

        return new CamtTransaction(
            trim((string) $detail->Refs?->AcctSvcrRef),
            trim((string) $detail->Refs?->TxId),
            trim((string) $detail->Refs?->EndToEndId),
            $hasAmount ? self::decimal((string) $detail->Amt) : null,
            $hasAmount ? strtoupper(trim((string) $detail->Amt['Ccy'])) : '',
            $hasOrder ? self::decimal((string) $instructed->Amt) : null,
            $hasOrder ? strtoupper(trim((string) $instructed->Amt['Ccy'])) : '',
            trim((string) $exchange?->XchgRate),
            strtoupper(trim((string) $exchange?->SrcCcy)),
            strtoupper(trim((string) $exchange?->TrgtCcy)),
            $type,
            $reference,
            $remittance,
            self::party($parties?->Dbtr, $parties?->DbtrAcct),
            self::party($parties?->UltmtDbtr, null),
            self::party($parties?->Cdtr, $parties?->CdtrAcct),
            self::party($parties?->UltmtCdtr, null),
        );
    }

    /** The party holding `Nm` / `PstlAdr`: .08 nests it (`Dbtr/Pty`), .04 has it directly (`Dbtr`); null when absent. */
    private static function party(?\SimpleXMLElement $element, ?\SimpleXMLElement $account): ?CamtParty
    {
        if ($element === null || $element->getName() === '') {
            return null;
        }
        $party = isset($element->Pty) ? $element->Pty : $element;

        return new CamtParty(
            trim((string) $party->Nm),
            trim((string) $party->PstlAdr?->TwnNm),
            $account === null || $account->getName() === '' ? '' : Iban::normalize((string) $account->Id?->IBAN),
        );
    }

    /** @return array{0: string, 1: string, 2: string} reference type (QRR | SCOR | NON | ''), reference, unstructured message */
    private static function remittance(\SimpleXMLElement $detail): array
    {
        if (!isset($detail->RmtInf)) {
            return ['', '', ''];
        }
        $rmt          = $detail->RmtInf;
        $unstructured = [];
        foreach ($rmt->Ustrd as $u) {
            $unstructured[] = trim((string) $u);
        }
        $type      = '';
        $reference = '';
        foreach ($rmt->Strd as $strd) {
            $info = $strd->CdtrRefInf;
            if (!isset($info)) {
                continue;
            }
            $reference = preg_replace('/\s+/', '', (string) $info->Ref) ?? '';
            $type      = strtoupper(trim((string) ($info->Tp?->CdOrPrtry?->Prtry ?? $info->Tp?->CdOrPrtry?->Cd ?? '')));
            foreach ($strd->AddtlRmtInf as $a) {
                $unstructured[] = trim((string) $a);
            }
            break;
        }
        if ($reference !== '' && $type === '') {
            $type = preg_match('/^\d{27}$/', $reference) ? 'QRR' : (str_starts_with($reference, 'RF') ? 'SCOR' : 'NON');
        }
        if ($reference === '') {
            $type = 'NON';
        }

        return [$type, $reference, implode(' ', array_filter($unstructured, static fn(string $s) => $s !== ''))];
    }

    /** The file's amount as a two-decimal string — string work only, no float (ADR-042): the fraction is cut or padded. */
    private static function decimal(string $raw): string
    {
        $raw = trim($raw);
        if (!preg_match('/^(-?)(\d+)(?:\.(\d*))?$/', $raw, $m)) {
            return '0.00';
        }
        $fraction = substr(str_pad($m[3] ?? '', 2, '0'), 0, 2);
        $value    = ltrim($m[2], '0') . '.' . $fraction;
        if (str_starts_with($value, '.')) {
            $value = '0' . $value;
        }

        return ($m[1] === '-' && $value !== '0.00') ? '-' . $value : $value;
    }

    private static function sequence(string $raw): ?int
    {
        $raw = trim($raw);

        return ctype_digit($raw) && strlen($raw) <= 18 ? (int) $raw : null;
    }

    private static function date(string $raw): ?\DateTimeImmutable
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($raw, 0, 10));

        return $date === false ? null : $date;
    }

    private static function dateTime(string $raw): ?\DateTimeImmutable
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }
}
