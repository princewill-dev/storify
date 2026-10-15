<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Enums\ConsentType;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Storefront\ConsentUpdateRequest;
use App\Models\Customer;
use App\Services\ConsentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer's own communication preferences —
 * `storefront/account/consents`.
 *
 * Only the optional types are offered as switches. Terms acceptance is shown
 * read-only, as a fact with a date, because it is a record rather than a
 * setting; the storefront has no business un-ticking it.
 *
 * The description strings come from the ConsentType enum rather than the
 * client, so what the customer is agreeing to is written in the same place the
 * agreement is recorded — a client cannot paraphrase it.
 */
class ConsentController extends ApiController
{
    public function __construct(
        private readonly ConsentService $consents,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        return $this->ok([
            'consents' => $this->catalogue($customer),
        ]);
    }

    public function update(ConsentUpdateRequest $request): JsonResponse
    {
        $customer = $this->customer($request);
        $consents = $request->validated('consents');

        foreach ($consents as $value => $granted) {
            $this->consents->record(
                $customer,
                ConsentType::from((string) $value),
                (bool) $granted,
                'account',
                $request->ip(),
            );
        }

        return $this->ok([
            'consents' => $this->catalogue($customer),
        ], 'Preferences updated.');
    }

    /**
     * Every type with its current answer, and whether the customer may change
     * it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function catalogue(Customer $customer): array
    {
        $payload = [];

        foreach (ConsentType::cases() as $type) {
            $payload[] = [
                'type' => $type->value,
                'label' => $type->label(),
                'description' => $type->description(),
                'granted' => $this->consents->state($customer, $type),
                'editable' => $type->isOptional(),
            ];
        }

        return $payload;
    }

    private function customer(Request $request): Customer
    {
        /** @var Customer $customer */
        $customer = $request->user();

        return $customer;
    }
}
