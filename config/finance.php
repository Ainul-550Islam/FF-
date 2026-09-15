<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Financial settlement (Phase 09)
    |--------------------------------------------------------------------------
    |
    | Global financial configuration for prize distribution, payouts and
    | reconciliation. All monetary values are integer minor units (BDT
    | poisha) or basis points (1/100th of a percent) — never floats.
    |
    | Commission defaults to ZERO: no pre-existing commission business rule
    | exists in FF Arena, so no fee is silently introduced into existing or
    | new tournaments. Set PLATFORM_COMMISSION_* to enable a platform fee.
    |
    */

    'commission' => [
        // 'percentage' (basis points of net collections) or 'fixed' (poisha).
        'type' => env('PLATFORM_COMMISSION_TYPE', 'percentage'),

        // Percentage commission in basis points (10000 = 100%). Default 0.
        'percentage_bp' => (int) env('PLATFORM_COMMISSION_BP', 0),

        // Fixed commission in poisha (100 = ৳1.00). Default 0.
        'fixed_minor' => (int) env('PLATFORM_COMMISSION_FIXED_MINOR', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payouts
    |--------------------------------------------------------------------------
    |
    | The default payout provider for prize payouts.
    |  - 'wallet' : internal wallet credit (the only provider shipped today).
    |  - 'manual' : manually processed external payout (bank/bKash agent) —
    |               no external API is called and no success is faked.
    |
    */
    'default_payout_provider' => env('DEFAULT_PAYOUT_PROVIDER', 'wallet'),
];
