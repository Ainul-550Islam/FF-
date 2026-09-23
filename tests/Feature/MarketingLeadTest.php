<?php

namespace Tests\Feature;

use App\Models\MarketingEvent;
use App\Models\MarketingLead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 20 — lead capture + lifecycle opt-out.
 *
 * One row per email (refresh instead of duplicate), the endpoint decides
 * the type server-side, attribution enriches the lead, and every lead gets
 * a working unsubscribe token.
 */
class MarketingLeadTest extends TestCase
{
    use RefreshDatabase;

    public function test_newsletter_capture_creates_a_lead(): void
    {
        $this->post('/newsletter', ['email' => 'Player@Example.com', 'name' => 'Rafi'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $lead = MarketingLead::query()->first();
        $this->assertNotNull($lead);
        $this->assertSame('player@example.com', $lead->email, 'Emails are stored lower-cased.');
        $this->assertSame(MarketingLead::TYPE_NEWSLETTER, $lead->type);
        $this->assertNotNull($lead->subscribed_at);
        $this->assertNotNull($lead->unsubscribe_token);
    }

    public function test_contact_endpoint_forces_the_contact_type_server_side(): void
    {
        $this->post('/contact', [
            'email' => 'org@example.com',
            'name' => 'Organizer Olivia',
            'type' => MarketingLead::TYPE_NEWSLETTER, // spoofed — must be overridden
            'message' => 'I want to run a tournament.',
        ])->assertRedirect();

        $lead = MarketingLead::query()->first();
        $this->assertSame(MarketingLead::TYPE_CONTACT, $lead->type, 'The client cannot choose the lead type.');
        $this->assertSame('I want to run a tournament.', $lead->metadata['message']);
    }

    public function test_a_repeat_capture_refreshes_instead_of_duplicating(): void
    {
        $this->post('/newsletter', ['email' => 'dupe@example.com', 'name' => 'First'])->assertRedirect();
        $this->post('/newsletter', ['email' => 'dupe@example.com', 'name' => 'Second'])->assertRedirect();

        $this->assertSame(1, MarketingLead::query()->where('email', 'dupe@example.com')->count());
        $this->assertSame('Second', MarketingLead::query()->where('email', 'dupe@example.com')->value('name'));
    }

    public function test_capture_after_unsubscribe_resubscribes(): void
    {
        $this->post('/newsletter', ['email' => 'back@example.com'])->assertRedirect();

        $lead = MarketingLead::query()->first();
        $lead->unsubscribed_at = now();
        $lead->save();

        $this->post('/newsletter', ['email' => 'back@example.com'])->assertRedirect();

        $lead->refresh();
        $this->assertNull($lead->unsubscribed_at, 'A fresh capture re-subscribes.');
        $this->assertSame(1, MarketingLead::query()->count());
    }

    public function test_the_lead_is_enriched_from_the_latest_touch(): void
    {
        $this->withCredentials();
        $this->withCookies(['ff_aid' => 'lead-visitor-1']);
        $this->get('/?utm_source=facebook&utm_medium=cpc&utm_campaign=spring-launch');

        $this->withCredentials();
        $this->withCookies(['ff_aid' => 'lead-visitor-1']);
        $this->post('/newsletter', ['email' => 'sourced@example.com'])->assertRedirect();

        $lead = MarketingLead::query()->where('email', 'sourced@example.com')->first();
        $this->assertSame('facebook', $lead->source);
        $this->assertSame('cpc', $lead->medium);
        $this->assertSame('spring-launch', $lead->campaign);
    }

    public function test_an_authenticated_capture_links_the_account(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->post('/newsletter', ['email' => 'me@example.com'])->assertRedirect();

        $lead = MarketingLead::query()->first();
        $this->assertSame($user->id, $lead->user_id);
    }

    public function test_a_capture_is_measured_in_the_funnel(): void
    {
        $this->post('/newsletter', ['email' => 'measured@example.com'])->assertRedirect();

        $this->assertSame(1, MarketingEvent::query()->where('name', 'lead_captured')->count());
    }

    public function test_invalid_email_is_rejected(): void
    {
        $this->post('/newsletter', ['email' => 'not-an-email'])
            ->assertSessionHasErrors(['email']);

        $this->assertSame(0, MarketingLead::query()->count());
    }

    public function test_oversized_message_is_rejected(): void
    {
        $this->post('/contact', [
            'email' => 'big@example.com',
            'message' => str_repeat('x', 2001),
        ])->assertSessionHasErrors(['message']);

        $this->assertSame(0, MarketingLead::query()->count());
    }

    public function test_unsubscribe_by_token_works(): void
    {
        $this->post('/newsletter', ['email' => 'bye@example.com'])->assertRedirect();

        $token = MarketingLead::query()->value('unsubscribe_token');

        $this->get("/marketing/unsubscribe/{$token}")
            ->assertRedirect()
            ->assertSessionHas('success');

        $lead = MarketingLead::query()->first();
        $this->assertFalse($lead->isSubscribed());
    }

    public function test_unsubscribe_with_an_unknown_token_fails_cleanly(): void
    {
        $this->get('/marketing/unsubscribe/not-a-real-token')
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, MarketingLead::query()->count());
    }

    public function test_unsubscribe_tokens_are_unique_per_lead(): void
    {
        $this->post('/newsletter', ['email' => 'one@example.com'])->assertRedirect();
        $this->post('/newsletter', ['email' => 'two@example.com'])->assertRedirect();

        $tokens = MarketingLead::query()->pluck('unsubscribe_token')->unique();

        $this->assertSame(2, $tokens->count(), 'Every lead has its own opt-out token.');
    }
}
