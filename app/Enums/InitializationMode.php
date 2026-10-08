<?php

namespace App\Enums;

/**
 * What the caller does after a gateway accepts a charge.
 *
 * This is the one field that lets manual bank transfer be a real driver rather
 * than an `if ($code === 'paystack')` branch somewhere in the controller. The
 * storefront reads the mode and either redirects or renders instructions; it
 * never needs to know which provider it is talking to.
 */
enum InitializationMode: string
{
    /** Hosted checkout — send the customer to the provider. */
    case REDIRECT = 'redirect';

    /** Nothing to visit — show the customer what to do instead. */
    case OFFLINE = 'offline';
}
