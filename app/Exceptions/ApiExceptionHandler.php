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
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
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
            // A method that is not allowed on a *defined* endpoint stays a 405;
            // a URI that no endpoint defines at all is an honest 404 (the web
            // fallback route only answers GET, so POSTs to unknown paths would
            // otherwise surface as 405).
            $e instanceof MethodNotAllowedHttpException => self::methodNotAllowed($request),
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
     * True when any route (regardless of HTTP method) defines this URI.
     *
     * The router itself only matches method+URI, so a POST to a path that
     * exists solely as a GET would be reported as "method not allowed". This
     * check keeps 405 for defined endpoints and 404 for unknown paths.
     */
    protected static function uriIsDefined(Request $request): bool
    {
        $path = '/'.trim($request->path(), '/');

        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            // The catch-all fallback route matches every path; it is not a
            // definition of the URI.
            if (str_contains($route->uri(), 'fallbackPlaceholder')) {
                continue;
            }

            if (preg_match(self::uriRegex($route->uri()), $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a method-agnostic regex for a route URI (`{param}` → one segment,
     * trailing `{param?}` → optional).
     */
    protected static function uriRegex(string $uri): string
    {
        $segments = explode('/', trim($uri, '/'));

        $expected = 0;
        $optionalFrom = null;

        foreach ($segments as $index => $segment) {
            if ($segment !== '' && str_starts_with($segment, '{')) {
                $segments[$index] = '[^/]+';

                if (str_ends_with($segment, '?}')) {
                    $optionalFrom ??= $index;
                }
            } else {
                $segments[$index] = preg_quote($segment, '#');
            }

            $expected++;
        }

        $required = $optionalFrom ?? $expected;
        $head = array_slice($segments, 0, $required);

        return '#^/'.implode('/', $head).'/?$#';
    }

    /**
     * 405 for a defined endpoint, 404 for an unknown path (API envelope).
     */
    protected static function methodNotAllowed(Request $request): JsonResponse
    {
        if (! self::uriIsDefined($request)) {
            return ApiResponse::error('not_found', 'The requested endpoint does not exist.', [], 404);
        }

        return ApiResponse::error(
            'method_not_allowed',
            'This HTTP method is not supported for the requested endpoint.',
            [],
            405
        );
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
