<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a team registration is refused for a lifecycle reason:
 * registration closed, tournament already started, capacity reached, or the
 * captain already has a team in this tournament.
 *
 * The message is surfaced to the user as a flash message.
 */
class RegistrationClosedException extends RuntimeException {}
