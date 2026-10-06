<?php

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
| This application is API-only. The web stack is kept for exactly two things
| that genuinely need it: the Paystack webhook (signature-verified, CSRF
| exempt) and the public invoice payment page, which is emailed to people who
| have no account. Everything else lives under routes/api.php.
*/

require __DIR__.'/v1/home.php';
require __DIR__.'/v1/storefront.php';
