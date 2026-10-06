<?php

namespace App\Http\Resources\Management\Invoice;

use App\Models\Invoice;
use App\Support\Money\Naira;
use Illuminate\Http\Request;

/**
 * WS-21 — the DomPDF print artefact's view data.
 *
 * The currency symbol is resolved here (not in Blade, per the house view
 * rule); the discount amount arrives pre-computed in kobo from
 * App\Services\Management\InvoiceService and converts once to the float the
 * legacy view rendered.
 */
final class InvoiceDocumentResource
{
    public function __construct(
        private readonly Invoice $invoice,
        private readonly int $discountKobo,
    ) {}

    /**
     * @return array{invoice: Invoice, discountAmount: float, symbol: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'invoice' => $this->invoice,
            'discountAmount' => Naira::floatFromKobo($this->discountKobo),
            'symbol' => $this->currencySymbol($this->invoice->business?->currency ?: 'NGN'),
        ];
    }

    private function currencySymbol(string $currency): string
    {
        return [
            'NGN' => '₦',
            'USD' => '$',
            'GBP' => '£',
            'EUR' => '€',
            'GHS' => 'GH₵',
            'KES' => 'KSh',
            'ZAR' => 'R',
        ][strtoupper($currency)] ?? strtoupper($currency).' ';
    }
}
