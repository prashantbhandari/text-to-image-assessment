<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HistoryActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::fake(['*/pricing' => Http::response(['rates' => config('pixelforge.default_rates')])]);
    }

    public function test_reuse_prefills_every_setting_from_own_generation(): void
    {
        $user = User::factory()->create();
        $g = Generation::factory()->for($user)->create([
            'prompt' => 'a red fox', 'size' => '1024x1536', 'quality' => 'high', 'reference_images' => ['https://x.test/a.png'],
        ]);

        $this->actingAs($user)->get('/generate?reuse='.$g->id)->assertOk()
            ->assertInertia(fn ($p) => $p->where('prefill.prompt', 'a red fox')->where('prefill.size', '1024x1536')
                ->where('prefill.quality', 'high')->where('prefill.reference_images', ['https://x.test/a.png']));
    }

    public function test_reuse_ignores_someone_elses_generation(): void
    {
        $other = Generation::factory()->create(['prompt' => 'secret']);

        $this->actingAs(User::factory()->create())->get('/generate?reuse='.$other->id)->assertOk()
            ->assertInertia(fn ($p) => $p->where('prefill', null));
    }

    public function test_delete_soft_deletes_a_finished_generation_and_keeps_it_in_the_report(): void
    {
        $user = User::factory()->create();
        $g = Generation::factory()->for($user)->create(['status' => 'succeeded', 'completed_at' => now(), 'provider_cost_nano' => 100, 'charged_nano' => 125]);

        $this->actingAs($user)->delete('/generations/'.$g->id)->assertRedirect();

        $this->assertSoftDeleted($g);
        $this->actingAs($user)->get('/history')->assertInertia(fn ($p) => $p->has('generations', 0));

        Http::fake(['*/usage*' => Http::response(['data' => [], 'total' => ['cost_usd' => '0.000000000']])]);
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/admin/report')
            ->assertInertia(fn ($p) => $p->where('totals.charged_nano', 125)->where('totals.cost_nano', 100));
    }

    public function test_cannot_delete_in_flight_or_other_users_generations(): void
    {
        $user = User::factory()->create();
        $active = Generation::factory()->for($user)->create(['status' => 'processing']);
        $theirs = Generation::factory()->create(['status' => 'succeeded']);

        $this->actingAs($user)->delete('/generations/'.$active->id)->assertSessionHasErrors('generation');
        $this->actingAs($user)->delete('/generations/'.$theirs->id)->assertNotFound();

        $this->assertNotSoftDeleted($active);
        $this->assertNotSoftDeleted($theirs);
    }

    public function test_download_streams_own_image_only(): void
    {
        Http::fake(['https://img.test/*' => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png'])]);
        $user = User::factory()->create();
        $g = Generation::factory()->for($user)->create(['status' => 'succeeded', 'image_url' => 'https://img.test/a.png', 'image_expires_at' => now()->addHour()]);
        $theirs = Generation::factory()->create(['status' => 'succeeded', 'image_url' => 'https://img.test/b.png', 'image_expires_at' => now()->addHour()]);

        $this->actingAs($user)->get("/generations/{$g->id}/download")->assertOk()->assertHeader('content-disposition');
        $this->actingAs($user)->get("/generations/{$theirs->id}/download")->assertNotFound();
    }

    public function test_download_refuses_expired_images(): void
    {
        $user = User::factory()->create();
        $g = Generation::factory()->for($user)->create(['status' => 'succeeded', 'image_url' => 'https://img.test/a.png', 'image_expires_at' => now()->subHour()]);

        $this->actingAs($user)->get("/generations/{$g->id}/download")->assertStatus(410);
    }
}
