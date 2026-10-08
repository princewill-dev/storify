<?php

use App\Support\Payments\CredentialCipher;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Encrypts the gateway secrets already in the table.
 *
 * Before this, every provider secret sat in cleartext in
 * `business_payment_method.config`. With one gateway that is a known and
 * bounded risk; with eight — several of which can move money on their own — it
 * is the moment to close it.
 *
 * Only the fields a provider marks `secret` are encrypted, so the JSON shape
 * and the column type are unchanged, `SensitiveKeys` still matches by name, and
 * a row the old code wrote still reads.
 *
 * Deliberately idempotent: the cipher detects its own prefix, so running this
 * twice — or running it after some rows were written encrypted — is a no-op
 * rather than a double encryption that would only fail at the provider.
 *
 * **This is one-way in practice.** Once encrypted, the values are unreadable
 * without `APP_KEY`; `down()` decrypts, but decrypting is only possible while
 * that key is still the one used to encrypt.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->walk(function (array $config, string $code): array {
            $secrets = PaymentGatewayRegistry::secretKeysFor($code);

            return CredentialCipher::needsEncryption($config, $secrets)
                ? CredentialCipher::encrypt($config, $secrets)
                : $config;
        });
    }

    public function down(): void
    {
        $this->walk(fn (array $config, string $code): array => CredentialCipher::decrypt(
            $config,
            PaymentGatewayRegistry::secretKeysFor($code),
        ));
    }

    /**
     * @param  callable(array<string, mixed>, string): array<string, mixed>  $transform
     */
    private function walk(callable $transform): void
    {
        DB::table('business_payment_method')
            ->join('payment_methods', 'payment_methods.id', '=', 'business_payment_method.payment_method_id')
            ->whereNotNull('business_payment_method.config')
            ->select('business_payment_method.id', 'business_payment_method.config', 'payment_methods.code')
            ->orderBy('business_payment_method.id')
            ->chunk(200, function ($rows) use ($transform) {
                foreach ($rows as $row) {
                    $config = json_decode((string) $row->config, true);

                    if (! is_array($config) || $config === []) {
                        continue;
                    }

                    $updated = $transform($config, (string) $row->code);

                    if ($updated !== $config) {
                        DB::table('business_payment_method')
                            ->where('id', $row->id)
                            ->update(['config' => json_encode($updated)]);
                    }
                }
            });
    }
};
