<?php

namespace App\Http\Requests\Management\PaymentGateways;

use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

/**
 * The connect/edit payload for one payment provider.
 *
 * Field rules come from {@see PaymentGatewayRegistry} rather than being written
 * here, so the same definition drives the rendered form, this validation and
 * the encryption key list. A provider needing three fields instead of the usual
 * two works because nothing here assumes a pair.
 *
 * ## The blank-secret case
 *
 * A stored secret is never sent back to the browser, so editing a provider to
 * change its public key submits an empty secret. Treating that as "clear the
 * key" would break every live connection — so for a secret that is *already
 * stored* the field becomes optional and an empty value means "keep it".
 *
 * The obvious shortcut is to drop the pattern too, which is what the older
 * update request does. That is worth not copying: it means a genuinely mistyped
 * replacement key passes validation and only fails later, at the provider, with
 * no useful error. Here the empty case is normalised to null (which `nullable`
 * short-circuits) and any non-empty value is still checked against the format.
 */
final class ConnectGatewayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Blank a secret only where one is already stored, and only for secrets, so
     * a required public key is unaffected.
     */
    protected function prepareForValidation(): void
    {
        $config = $this->input('config');

        if (! is_array($config)) {
            return;
        }

        foreach ($this->storedSecretKeys() as $key) {
            if (($config[$key] ?? null) === '') {
                $config[$key] = null;
            }
        }

        $this->merge(['config' => $config]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $provider = (string) $this->route('provider');

        if (! PaymentGatewayRegistry::has($provider)) {
            return [];
        }

        $rules = [
            'is_enabled' => ['required', 'boolean'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'config' => ['required', 'array'],
        ];

        $alreadyStored = $this->storedSecretKeys();

        foreach (PaymentGatewayRegistry::rulesFor($provider) as $field => $fieldRules) {
            $rules["config.{$field}"] = in_array($field, $alreadyStored, true)
                ? $this->relax($fieldRules)
                : $fieldRules;
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $provider = (string) $this->route('provider');

        $messages = ['config.required' => 'Fill in the details this provider needs.'];

        foreach (PaymentGatewayRegistry::messagesFor($provider) as $rule => $message) {
            $messages["config.{$rule}"] = $message;
        }

        return $messages;
    }

    /**
     * Swap `required` for `nullable`, keeping every other rule — notably the
     * format pattern, which must still catch a mistyped replacement key.
     *
     * @param  array<int, mixed>  $rules
     * @return array<int, mixed>
     */
    private function relax(array $rules): array
    {
        return array_map(
            fn (mixed $rule): mixed => $rule === 'required' ? 'nullable' : $rule,
            $rules,
        );
    }

    /**
     * Secret fields that already have a value for the scope being edited.
     *
     * @return array<int, string>
     */
    private function storedSecretKeys(): array
    {
        $provider = (string) $this->route('provider');
        $user = $this->user();

        if ($user === null || ! PaymentGatewayRegistry::has($provider)) {
            return [];
        }

        $storeId = $this->input('store_id');
        $table = $storeId ? 'store_payment_method' : 'business_payment_method';

        $exists = DB::table($table)
            ->where('payment_method_id', DB::table('payment_methods')->where('code', $provider)->value('id'))
            ->when(
                $storeId,
                fn ($q) => $q->where('store_id', (int) $storeId),
                fn ($q) => $q->where('business_id', (int) $user->business_id),
            )
            ->whereNotNull('config')
            ->exists();

        return $exists ? PaymentGatewayRegistry::secretKeysFor($provider) : [];
    }
}
