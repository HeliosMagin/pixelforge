<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admins_can_see_it(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/report')->assertForbidden();
    }

    public function test_it_buckets_by_utc_completion_day_and_compares_with_pixelforge(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $alice = User::factory()->create(['name' => 'Alice']);

        // Created on the 25th, completed on the 26th: billed on the 26th.
        Generation::factory()->for($alice)->create([
            'status' => GenerationStatus::Succeeded,
            'created_at' => '2026-11-25 23:59:50',
            'completed_at' => '2026-11-26 00:00:10',
            'provider_cost_nanos' => 56_550_000,
            'charged_nanos' => 70_687_500,
        ]);
        Generation::factory()->for($alice)->create(['status' => GenerationStatus::Failed, 'completed_at' => '2026-11-26 01:00:00']);

        Http::fake(['*/usage*' => Http::response([
            'object' => 'usage',
            'start_date' => '2026-11-25',
            'end_date' => '2026-11-26',
            'data' => [
                ['date' => '2026-11-25', 'generations' => 0, 'input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0, 'cost_usd' => '0.000000000'],
                ['date' => '2026-11-26', 'generations' => 1, 'input_text_tokens' => 10, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 1000, 'cost_usd' => '0.056550000'],
            ],
            'total' => ['generations' => 1, 'input_text_tokens' => 10, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 1000, 'cost_usd' => '0.056550000'],
        ])]);

        $this->actingAs($admin)
            ->get('/admin/report?start=2026-11-25&end=2026-11-26')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Report')
                ->where('report.days.0.date', '2026-11-26')
                ->where('report.days.0.provider_nanos', 56_550_000)
                ->where('report.days.0.pixelforge_cost_nanos', 56_550_000)
                ->where('report.days.0.charged_nanos', 70_687_500)
                ->where('report.days.0.profit_nanos', 14_137_500)
                ->where('report.days.1.provider_nanos', 0)
                ->where('report.users.0.user.name', 'Alice')
                ->where('report.total.pixelforge_cost_nanos', 56_550_000)
            );
    }
}
