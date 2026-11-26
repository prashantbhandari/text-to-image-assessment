<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use App\Services\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        RateLimiter::clear('x');
        Http::fake([
            '*/pricing' => Http::response(['rates' => config('pixelforge.default_rates')]),
            '*/generations' => fn () => Http::response(['id' => 'gen_'.uniqid(), 'status' => 'queued'], 202),
        ]);
    }

    public function test_login_locks_out_after_five_failures_for_the_same_account(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        }

        // Even the correct password is refused while locked out.
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_is_limited_per_ip_across_many_emails(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->post('/login', ['email' => "user{$i}@example.test", 'password' => 'x']);
        }

        $this->post('/login', ['email' => 'another@example.test', 'password' => 'x'])
            ->assertRedirect()->assertSessionHas('error');
    }

    public function test_register_is_limited_per_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/register', ['name' => 'A', 'email' => "a{$i}@example.test", 'password' => 'Password123!', 'password_confirmation' => 'Password123!']);
            $this->post('/logout');
        }

        $this->post('/register', ['name' => 'A', 'email' => 'late@example.test', 'password' => 'Password123!', 'password_confirmation' => 'Password123!'])
            ->assertRedirect()->assertSessionHas('error');
        $this->assertDatabaseMissing('users', ['email' => 'late@example.test']);
    }

    public function test_forgot_password_is_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/forgot-password', ['email' => "n{$i}@example.test"]);
        }

        $this->post('/forgot-password', ['email' => 'n6@example.test'])->assertSessionHas('error');
    }

    public function test_image_creation_is_limited_per_user(): void
    {
        $user = User::factory()->create(['balance_nano' => Money::usdToNano(100)]);
        $payload = ['prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'low'];

        for ($i = 0; $i < 6; $i++) {
            Generation::query()->update(['status' => 'succeeded']); // free the concurrency slots
            $this->actingAs($user)->post('/generate', $payload)->assertRedirect('/history');
        }

        $this->actingAs($user)->post('/generate', $payload)->assertSessionHas('error');
        $this->assertSame(6, Generation::count());
    }

    public function test_generation_is_refused_when_pixelforge_concurrency_is_full(): void
    {
        $user = User::factory()->create(['balance_nano' => Money::usdToNano(100)]);
        Generation::factory()->count(3)->create(['status' => 'processing']); // other users' jobs count too

        $this->actingAs($user)->post('/generate', ['prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'low'])
            ->assertSessionHasErrors('prompt');

        $this->assertSame(3, Generation::count());
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_stuck_old_generations_do_not_block_capacity(): void
    {
        $user = User::factory()->create(['balance_nano' => Money::usdToNano(100)]);
        Generation::factory()->count(3)->create(['status' => 'queued', 'created_at' => now()->subHour()]);

        $this->actingAs($user)->post('/generate', ['prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'low'])
            ->assertRedirect('/history');
    }

    public function test_daily_cap_per_user(): void
    {
        config(['pixelforge.max_daily_per_user' => 2]);
        $user = User::factory()->create(['balance_nano' => Money::usdToNano(100)]);
        Generation::factory()->count(2)->for($user)->create(['status' => 'succeeded']);

        $this->actingAs($user)->post('/generate', ['prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'low'])
            ->assertSessionHasErrors('prompt');
    }

    public function test_credit_purchases_are_limited(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($user)->post('/credits', ['amount' => 5]);
        }

        $this->actingAs($user)->post('/credits', ['amount' => 5])->assertSessionHas('error');
        $this->assertSame(Money::usdToNano(5) * 30, $user->fresh()->balance_nano);
    }

    public function test_json_clients_still_get_a_standard_429(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($user)->postJson('/credits', ['amount' => 5]);
        }

        $this->actingAs($user)->postJson('/credits', ['amount' => 5])->assertStatus(429)->assertHeader('Retry-After');
    }
}
