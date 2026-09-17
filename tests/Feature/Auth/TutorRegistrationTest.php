<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TutorRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tutor_registration_screen_can_be_rendered()
    {
        $response = $this->get(route('tutor.register'));

        $response->assertOk();
    }

    public function test_new_tutors_can_register()
    {
        $response = $this->post(route('tutor.register.store'), [
            'name' => 'Test Tutor',
            'email' => 'tutor@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('tutor.onboarding'));

        $user = User::where('email', 'tutor@example.com')->firstOrFail();
        $this->assertSame(Role::Tutor, $user->role);
    }

    public function test_tutor_registration_ignores_a_client_supplied_role()
    {
        $this->post(route('tutor.register.store'), [
            'name' => 'Sneaky Tutor',
            'email' => 'sneaky-tutor@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'admin',
        ]);

        $user = User::where('email', 'sneaky-tutor@example.com')->firstOrFail();
        $this->assertSame(Role::Tutor, $user->role);
    }

    public function test_account_owner_registration_ignores_a_client_supplied_role()
    {
        $this->post(route('register.store'), [
            'name' => 'Sneaky Owner',
            'email' => 'sneaky-owner@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'admin',
        ]);

        $user = User::where('email', 'sneaky-owner@example.com')->firstOrFail();
        $this->assertSame(Role::AccountOwner, $user->role);
    }

    public function test_there_is_no_admin_registration_route()
    {
        $response = $this->get('/admin/register');

        $response->assertNotFound();
    }

    public function test_guest_visiting_the_admin_panel_is_redirected_to_the_admin_login()
    {
        $response = $this->get('/admin');

        $response->assertRedirect('/admin/login');
    }
}
