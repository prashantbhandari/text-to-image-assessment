<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['pixelforge.webhook_secret' => self::SECRET]);
    }

    /** @param array<string, mixed> $event */
    private function deliver(array $event, ?string $secret = null, ?int $time = null)
    {
        $body = json_encode($event);
        $t = $time ?? time();
        $sig = hash_hmac('sha256', $t.'.'.$body, $secret ?? self::SECRET);

        return $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'HTTP_PIXELFORGE_SIGNATURE' => "t=$t,v1=$sig",
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    /** @return array<string, mixed> */
    private function event(string $id, string $type, Generation $g, array $extra = []): array
    {
        return [
            'id' => $id,
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => array_merge([
                'id' => $g->provider_id,
                'metadata' => ['generation_uuid' => $g->uuid],
                'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
            ], $extra)],
        ];
    }

    private function succeeded(Generation $g): array
    {
        // 41 text + 3300... realistic: cost = 100*5000 + 1100*40000 + 500*12500 = 50,750,000 nano
        return $this->event('evt_s', 'generation.succeeded', $g, [
            'status' => 'succeeded',
            'usage' => ['input_text_tokens' => 100, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 500],
            'image_url' => 'https://img.test/a.png',
            'completed_at' => now()->toIso8601String(),
        ]);
    }

    public function test_bad_signature_is_rejected(): void
    {
        $g = Generation::factory()->create();
        $this->deliver($this->succeeded($g), 'wrong')->assertStatus(401);
        $this->assertSame('queued', $g->fresh()->status);
    }

    public function test_stale_timestamp_is_rejected(): void
    {
        $g = Generation::factory()->create();
        $this->deliver($this->succeeded($g), null, time() - 3600)->assertStatus(401);
    }

    public function test_success_charges_cost_plus_markup_once_even_if_delivered_twice(): void
    {
        $user = User::factory()->create(['balance_nano' => 1_000_000_000]);
        $g = Generation::factory()->for($user)->create();

        $this->deliver($this->succeeded($g))->assertOk();
        $this->deliver($this->succeeded($g))->assertOk(); // duplicate

        $g->refresh();
        $this->assertSame('succeeded', $g->status);
        $this->assertSame(50_750_000, $g->provider_cost_nano);
        $this->assertSame(63_437_500, $g->charged_nano); // x1.25
        $this->assertSame(1, LedgerEntry::count());
        $this->assertSame(1, WebhookEvent::count());
        $this->assertSame(1_000_000_000 - 63_437_500, $user->fresh()->balance_nano);
    }

    public function test_late_processing_event_cannot_undo_success(): void
    {
        $g = Generation::factory()->create();

        $this->deliver($this->succeeded($g))->assertOk();
        $this->deliver($this->event('evt_p', 'generation.processing', $g, ['status' => 'processing']))->assertOk();

        $this->assertSame('succeeded', $g->fresh()->status);
    }

    public function test_failed_generation_costs_nothing(): void
    {
        $user = User::factory()->create(['balance_nano' => 500]);
        $g = Generation::factory()->for($user)->create();

        $this->deliver($this->event('evt_f', 'generation.failed', $g, [
            'status' => 'failed', 'error' => ['code' => 'generation_failed', 'message' => 'nope'],
        ]))->assertOk();

        $this->assertSame('failed', $g->fresh()->status);
        $this->assertSame(0, $g->fresh()->charged_nano);
        $this->assertSame(500, $user->fresh()->balance_nano);
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_webhook_matches_by_metadata_before_provider_id_is_stored(): void
    {
        $g = Generation::factory()->create(['provider_id' => null]);
        $event = $this->succeeded($g);
        $event['data']['object']['id'] = 'gen_early';

        $this->deliver($event)->assertOk();

        $this->assertSame('gen_early', $g->fresh()->provider_id);
        $this->assertSame('succeeded', $g->fresh()->status);
    }
}
