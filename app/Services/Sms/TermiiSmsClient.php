<?php

namespace App\Services\Sms;

use App\Support\Sms\PhoneNumber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The platform's Termii account, for transactional SMS only.
 *
 * It never throws and never logs the message body: an alert is best-effort,
 * and an OTP body is the code itself. Callers decide what a failure means —
 * the KYC form tells the user to retry, the alert job records a failed row.
 */
final class TermiiSmsClient
{
    public function send(string $phone, string $message): SmsSendResult
    {
        $config = (array) config('services.termii', []);

        $apiKey = trim((string) ($config['api_key'] ?? ''));
        $baseUrl = rtrim(trim((string) ($config['base_url'] ?? '')), '/');
        $senderId = trim((string) ($config['sender_id'] ?? ''));

        if ($apiKey === '' || $baseUrl === '' || $senderId === '') {
            Log::warning('sms.termii.not_configured');

            return SmsSendResult::failed('not_configured');
        }

        $to = PhoneNumber::normalize(null, $phone);

        if ($to === null) {
            Log::warning('sms.termii.invalid_phone', ['phone' => PhoneNumber::mask((string) $phone)]);

            return SmsSendResult::failed('invalid_phone');
        }

        $timeout = max(1, (int) ($config['timeout_ms'] ?? 10000)) / 1000;

        try {
            $response = Http::baseUrl($baseUrl)
                ->timeout($timeout)
                ->acceptJson()
                ->asJson()
                ->post('/api/sms/send', [
                    'to' => PhoneNumber::digits($to),
                    'from' => $senderId,
                    'sms' => $message,
                    'type' => 'plain',
                    'channel' => (string) ($config['channel'] ?? 'dnd'),
                    'api_key' => $apiKey,
                ]);
        } catch (ConnectionException $e) {
            Log::warning('sms.termii.failed', [
                'phone' => PhoneNumber::mask($to),
                'error' => 'connection',
            ]);

            return SmsSendResult::failed('connection');
        } catch (\Throwable $e) {
            Log::warning('sms.termii.failed', [
                'phone' => PhoneNumber::mask($to),
                'error' => Str::limit($e->getMessage(), 120),
            ]);

            return SmsSendResult::failed(Str::limit($e->getMessage(), 120));
        }

        $payload = $response->json();
        $messageId = is_array($payload) ? ($payload['message_id'] ?? null) : null;

        if ($response->successful() && is_string($messageId) && $messageId !== '') {
            Log::info('sms.termii.sent', [
                'phone' => PhoneNumber::mask($to),
                'message_id' => $messageId,
            ]);

            return SmsSendResult::sent($messageId);
        }

        $error = is_array($payload)
            ? (string) ($payload['message'] ?? 'provider_error')
            : 'provider_error';

        Log::warning('sms.termii.failed', [
            'phone' => PhoneNumber::mask($to),
            'status' => $response->status(),
            'error' => Str::limit($error, 120),
        ]);

        return SmsSendResult::failed(Str::limit($error, 120));
    }
}
