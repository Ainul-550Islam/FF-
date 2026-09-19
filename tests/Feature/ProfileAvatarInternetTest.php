<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ProfileAvatarInternetTest extends \Tests\TestCase
{
    use RefreshDatabase;

    public function test_profile_page_requires_auth_and_shows_avatar_and_internet_status()
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('profile.show'));
        $response->assertStatus(200);
        $response->assertSee('Profile');
        $response->assertSee('data-internet-status');
        $response->assertSee('avatar');
        $response->assertSee($user->initials);
    }

    public function test_profile_edit_page_has_avatar_upload_and_internet_check()
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('profile.edit'));
        $response->assertStatus(200);
        $response->assertSee('Edit Profile');
        $response->assertSee('data-avatar-input');
        $response->assertSee('data-internet-status');
        $response->assertSee('Avatar');
    }

    public function test_avatar_upload_works_with_validation()
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $source = public_path('apple-touch-icon.png');
        $tmpPath = sys_get_temp_dir().'/test_avatar_'.uniqid().'.png';
        copy($source, $tmpPath);
        $file = new UploadedFile($tmpPath, 'avatar.png', 'image/png', null, true);

        $response = $this->actingAs($user)->put(route('profile.update'), [
            'name' => 'Updated Name',
            'email' => $user->email,
            'avatar' => $file,
        ]);
        $response->assertRedirect(route('profile.show'));
        $user->refresh();
        $this->assertNotNull($user->avatar_path);
        @unlink($tmpPath);
    }

    public function test_avatar_served_via_authenticated_route()
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $source = public_path('apple-touch-icon.png');
        Storage::disk('local')->put('avatars/'.$user->id.'/test.png', file_get_contents($source));
        $user->forceFill(['avatar_path' => 'avatars/'.$user->id.'/test.png'])->save();

        $response = $this->actingAs($user)->get(route('avatar.show', $user));
        $response->assertStatus(200);
        $response->assertHeader('Content-Type');
    }

    public function test_settings_pages_exist_with_internet_check()
    {
        $user = User::factory()->create();
        $routes = [
            'settings.security',
            'settings.sessions',
            'settings.login-history',
            'settings.connected-accounts',
            'settings.payment-methods',
        ];
        foreach ($routes as $route) {
            $response = $this->actingAs($user)->get(route($route));
            $response->assertStatus(200, "Failed for $route");
            $response->assertSee('data-internet-status');
        }
    }

    public function test_home_has_internet_status_and_avatar_features()
    {
        $response = $this->get(route('home'));
        $response->assertStatus(200);
        $response->assertSee('data-internet-status');
        $response->assertSee('FF Arena');
    }

    public function test_layout_has_skip_link_and_landmarks()
    {
        $response = $this->get(route('home'));
        $response->assertStatus(200);
        $response->assertSee('skip-link');
        $response->assertSee('main');
        $response->assertSee('lang="en"', false);
    }

    public function test_offline_banner_exists()
    {
        $response = $this->get(route('home'));
        $response->assertStatus(200);
        $response->assertSee('offline-banner');
        $response->assertSee('You are offline');
    }
}
