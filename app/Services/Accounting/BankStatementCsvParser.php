<?php

namespace App\Services\Accounting;

use Illuminate\Support\Carbon;

/**
 * WS-37 — lenient CSV/TXT bank statement parser.
 *
 * Extracted verbatim from ReconciliationController so the module's most
 * delicate logic has one home. Accepts comma/₦/space-grouped amounts, signed
 * or parenthesised values, a single amount column or separate debit/credit
 * columns, and named or positional headers. Dates are day-first
 * (`31/12/2025`, `03/12/2025` both read as December) because that is how the
 * statements this module ingests are written; legacy parsed them US-style and
 * mis-dated them.
 *
 * The kobo maths below stays private to the parser on purpose: bank
 * statements arrive with currency symbols, thousands grouping and
 * parentheses, and a third decimal place is rounded — none of the Naira
 * converters accept that input, so routing it through one would change what
 * a row parses to.
 */
final class BankStatementCsvParser
{
    /**
     * Parse a CSV/TXT statement leniently.
     *
     * @return array<int, array{date: string, description: ?string, reference: ?string, amount_kobo: int}>
     */
    public function parse(string $path): array
    {
        $handle = fopen($path, 'r');

        if (! $handle) {
            return [];
        }

        $headers = null;
        $positional = false;
        $rows = [];

        // PHP 8.4 wants the escape argument spelled out; "\\" keeps the
        // pre-8.4 CSV behaviour.
        while (($data = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
            if (count(array_filter($data, fn ($value) => $value !== null && trim((string) $value) !== '')) === 0) {
                continue;
            }

            if ($headers === null) {
                // Excel and several bank exports lead with a UTF-8 BOM, which
                // would otherwise turn "date" into "\u{FEFF}date".
                $data = array_map(fn ($value) => is_string($value) ? ltrim($value, "\xEF\xBB\xBF") : $value, $data);

                $normalised = array_map(fn ($value) => strtolower(trim((string) $value)), $data);

                // A first row that names any known column is a header row.
                if (array_intersect($normalised, self::ALL_ALIASES)) {
                    $headers = $normalised;

                    continue;
                }

                $headers = ['date', 'description', 'reference', 'amount'];
                $positional = true;
            }

            $columns = $this->mapColumns($headers, $data, $positional);

            $date = $this->parseDate($columns['date'] ?? null);
            $amount = $this->resolveAmount(
                $columns['amount'] ?? null,
                $columns['debit'] ?? null,
                $columns['credit'] ?? null,
            );

            if ($date === null || $amount === null) {
                continue;
            }

            $rows[] = [
                'date' => $date,
                'description' => $this->clean($columns['description'] ?? null),
                // `reference` is varchar(100); clamp it there so one long bank
                // narration cannot abort the whole import on insert.
                'reference' => $this->clean($columns['reference'] ?? null, 100),
                'amount_kobo' => $amount,
            ];
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Map one raw row onto logical columns, by header name when available
     * and positionally otherwise (legacy order: date, description, reference,
     * amount).
     *
     * @param  array<int, string>  $headers
     * @param  array<int, ?string>  $data
     * @return array<string, ?string>
     */
    private function mapColumns(array $headers, array $data, bool $positional = false): array
    {
        $columns = [];

        foreach ($headers as $index => $header) {
            $value = $data[$index] ?? null;

            foreach (self::COLUMN_ALIASES as $logical => $aliases) {
                if (in_array($header, $aliases, true) && ! array_key_exists($logical, $columns)) {
                    $columns[$logical] = $value;
                }
            }
        }

        if ($positional) {
            $columns = [
                'date' => $data[0] ?? null,
                'description' => $data[1] ?? null,
                'reference' => count($data) >= 4 ? $data[2] : null,
                // Headerless files of three columns put the amount last;
                // four-plus column files keep it in the legacy third slot.
                'amount' => count($data) >= 4
                    ? ($data[3] ?? $data[count($data) - 1] ?? null)
                    : ($data[count($data) - 1] ?? null),
            ];
        }

        if (empty($columns)) {
            $columns = [
                'date' => $data[0] ?? null,
                'description' => $data[1] ?? null,
                'reference' => $data[2] ?? null,
                'amount' => $data[count($data) - 1] ?? null,
            ];
        }

        return $columns;
    }

    /**
     * Resolve a signed kobo amount from an amount column, or from separate
     * debit/credit columns (legacy only understood the single column).
     */
    private function resolveAmount(?string $amount, ?string $debit, ?string $credit): ?int
    {
        if ($amount !== null && trim($amount) !== '') {
            return $this->parseKobo($amount);
        }

        $debitKobo = $debit !== null && trim($debit) !== '' ? $this->parseKobo($debit) : null;
        $creditKobo = $credit !== null && trim($credit) !== '' ? $this->parseKobo($credit) : null;

        if ($debitKobo === null && $creditKobo === null) {
            return null;
        }

        // The bank credits the business (money in, positive in the signed
        // amount_kobo convention); it debits it on the way out.
        return (int) abs($creditKobo ?? 0) - (int) abs($debitKobo ?? 0);
    }

    /**
     * Convert "₦1,234.56", "(250.00)" or "-12.5" to signed integer kobo.
     *
     * String maths only — money never passes through a float here.
     */
    private function parseKobo(?string $raw): ?int
    {
        if ($raw === null) {
            return null;
        }

        $value = trim($raw);

        if ($value === '') {
            return null;
        }

        $negative = false;

        if (preg_match('/^\((.*)\)$/', $value, $matches) === 1) {
            $negative = true;
            $value = $matches[1];
        }

        if (str_starts_with($value, '-') || str_starts_with($value, '−')) {
            $negative = true;
            $value = mb_substr($value, 1);
        }

        // The second whitespace entry is a non-breaking space — Excel and
        // several bank exports group thousands with it.
        $value = str_replace(['₦', 'NGN', 'ngn', ',', ' ', "\u{A0}"], '', $value);

        if (preg_match('/^\d+(\.\d+)?$/', $value) !== 1) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = str_pad($fraction, 3, '0');

        $kobo = ((int) $whole) * 100 + (int) substr($fraction, 0, 2);

        if ((int) $fraction[2] >= 5) {
            $kobo++;
        }

        return $negative ? -$kobo : $kobo;
    }

    /**
     * Parse a statement date. Day-first for ambiguous d/m/Y input.
     *
     * A value that fits a known shape but not the calendar (31/02/2026) is
     * refused rather than rolled over into March — a silent month shift is
     * how a reconciliation stops balancing for no visible reason.
     */
    private function parseDate(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $value = trim($raw);

        foreach (['Y-m-d', 'Y/m/d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'm/d/Y', 'm-d-Y'] as $format) {
            if (! Carbon::hasFormat($value, $format)) {
                continue;
            }

            try {
                $parsed = Carbon::createFromFormat($format, $value);
            } catch (\Throwable) {
                return null;
            }

            return $parsed && $parsed->format($format) === $value ? $parsed->toDateString() : null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function clean(?string $value, int $max = 255): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /**
     * Header aliases understood by the parser, in logical-column order.
     *
     * @var array<string, array<int, string>>
     */
    private const COLUMN_ALIASES = [
        'date' => ['date', 'transaction date', 'trans date', 'value date', 'entry date', 'posting date'],
        'description' => ['description', 'narration', 'details', 'memo', 'particulars'],
        'reference' => ['reference', 'ref', 'reference no', 'ref no', 'reference number', 'cheque no', 'cheque number'],
        'amount' => ['amount', 'value', 'amount (ngn)', 'amount(ngn)', 'transaction amount'],
        'debit' => ['debit', 'debit amount', 'dr', 'money out', 'withdrawal'],
        'credit' => ['credit', 'credit amount', 'cr', 'money in', 'deposit', 'lodgement'],
    ];

    /**
     * Every alias above, flattened, for header-row detection.
     *
     * @var array<int, string>
     */
    private const ALL_ALIASES = [
        'date', 'transaction date', 'trans date', 'value date', 'entry date', 'posting date',
        'description', 'narration', 'details', 'memo', 'particulars',
        'reference', 'ref', 'reference no', 'ref no', 'reference number', 'cheque no', 'cheque number',
        'amount', 'value', 'amount (ngn)', 'amount(ngn)', 'transaction amount',
        'debit', 'debit amount', 'dr', 'money out', 'withdrawal',
        'credit', 'credit amount', 'cr', 'money in', 'deposit', 'lodgement',
    ];
}
