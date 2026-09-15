<?php

namespace App\Exceptions;

use App\Support\ApiResponse;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Phase 15 — centralized API error rendering.
 *
 * Maps exceptions to the standard `{ "error": { code, message, details } }`
 * envelope for /api/* requests only. Stack traces, SQL, credentials, class
 * paths and environment values are never included. Web routes keep Laravel's
 * default rendering (the renderer is only invoked for /api/* requests).
 */
class ApiExceptionHandler
{
    /**
     * Render an exception as a JSON API error, or return null to fall back
     * to Laravel's default handling.
     */
    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        return match (true) {
            $e instanceof ValidationException => self::validation($e),
            $e instanceof AuthenticationException => ApiResponse::error(
                'unauthenticated',
                'Authentication is required.',
                [],
                401
            ),
            $e instanceof AuthorizationException => ApiResponse::error(
                'forbidden',
                $e->getMessage() !== '' ? $e->getMessage() : 'You are not authorized to perform this action.',
                [],
                403
            ),
            $e instanceof ModelNotFoundException => ApiResponse::error(
                'not_found',
                'The requested resource does not exist.',
                [],
                404
            ),
            $e instanceof NotFoundHttpException => ApiResponse::error(
                'not_found',
                'The requested endpoint does not exist.',
                [],
                404
            ),
            $e instanceof DomainException => self::domain($e),
            $e instanceof HttpExceptionInterface => ApiResponse::error(
                self::httpCode($e->getStatusCode()),
                self::httpMessage($e->getStatusCode(), $e->getMessage()),
                [],
                $e->getStatusCode()
            ),
            default => self::unexpected(),
        };
    }

    /**
     * Validation errors become a 422 with field-level details. Attribute
     * names are normalized but never echo raw request values.
     */
    protected static function validation(ValidationException $e): JsonResponse
    {
        $details = [];

        foreach ($e->errors() as $field => $messages) {
            $details[$field] = is_array($messages) ? $messages[0] : $messages;
        }

        return ApiResponse::error(
            'validation_error',
            'The request could not be processed.',
            $details,
            422
        );
    }

    /**
     * DomainException carries a business rule violation. When its code is a
     * real HTTP status (e.g. PaymentService's 404/400 for mismatches) that
     * status is preserved; otherwise it maps to a 409 conflict.
     */
    protected static function domain(DomainException $e): JsonResponse
    {
        $code = (int) $e->getCode();
        $status = ($code >= 400 && $code < 600) ? $code : 409;

        return ApiResponse::error(
            $status === 409 ? 'conflict' : 'invalid_request',
            $e->getMessage(),
            [],
            $status
        );
    }

    /**
     * Unexpected errors become a generic 500 with no internals. The real
     * exception is still reported to the application log.
     */
    protected static function unexpected(): JsonResponse
    {
        return ApiResponse::error(
            'server_error',
            'An unexpected error occurred.',
            [],
            500
        );
    }

    protected static function httpCode(int $status): string
    {
        return match ($status) {
            429 => 'rate_limited',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            default => 'http_error',
        };
    }

    protected static function httpMessage(int $status, string $fallback): string
    {
        return match ($status) {
            429 => 'Too many requests. Please slow down.',
            403 => $fallback !== '' ? $fallback : 'Forbidden.',
            404 => 'Not found.',
            405 => 'Method not allowed.',
            default => $fallback !== '' ? $fallback : 'Request failed.',
        };
    }
}
