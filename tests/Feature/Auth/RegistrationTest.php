<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register_as_customer(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test Customer',
            'email' => 'customer_reg@example.com',
            'role' => 'customer',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('customer.dashboard', absolute: false));
    }

    public function test_new_users_can_register_as_business_owner(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test Owner',
            'email' => 'owner_reg@example.com',
            'role' => 'business_owner',
            'business_name' => 'My Test Spa',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('owner.dashboard', absolute: false));
        $this->assertDatabaseHas('businesses', ['name' => 'My Test Spa']);
    }
}
