<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 14 — saved payment-method management: ownership, masking, default
 * promotion and IDOR resistance.
 */
class PaymentMethodManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->account_status = 'active';
        $user->save();

        return $user;
    }

    public function test_user_can_add_a_payment_method(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->post(route('settings.payment-methods.store'), [
            'provider' => 'bkash',
            'label' => 'My bKash',
            'identifier' => '01712345678',
        ])->assertSessionHas('success');

        $method = PaymentMethod::where('user_id', $user->id)->first();
        $this->assertNotNull($method);
        $this->assertSame('bkash', $method->provider);
        $this->assertTrue($method->is_default, 'First method becomes the default.');
    }

    public function test_identifier_is_masked(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->post(route('settings.payment-methods.store'), [
            'provider' => 'bkash',
            'label' => 'My bKash',
            'identifier' => '01712345678',
        ]);

        $method = PaymentMethod::where('user_id', $user->id)->first();
        $this->assertStringNotContainsString('01712345678', $method->masked_identifier);
        $this->assertStringEndsWith('5678', $method->masked_identifier);
    }

    public function test_unknown_provider_is_rejected(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->post(route('settings.payment-methods.store'), [
            'provider' => 'paypal',
            'label' => 'PayPal',
            'identifier' => '01712345678',
        ])->assertSessionHasErrors('provider');
    }

    public function test_set_default_promotes_exactly_one(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->post(route('settings.payment-methods.store'), [
            'provider' => 'bkash', 'label' => 'A', 'identifier' => '01712345678',
        ]);
        $this->actingAs($user)->post(route('settings.payment-methods.store'), [
            'provider' => 'nagad', 'label' => 'B', 'identifier' => '01812345678',
        ]);

        $b = PaymentMethod::where('user_id', $user->id)->where('provider', 'nagad')->first();

        $this->actingAs($user)->post(route('settings.payment-methods.default', $b))
            ->assertSessionHas('success');

        $this->assertTrue($b->fresh()->is_default);
        $this->assertSame(1, PaymentMethod::where('user_id', $user->id)->where('is_default', true)->count());
    }

    public function test_user_cannot_remove_another_users_method(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();

        $method = new PaymentMethod();
        $method->user_id = $b->id;
        $method->provider = 'bkash';
        $method->label = 'B';
        $method->masked_identifier = '****5678';
        $method->status = PaymentMethod::STATUS_ACTIVE;
        $method->is_default = true;
        $method->save();

        $this->actingAs($a)->delete(route('settings.payment-methods.destroy', $method))
            ->assertForbidden();

        $this->assertSame(PaymentMethod::STATUS_ACTIVE, $method->fresh()->status);
    }

    public function test_user_cannot_set_default_on_another_users_method(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();

        $method = new PaymentMethod();
        $method->user_id = $b->id;
        $method->provider = 'bkash';
        $method->label = 'B';
        $method->masked_identifier = '****5678';
        $method->status = PaymentMethod::STATUS_ACTIVE;
        $method->save();

        $this->actingAs($a)->post(route('settings.payment-methods.default', $method))
            ->assertForbidden();
    }

    public function test_removing_default_promotes_another(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->post(route('settings.payment-methods.store'), [
            'provider' => 'bkash', 'label' => 'A', 'identifier' => '01712345678',
        ]);
        $this->actingAs($user)->post(route('settings.payment-methods.store'), [
            'provider' => 'nagad', 'label' => 'B', 'identifier' => '01812345678',
        ]);

        $a = PaymentMethod::where('user_id', $user->id)->where('provider', 'bkash')->first();

        $this->actingAs($user)->delete(route('settings.payment-methods.destroy', $a))
            ->assertSessionHas('success');

        $b = PaymentMethod::where('user_id', $user->id)->where('provider', 'nagad')->first();
        $this->assertTrue($b->fresh()->is_default, 'Remaining method is promoted to default.');
    }
}
