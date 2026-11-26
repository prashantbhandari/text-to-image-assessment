<?php

namespace Tests\Feature;

use App\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CronEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_is_disabled_when_no_token_is_configured(): void
    {
        config(['pixelforge.cron_token' => null]);

        $this->get('/cron/anything')->assertNotFound();
        $this->get('/cron/')->assertNotFound();
    }

    public function test_wrong_token_is_rejected(): void
    {
        config(['pixelforge.cron_token' => 'secret-token']);

        $this->get('/cron/wrong')->assertNotFound();
    }

    public function test_correct_token_runs_the_scheduler_and_rescues_a_stuck_generation(): void
    {
        config(['pixelforge.cron_token' => 'secret-token']);
        Http::fake(['*/generations/gen_stuck' => Http::response([
            'id' => 'gen_stuck', 'status' => 'failed', 'error' => ['code' => 'generation_failed', 'message' => 'x'],
        ])]);
        $g = Generation::factory()->create(['provider_id' => 'gen_stuck', 'status' => 'processing', 'created_at' => now()->subMinutes(5)]);

        $this->get('/cron/secret-token')->assertOk()->assertSee('ok');

        $this->assertSame('failed', $g->fresh()->status); // generations:sync polled and applied it
    }
}
