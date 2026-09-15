<?php

namespace Tests\Feature\Phase16;

use App\Contracts\ErrorReporterInterface;
use App\Exceptions\ApiExceptionHandler;
use App\Support\ErrorReporting\ErrorReporterManager;
use App\Support\ErrorReporting\LogErrorReporter;
use DomainException;
use Illuminate\Http\Request;

/**
 * Phase 16 — provider-neutral error reporting + safe API error rendering.
 */
class ErrorReportingTest extends Phase16TestCase
{
    public function test_default_reporter_is_log_based(): void
    {
        $this->assertInstanceOf(LogErrorReporter::class, app(ErrorReporterInterface::class));
        $this->assertTrue(app(ErrorReporterInterface::class)->isConfigured());
    }

    public function test_manager_falls_back_to_log_when_sentry_not_installed(): void
    {
        config(['observability.error_reporting.driver' => 'sentry']);

        $this->assertInstanceOf(LogErrorReporter::class, (new ErrorReporterManager)->driver());
    }

    public function test_reporter_records_without_throwing(): void
    {
        $reporter = app(ErrorReporterInterface::class);

        $reporter->report(new \RuntimeException('boom'), ['request_id' => 'req-1']);
        $reporter->captureMessage('recovered failure', 'warning', ['request_id' => 'req-2']);

        $this->assertTrue(true); // no exception = pass
    }

    public function test_api_error_envelope_hides_internals(): void
    {
        $request = Request::create('/api/v1/example', 'GET');
        $response = ApiExceptionHandler::render(new \RuntimeException('secret-internal-detail'), $request);

        $this->assertNotNull($response);

        $body = $response->getData(true);

        $this->assertArrayHasKey('error', $body);
        $this->assertArrayNotHasKey('trace', $body['error']);
        $this->assertArrayNotHasKey('sql', $body['error']);

        $serialized = json_encode($body);
        $this->assertStringNotContainsString('secret-internal-detail', $serialized);
    }

    public function test_domain_exception_maps_to_conflict(): void
    {
        $request = Request::create('/api/v1/example', 'GET');
        $response = ApiExceptionHandler::render(new DomainException('nope'), $request);

        $this->assertNotNull($response);
        $this->assertSame(409, $response->getStatusCode());
    }

    public function test_web_requests_are_not_transformed_by_api_handler(): void
    {
        $request = Request::create('/some-web-page', 'GET');

        $this->assertNull(ApiExceptionHandler::render(new \RuntimeException('x'), $request));
    }
}
