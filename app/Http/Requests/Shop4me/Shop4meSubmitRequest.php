<?php

namespace App\Http\Requests\Shop4me;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class Shop4meSubmitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'store_id' => ['nullable','integer'],
            'currency_id' => ['nullable','integer'],
            'budget_amount' => ['required','numeric','min:0'],
            'notes' => ['nullable','string'],
            'items' => ['required','array','min:1'],
            'items.*.product_id' => ['nullable','integer'],
            'items.*.product_variant_id' => ['nullable','integer'],
            'items.*.name' => ['nullable','string'],
            'items.*.qty' => ['nullable','numeric','min:0.01'],
            'items.*.unit_hint' => ['nullable','string'],
            'items.*.amount_hint' => ['required','numeric','min:0'],
            'items.*.notes' => ['nullable','string'],
            'items.*.allow_substitute' => ['nullable','boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function(Validator $v){
            $items = $this->input('items', []);
            if (!is_array($items) || count($items) === 0) return;
            $sum = 0.0; $hasAmount = false;
            foreach ($items as $i => $it) {
                $name = trim((string)($it['name'] ?? ''));
                $pid = $it['product_id'] ?? null;
                $pvid = $it['product_variant_id'] ?? null;
                if (!$name && !$pid && !$pvid) {
                    $v->errors()->add("items.$i.name", 'Each item must have a name or be linked to a product.');
                }
                $amt = (float)($it['amount_hint'] ?? 0);
                if ($amt < 0) {
                    $v->errors()->add("items.$i.amount_hint", 'Amount must be >= 0.');
                }
                $sum += max(0, $amt);
                if ($amt > 0) $hasAmount = true;
            }
            if (!$hasAmount) {
                $v->errors()->add('items', 'At least one item must have a positive amount.');
            }
            $budget = (float)($this->input('budget_amount', 0));
            if (number_format($sum, 2, '.', '') !== number_format($budget, 2, '.', '')) {
                $v->errors()->add('budget_amount', 'Budget total mismatch. Please do not modify the computed total.');
            }
        });
    }
}
