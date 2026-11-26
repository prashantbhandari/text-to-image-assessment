<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminSectionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    private function usage(array $data, string $total = '0.000000000'): void
    {
        Http::fake(['*/usage*' => Http::response(['data' => $data, 'total' => ['cost_usd' => $total]])]);
    }

    public function test_make_admin_command_really_promotes_the_user(): void
    {
        $user = User::factory()->create();

        $this->artisan('app:make-admin', ['email' => $user->email])->assertSuccessful();
        $this->assertTrue($user->fresh()->is_admin);

        $this->artisan('app:make-admin', ['email' => 'nobody@example.test'])->assertFailed();
    }

    public function test_every_admin_page_is_forbidden_for_regular_users(): void
    {
        $user = User::factory()->create();

        foreach (['/admin/report', '/admin/report/export', '/admin/users', '/admin/webhooks'] as $url) {
            $this->actingAs($user)->get($url)->assertForbidden();
        }
        $this->actingAs($user)->post("/admin/users/{$user->id}/credits", ['amount' => '5'])->assertForbidden();
        $this->assertSame(0, $user->fresh()->balance_nano);
    }

    public function test_report_only_counts_generations_completed_inside_the_range(): void
    {
        $this->usage([]);
        $user = User::factory()->create();
        Generation::factory()->for($user)->create(['status' => 'succeeded', 'completed_at' => '2026-11-10 23:59:00', 'provider_cost_nano' => 100, 'charged_nano' => 125]);
        Generation::factory()->for($user)->create(['status' => 'succeeded', 'completed_at' => '2026-11-11 00:00:01', 'provider_cost_nano' => 40, 'charged_nano' => 50]);
        Generation::factory()->for($user)->create(['status' => 'failed', 'completed_at' => '2026-11-10 12:00:00', 'provider_cost_nano' => 0, 'charged_nano' => 0]);

        $this->actingAs($this->admin)->get('/admin/report?from=2026-11-10&to=2026-11-10')
            ->assertInertia(fn ($p) => $p->where('totals.cost_nano', 100)->where('totals.charged_nano', 125)->where('totals.generations', 1)
                ->where('range.from', '2026-11-10'));
    }

    public function test_report_range_is_clamped_and_bad_input_falls_back(): void
    {
        $this->usage([]);

        $this->actingAs($this->admin)->get('/admin/report?from=2020-01-01&to=2026-11-10')
            ->assertInertia(fn ($p) => $p->where('range.to', '2026-11-10')->where('range.from', '2026-08-11')); // 92 days max

        $this->actingAs($this->admin)->get('/admin/report?from=garbage&to=nope')->assertOk();
    }

    public function test_reconciliation_flags_a_day_that_differs_from_pixelforge(): void
    {
        $user = User::factory()->create();
        Generation::factory()->for($user)->create(['status' => 'succeeded', 'completed_at' => '2026-11-10 10:00:00', 'provider_cost_nano' => 169_080_000, 'charged_nano' => 1]);
        $this->usage([
            ['date' => '2026-11-10', 'generations' => 1, 'cost_usd' => '0.169080000'],
            ['date' => '2026-11-11', 'generations' => 1, 'cost_usd' => '0.050000000'], // we have nothing for this day
        ], '0.219080000');

        $this->actingAs($this->admin)->get('/admin/report?from=2026-11-10&to=2026-11-11')
            ->assertInertia(fn ($p) => $p->where('reconciliation.matches', false)->where('reconciliation.mismatches', 1)
                ->where('reconciliation.rows.0.day', '2026-11-11')->where('reconciliation.rows.0.diff_nano', -50_000_000)
                ->where('reconciliation.rows.1.match', true));
    }

    public function test_csv_export_has_exact_decimals_and_neutralises_formulas(): void
    {
        $evil = User::factory()->create(['name' => '=HYPERLINK("http://evil")']);
        Generation::factory()->for($evil)->create(['status' => 'succeeded', 'completed_at' => '2026-11-10 10:00:00', 'provider_cost_nano' => 50_750_000, 'charged_nano' => 63_437_500]);

        $csv = $this->actingAs($this->admin)->get('/admin/report/export?view=user&from=2026-11-10&to=2026-11-10')
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->streamedContent();

        $this->assertStringContainsString('0.063437500', $csv);
        $this->assertStringContainsString('0.050750000', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
    }

    public function test_admin_can_adjust_credits_and_it_is_ledgered(): void
    {
        $user = User::factory()->create(['balance_nano' => 5_000_000_000]);

        $this->actingAs($this->admin)->post("/admin/users/{$user->id}/credits", ['amount' => '2.50', 'note' => 'Goodwill'])->assertRedirect();
        $this->actingAs($this->admin)->post("/admin/users/{$user->id}/credits", ['amount' => '-1', 'note' => null])->assertRedirect();

        $this->assertSame(6_500_000_000, $user->fresh()->balance_nano);
        $this->assertDatabaseHas('ledger_entries', ['user_id' => $user->id, 'type' => LedgerEntry::ADJUSTMENT, 'amount_nano' => 2_500_000_000, 'note' => 'Goodwill']);
        $this->assertSame(2, LedgerEntry::count());
    }

    public function test_adjustment_rejects_bad_amounts_and_negative_balances(): void
    {
        $user = User::factory()->create(['balance_nano' => 1_000_000_000]);

        $this->actingAs($this->admin)->post("/admin/users/{$user->id}/credits", ['amount' => 'abc'])->assertSessionHasErrors('amount');
        $this->actingAs($this->admin)->post("/admin/users/{$user->id}/credits", ['amount' => '0'])->assertSessionHasErrors('amount');
        $this->actingAs($this->admin)->post("/admin/users/{$user->id}/credits", ['amount' => '1.999'])->assertSessionHasErrors('amount');
        $this->actingAs($this->admin)->post("/admin/users/{$user->id}/credits", ['amount' => '-5'])->assertSessionHasErrors('amount');

        $this->assertSame(1_000_000_000, $user->fresh()->balance_nano);
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_users_page_lists_spend_including_deleted_generations_and_searches(): void
    {
        $user = User::factory()->create(['name' => 'Zed Findable']);
        Generation::factory()->for($user)->create(['status' => 'succeeded', 'charged_nano' => 700])->delete();
        Generation::factory()->for($user)->create(['status' => 'succeeded', 'charged_nano' => 300]);
        User::factory()->create(['name' => 'Someone Else']);

        $this->actingAs($this->admin)->get('/admin/users?q=findable')
            ->assertInertia(fn ($p) => $p->has('users.data', 1)->where('users.data.0.spent_nano', 1000)->where('users.data.0.images', 2));
    }

    public function test_webhook_log_counts_redeliveries_and_records_the_outcome(): void
    {
        config(['pixelforge.webhook_secret' => 'whsec_test']);
        $g = Generation::factory()->create(['provider_id' => 'gen_log1']);
        $body = json_encode(['id' => 'evt_log1', 'type' => 'generation.failed', 'data' => ['object' => [
            'id' => 'gen_log1', 'status' => 'failed', 'metadata' => ['generation_uuid' => $g->uuid], 'error' => ['code' => 'generation_failed', 'message' => 'x'],
        ]]]);
        $t = time();
        $headers = ['HTTP_PIXELFORGE_SIGNATURE' => "t=$t,v1=".hash_hmac('sha256', "$t.$body", 'whsec_test'), 'CONTENT_TYPE' => 'application/json'];

        $this->call('POST', '/webhooks/pixelforge', [], [], [], $headers, $body)->assertOk();
        $this->call('POST', '/webhooks/pixelforge', [], [], [], $headers, $body)->assertOk();
        $this->call('POST', '/webhooks/pixelforge', [], [], [], $headers, $body)->assertOk();

        $event = WebhookEvent::sole();
        $this->assertSame(3, $event->delivery_count);
        $this->assertSame('applied', $event->outcome);

        $this->actingAs($this->admin)->get('/admin/webhooks?only=duplicates')
            ->assertInertia(fn ($p) => $p->has('events.data', 1)->where('stats.duplicates', 1)->where('events.data.0.delivery_count', 3));
    }

    public function test_late_stale_event_is_logged_as_ignored(): void
    {
        config(['pixelforge.webhook_secret' => 'whsec_test']);
        $g = Generation::factory()->create(['provider_id' => 'gen_log2', 'status' => 'succeeded', 'charged_nano' => 10]);
        $body = json_encode(['id' => 'evt_late', 'type' => 'generation.processing', 'data' => ['object' => ['id' => 'gen_log2', 'status' => 'processing', 'metadata' => ['generation_uuid' => $g->uuid]]]]);
        $t = time();

        $this->call('POST', '/webhooks/pixelforge', [], [], [], ['HTTP_PIXELFORGE_SIGNATURE' => "t=$t,v1=".hash_hmac('sha256', "$t.$body", 'whsec_test'), 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();

        $this->assertSame('ignored', WebhookEvent::sole()->outcome);
        $this->assertSame('succeeded', $g->fresh()->status);
    }
}
