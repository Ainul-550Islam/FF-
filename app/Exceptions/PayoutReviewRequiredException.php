<?php

namespace App\Exceptions;

use DomainException;

/**
 * Thrown when a payout is held for fraud review (Phase 10).
 *
 * Distinct from a generic DomainException so the distribution processing
 * loop can HOLD (leave the payout pending review) instead of failing it.
 */
class PayoutReviewRequiredException extends DomainException {}
