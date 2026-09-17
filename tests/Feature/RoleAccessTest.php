<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_account_owner_is_redirected_to_verify_email_from_dashboard()
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertRedirect(route('verification.notice'));
    }

    public function test_verified_account_owner_can_visit_the_dashboard()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
    }

    public function test_unverified_tutor_is_redirected_to_verify_email_from_onboarding()
    {
        $user = User::factory()->unverified()->tutor()->create();

        $response = $this->actingAs($user)->get(route('tutor.onboarding'));

        $response->assertRedirect(route('verification.notice'));
    }

    public function test_verified_tutor_can_visit_the_onboarding_page()
    {
        $user = User::factory()->tutor()->create();

        $response = $this->actingAs($user)->get(route('tutor.onboarding'));

        $response->assertOk();
    }

    public function test_verified_account_owner_cannot_visit_the_tutor_onboarding_page()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('tutor.onboarding'));

        $response->assertForbidden();
    }

    public function test_verified_account_owner_cannot_visit_the_admin_panel()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin');

        $response->assertForbidden();
    }

    public function test_verified_tutor_cannot_visit_the_dashboard()
    {
        $user = User::factory()->tutor()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertForbidden();
    }

    public function test_verified_tutor_cannot_visit_the_admin_panel()
    {
        $user = User::factory()->tutor()->create();

        $response = $this->actingAs($user)->get('/admin');

        $response->assertForbidden();
    }

    public function test_admin_cannot_visit_the_dashboard()
    {
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertForbidden();
    }

    public function test_admin_cannot_visit_the_tutor_onboarding_page()
    {
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)->get(route('tutor.onboarding'));

        $response->assertForbidden();
    }

    public function test_admin_can_visit_the_admin_panel()
    {
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)->get('/admin');

        $response->assertOk();
    }

    public function test_account_owner_login_redirects_to_the_dashboard()
    {
        $user = User::factory()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('dashboard'));
    }

    public function test_tutor_login_redirects_to_onboarding()
    {
        $user = User::factory()->tutor()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('tutor.onboarding'));
    }

    public function test_admin_login_redirects_to_the_admin_panel()
    {
        $user = User::factory()->admin()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('filament.admin.pages.dashboard'));
    }
}
