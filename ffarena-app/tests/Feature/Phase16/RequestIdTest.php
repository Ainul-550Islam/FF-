<?php

namespace Tests\Feature\Phase16;

use App\Support\Logging\RedactSensitiveDataProcessor;
use App\Support\Logging\RequestContextProcessor;
use App\Support\RequestContext;
use Illuminate\Http\Request;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Phase 16 — request correlation id.
 */
class RequestIdTest extends Phase16TestCase
{
    public function test_request_id_is_generated_and_returned(): void
    {
        $response = $this->getJson('/health/live')->assertStatus(200);

        $id = $response->headers->get('X-Request-ID');

        $this->assertNotNull($id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $id);
    }

    public function test_valid_inbound_request_id_is_accepted(): void
    {
        $response = $this->withHeader('X-Request-ID', 'loadbalancer-probe-001')
            ->getJson('/health/live');

        $this->assertSame('loadbalancer-probe-001', $response->headers->get('X-Request-ID'));
    }

    public function test_invalid_oversized_request_id_is_replaced(): void
    {
        $malicious = str_repeat('a', 4096);

        $response = $this->withHeader('X-Request-ID', $malicious)
            ->getJson('/health/live');

        $id = $response->headers->get('X-Request-ID');

        $this->assertNotNull($id);
        $this->assertNotSame($malicious, $id);
        $this->assertLessThan(100, strlen((string) $id));
    }

    public function test_request_id_with_control_chars_is_replaced(): void
    {
        $response = $this->withHeader('X-Request-ID', "bad id with spaces\tand\ttabs")
            ->getJson('/health/live');

        $id = $response->headers->get('X-Request-ID');

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-]+$/', (string) $id);
    }

    public function test_context_processor_attaches_request_id_to_logs(): void
    {
        RequestContext::start(Request::create('/x', 'GET'), 'req-abc-1234');

        try {
            $processor = new RequestContextProcessor;
            $record = new LogRecord(new \DateTimeImmutable, 'test', Level::Info, 'message', [], []);

            $processed = $processor($record);

            $this->assertArrayHasKey('request_id', $processed->extra);
            $this->assertSame('req-abc-1234', $processed->extra['request_id']);
        } finally {
            RequestContext::flush();
        }
    }

    public function test_redaction_processor_scrubs_secrets_from_logs(): void
    {
        $processor = new RedactSensitiveDataProcessor;

        $record = new LogRecord(
            new \DateTimeImmutable,
            'test',
            Level::Info,
            'login with password=sup3rs3cret and bearer abc123',
            ['password' => 'hunter2', 'api_key' => 'k-12345', 'note' => 'safe'],
            [],
        );

        $processed = $processor($record);

        $this->assertStringNotContainsString('sup3rs3cret', $processed->message);
        $this->assertStringNotContainsString('abc123', $processed->message);
        $this->assertSame('[REDACTED]', $processed->context['password']);
        $this->assertSame('[REDACTED]', $processed->context['api_key']);
        $this->assertSame('safe', $processed->context['note']);
    }
}
