<?php

use App\Providers\AppServiceProvider;
use App\Providers\TrustedProxyServiceProvider;

return [
    AppServiceProvider::class,

    // GAP-10 (F-25) — apply TRUSTED_PROXIES / TRUSTED_PROXY_HEADERS. Runs after
    // LoadConfiguration, so `config()` is available; see the class docblock.
    TrustedProxyServiceProvider::class,
];
