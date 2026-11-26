<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use App\Services\Money;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GenerationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::fake([
            '*/pricing' => Http::response(['rates' => config('pixelforge.default_rates')]),
            '*/generations' => Http::response(['id' => 'gen_abc', 'status' => 'queued'], 202),
        ]);
    }

    public function test_estimate_matches_the_documented_worst_case_example(): void
    {
        // Docs: high, 1536x1024, 400-char prompt, 2 reference images => max 0.373875 USD.
        $e = app(PricingService::class)->estimate(str_repeat('a', 400), '1536x1024', 'high', 2);

        $this->assertSame(373_875_000, $e['max_nano']);
        $this->assertSame(Money::withMarkup(373_875_000), $e['price_max_nano']);
    }

    public function test_user_without_credits_cannot_generate(): void
    {
        $user = User::factory()->create(['balance_nano' => 0]);

        $this->actingAs($user)->post('/generate', ['prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'low'])
            ->assertSessionHasErrors('prompt');

        $this->assertSame(0, Generation::count());
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_generation_is_submitted_with_idempotency_key_and_saved(): void
    {
        $user = User::factory()->create(['balance_nano' => Money::usdToNano(5)]);

        $this->actingAs($user)->post('/generate', ['prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'low'])
            ->assertRedirect('/history');

        $g = Generation::firstOrFail();
        $this->assertSame('gen_abc', $g->provider_id);
        $this->assertGreaterThan(0, $g->estimated_cost_nano);
        Http::assertSent(fn ($r) => $r->hasHeader('Idempotency-Key', $g->uuid));
    }

    public function test_in_flight_generations_reserve_balance(): void
    {
        $user = User::factory()->create(['balance_nano' => 500_000_000]);
        Generation::factory()->for($user)->create(['estimated_max_nano' => 450_000_000]);

        $this->actingAs($user)->post('/generate', ['prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'high'])
            ->assertSessionHasErrors('prompt');
    }

    public function test_buying_credits_adds_balance_and_ledger_entry(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/credits', ['amount' => 10])->assertRedirect();
        $this->actingAs($user)->post('/credits', ['amount' => 7])->assertSessionHasErrors('amount');

        $this->assertSame(10_000_000_000, $user->fresh()->balance_nano);
        $this->assertDatabaseCount('ledger_entries', 1);
    }

    public function test_admin_report_is_restricted_and_sums_correctly(): void
    {
        Http::fake(['*/usage*' => Http::response(['data' => [], 'total' => ['cost_usd' => '0.000000000']])]);
        $user = User::factory()->create();
        Generation::factory()->for($user)->create([
            'status' => 'succeeded', 'completed_at' => now(), 'provider_cost_nano' => 100, 'charged_nano' => 125,
        ]);

        $this->actingAs($user)->get('/admin/report')->assertForbidden();

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/admin/report')->assertOk()
            ->assertInertia(fn ($p) => $p->where('totals.profit_nano', 25)->where('totals.cost_nano', 100));
    }
}
