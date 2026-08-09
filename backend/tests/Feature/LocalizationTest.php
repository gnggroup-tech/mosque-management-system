<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_french_is_the_default_locale(): void
    {
        $this->get('/')->assertOk()->assertSee('lang="fr"', false)->assertSee('dir="ltr"', false);
    }

    public function test_guest_can_store_a_supported_locale_in_session(): void
    {
        $this->from('/')->post('/locale', ['locale' => 'en'])
            ->assertRedirect('/')
            ->assertSessionHas('locale', 'en');
    }

    public function test_guest_session_locale_is_applied_on_following_request(): void
    {
        $this->withSession(['locale' => 'en'])->get('/')->assertSee('lang="en"', false);
    }

    public function test_unsupported_locale_is_rejected(): void
    {
        $this->from('/')->post('/locale', ['locale' => 'de'])
            ->assertRedirect('/')
            ->assertSessionHasErrors('locale');
    }

    public function test_authenticated_user_locale_is_persisted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->from('/dashboard')->post('/locale', ['locale' => 'ar'])
            ->assertRedirect('/dashboard');

        $this->assertSame('ar', $user->refresh()->locale);
    }

    public function test_authenticated_preference_overrides_session_locale(): void
    {
        $user = User::factory()->create(['locale' => 'fr']);

        $this->actingAs($user)->withSession(['locale' => 'en'])->get('/dashboard')
            ->assertSee('lang="fr"', false);
    }

    public function test_arabic_layout_uses_right_to_left_direction(): void
    {
        $this->withSession(['locale' => 'ar'])->get('/')->assertSee('dir="rtl"', false);
    }

    public function test_invalid_stored_locale_falls_back_safely(): void
    {
        $this->withSession(['locale' => 'xx'])->get('/')->assertSee('lang="fr"', false);
    }
}
