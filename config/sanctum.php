<?php

return [
    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', '')),
    'expiration' => null,
    'token_prefix' => 'iaf_',
];
