<?php

namespace App\Services;

use App\Enums\ConsentType;
use App\Models\Customer;
use App\Models\CustomerConsent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * The one place a consent decision is read or written.
 *
 * `customer_consents` is append-only: the current answer for a type is its
 * newest row, and nothing here updates or deletes an existing one. Both the
 * storefront (a customer toggling their own preferences) and management (the
 * consents tab, the message audience filter) go through this service, so they
 * cannot disagree about what the trail means.
 *
 * Kept out of the management namespace because the storefront uses it too, and
 * out of either controller because two of them read the same history.
 */
final class ConsentService
{
    /**
     * Write a decision, unless it is the one already standing.
     *
     * A repeated toggle in the same direction appends nothing: the trail is
     * meant to hold decisions, and a page that re-submits its own state on
     * every load would otherwise bury them. `$force` is for the records that
     * exist to mark an event rather than a change — accepting the terms while
     * registering, where the row is the evidence that it was shown at all.
     */
    public function record(
        Customer $customer,
        ConsentType $type,
        bool $granted,
        string $source,
        ?string $ipAddress = null,
        bool $force = false,
    ): ?CustomerConsent {
        if (! $force && $this->state($customer, $type) === $granted) {
            return null;
        }

        return $customer->consents()->create([
            'business_id' => $customer->business_id,
            'type' => $type->value,
            'granted' => $granted,
            'source' => $source,
            'ip_address' => $ipAddress,
        ]);
    }

    /**
     * Where the customer currently stands on one thing: their newest row, or
     * the type's own default when they have never been asked.
     */
    public function state(Customer $customer, ConsentType $type): bool
    {
        $latest = $this->historyQuery($customer, $type)->orderByDesc('id')->first();

        return $latest?->granted ?? $type->default();
    }

    /**
     * Every type, as a type => granted map.
     *
     * @return array<string, bool>
     */
    public function states(Customer $customer): array
    {
        $states = [];

        foreach (ConsentType::cases() as $type) {
            $states[$type->value] = $this->state($customer, $type);
        }

        return $states;
    }

    /**
     * The trail, newest first — what the management consents tab lists, and
     * what makes "when, and how" answerable rather than just "yes or no".
     *
     * @return EloquentCollection<int, CustomerConsent>
     */
    public function history(Customer $customer, ?ConsentType $type = null): EloquentCollection
    {
        return $this->historyQuery($customer, $type)->orderByDesc('id')->get();
    }

    /**
     * @return Builder<CustomerConsent>
     */
    private function historyQuery(Customer $customer, ?ConsentType $type = null): Builder
    {
        return CustomerConsent::query()
            ->where('customer_id', $customer->id)
            ->when($type !== null, fn ($query) => $query->where('type', $type->value));
    }
}
