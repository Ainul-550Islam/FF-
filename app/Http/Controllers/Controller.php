<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;

/**
 * Base web/API controller.
 *
 * Phase 03+ controllers rely on `$this->authorize(...)` (policies: disputes,
 * matches, teams, tournaments, payouts) and on `$this->validate(...)`. Both
 * come from the framework traits below and were previously missing from this
 * base class, which turned every policy check into a fatal "Call to undefined
 * method ...::authorize()" 500. Keep these traits here: removing them silently
 * disables authorization on every controller that depends on them.
 */
abstract class Controller
{
    use AuthorizesRequests;
    use ValidatesRequests;
}
