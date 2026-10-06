<?php

/*
| This file used to hold Laravel's stock example test, asserting that GET /
| returns 200. That was true when this app also served the marketing site.
| It is API-only now — the marketing site, storefront, management and admin
| console are separate Vue applications — so the root deliberately has no
| page. These assertions pin that down instead.
*/

test('the api root serves no page', function () {
    // A JSON client gets a clean 404 rather than a redirect or an HTML error
    // page, which is what an API host should do with an unmatched path.
    $this->getJson('/')->assertNotFound();
});

test('the health endpoint responds', function () {
    $this->get('/up')->assertOk();
});
