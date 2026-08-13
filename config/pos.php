<?php

return [
    'link' => env('POS_LINK', 'https://pos.storify.ng'),
    'token_expiry_minutes' => (int) env('POS_TOKEN_EXPIRY_MINUTES', 480),
    'idle_timeout_minutes' => (int) env('POS_IDLE_TIMEOUT_MINUTES', 15),
];
