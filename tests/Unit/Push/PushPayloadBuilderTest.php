<?php

namespace Tests\Unit\Push;

use App\Models\Notification;
use App\Services\Push\PushPayloadBuilder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PushPayloadBuilderTest extends TestCase
{
    private PushPayloadBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = $this->app->make(PushPayloadBuilder::class);
    }

    private function notification(string $type, string $body = 'Full body', array $data = []): Notification
    {
        $n = new Notification;
        $n->id = 42;
        $n->type = $type;
        $n->title = 'Title';
        $n->body = $body;
        $n->data = $data;

        return $n;
    }

    #[Test]
    public function maps_type_to_category(): void
    {
        $this->assertSame('payment', $this->builder->categoryFor('payment.verified'));
        $this->assertSame('payout', $this->builder->categoryFor('payout.processed'));
        $this->assertSame('payout', $this->builder->categoryFor('settlement.completed'));
        $this->assertSame('dispute', $this->builder->categoryFor('dispute.opened'));
        $this->assertSame('support', $this->builder->categoryFor('support.reply'));
        $this->assertSame('team', $this->builder->categoryFor('team.registered'));
        $this->assertSame('tournament', $this->builder->categoryFor('tournament.starting'));
        $this->assertSame('match', $this->builder->categoryFor('match.completed'));
        $this->assertSame('security', $this->builder->categoryFor('auth.suspicious_login'));
        $this->assertSame('security', $this->builder->categoryFor('identity.verified'));
        $this->assertNull($this->builder->categoryFor('system'));
    }

    #[Test]
    public function security_events_get_high_priority(): void
    {
        $this->assertSame(
            PushPayloadBuilder::PRIORITY_HIGH,
            $this->builder->priorityFor('auth.suspicious_login'),
        );
        $this->assertSame(
            PushPayloadBuilder::PRIORITY_HIGH,
            $this->builder->priorityFor('restriction.applied'),
        );
        $this->assertSame(
            PushPayloadBuilder::PRIORITY_NORMAL,
            $this->builder->priorityFor('team.registered'),
        );
    }

    #[Test]
    public function sensitive_bodies_are_redacted(): void
    {
        $message = $this->builder->build(
            $this->notification('payment.verified', 'Your wallet balance is 50000 BDT'),
        );

        $this->assertStringNotContainsString('50000', $message->body);
        $this->assertStringContainsString('updated', $message->body);
    }

    #[Test]
    public function non_sensitive_bodies_are_preserved(): void
    {
        $message = $this->builder->build(
            $this->notification('team.registered', 'Your team has been registered.'),
        );

        $this->assertSame('Your team has been registered.', $message->body);
    }

    #[Test]
    public function unmapped_future_types_default_to_redacted(): void
    {
        $message = $this->builder->build(
            $this->notification('something.new', 'A brand new leaky body'),
        );

        // Fail-safe: unknown types are redacted until explicitly mapped.
        $this->assertStringNotContainsString('leaky', $message->body);
    }

    #[Test]
    public function builds_deep_link_from_server_authored_entity_hints(): void
    {
        $message = $this->builder->build(
            $this->notification('payment.verified', 'x', ['payment_id' => 7]),
        );

        $this->assertSame('payment', $message->data['entity_type']);
        $this->assertSame('7', $message->data['entity_id']);
        $this->assertSame('ffarena://payment/7', $message->data['deep_link']);
    }

    #[Test]
    public function omits_deep_link_without_entity_hints(): void
    {
        $message = $this->builder->build($this->notification('system', 'x', []));

        $this->assertArrayNotHasKey('deep_link', $message->data);
        $this->assertArrayNotHasKey('entity_type', $message->data);
    }

    #[Test]
    public function always_includes_notification_id_and_type_for_dedup(): void
    {
        $message = $this->builder->build(
            $this->notification('match.completed', 'Match done', ['match_id' => 9]),
        );

        $this->assertSame('42', $message->data['notification_id']);
        $this->assertSame('match.completed', $message->data['type']);
        $this->assertSame('match', $message->data['category']);
    }
}
