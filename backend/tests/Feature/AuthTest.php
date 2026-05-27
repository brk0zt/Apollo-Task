<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test user registration is successful and registers event stream telemetry.
     */
    public function test_user_can_register_successfully(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'access_token',
                'token_type',
                'user' => ['id', 'name', 'email']
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
        ]);

        // Assert L1 telemetry login log is appended
        $this->assertDatabaseHas('event_stream', [
            'event_type' => 'login',
        ]);
    }

    /**
     * Test user login with correct credentials.
     */
    public function test_user_can_login_with_correct_credentials(): void
    {
        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'john@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'access_token',
                'token_type',
                'user' => ['id', 'name', 'email']
            ]);
    }

    /**
     * Test login rate limits block rapid password guessing attempts.
     */
    public function test_login_rate_limiter_blocks_burst_attempts(): void
    {
        $email = 'brute@example.com';
        User::create([
            'name' => 'Target User',
            'email' => $email,
            'password' => Hash::make('securepassword'),
        ]);

        // AuthRateLimiter has a capacity of 5. The 6th concurrent request must be rate limited!
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/auth/login', [
                'email' => $email,
                'password' => 'wrongpassword' . $i,
            ]);
            $response->assertStatus(422); // Validation error for bad credentials
        }

        // 6th attempt must return 429 Too Many Requests
        $response = $this->postJson('/api/auth/login', [
            'email' => $email,
            'password' => 'wrongpassword6',
        ]);

        $response->assertStatus(429)
            ->assertJson([
                'error' => 'Too Many Requests'
            ]);
    }
}
