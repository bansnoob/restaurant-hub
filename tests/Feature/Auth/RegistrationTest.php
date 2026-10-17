<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Self-service sign-up is closed. It used to create a role-less account that went
 * straight to the dashboard and its company-wide figures. Accounts are now made by
 * the owner (a cashier login is created from the employee record).
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_registration_screen_is_not_served(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_nobody_can_register_an_account(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertNotFound();
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }
}
