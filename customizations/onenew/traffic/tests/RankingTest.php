<?php

namespace Tests\Feature\Onenew;

use App\Models\Server;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\User;
use App\Onenew\Traffic\RankingController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class RankingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Server $server;
    private string $endpoint;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Asia/Shanghai']);
        $this->travelTo(Carbon::parse('2026-11-20 01:35:05', 'Asia/Shanghai'));
        $this->user = User::create(['email' => Str::uuid().'@example.test', 'password' => 'unused',
            'uuid' => (string) Str::uuid(), 'token' => bin2hex(random_bytes(16)), 'is_admin' => true]);
        $this->server = Server::create(['name' => 'ranking-node', 'type' => 'vmess', 'host' => 'example.test',
            'port' => 443, 'server_port' => 443, 'rate' => 1, 'enabled' => true, 'group_ids' => []]);
        $route = collect(app('router')->getRoutes()->getRoutes())->first(fn ($route) =>
            $route->getActionName() === RankingController::class.'@getTrafficRank');
        $this->assertNotNull($route, 'The native authenticated ranking route must use the panel-time controller');
        $this->endpoint = '/'.$route->uri();
    }

    private function record(string $day, int $bytes, string $timezone = 'Asia/Shanghai'): void
    {
        $at = Carbon::parse($day, $timezone)->startOfDay()->timestamp;
        StatUser::create(['user_id' => $this->user->id, 'server_rate' => 1, 'record_type' => 'd',
            'record_at' => $at, 'u' => $bytes, 'd' => 0]);
        StatServer::create(['server_id' => $this->server->id, 'server_type' => 'vmess', 'record_type' => 'd',
            'record_at' => $at, 'u' => $bytes, 'd' => 0]);
    }

    public function test_today_includes_midnight_despite_a_one_hour_shifted_client_timestamp(): void
    {
        $this->record('2026-11-19', 100);
        $this->record('2026-11-20', 300);
        $this->record('2026-11-21', 900);
        Sanctum::actingAs($this->user);
        foreach (['node', 'user'] as $type) {
            $this->getJson($this->endpoint.'?'.http_build_query(['type' => $type, 'period' => 'today',
                'start_time' => 1795107600, 'end_time' => 1795194000]))
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.value', 300)
                ->assertJsonPath('data.0.previousValue', 100)->assertJsonPath('data.0.change', 200);
        }
    }

    public function test_recent_periods_use_inclusive_calendar_days_and_an_exclusive_end(): void
    {
        $this->record('2026-10-21', 10000);
        $this->record('2026-10-22', 1000);
        $this->record('2026-11-13', 100);
        $this->record('2026-11-14', 10);
        $this->record('2026-11-20', 1);
        $this->record('2026-11-21', 90000);
        Sanctum::actingAs($this->user);
        foreach (['node', 'user'] as $type) {
            foreach (['last7days' => 11, 'last30days' => 1111] as $period => $bytes) {
                $this->getJson($this->endpoint.'?'.http_build_query(['type' => $type, 'period' => $period]))
                    ->assertOk()->assertJsonPath('data.0.value', $bytes);
            }
        }
    }

    public function test_custom_dates_cover_the_selected_day_and_do_not_include_the_following_day(): void
    {
        $this->record('2026-11-18', 100);
        $this->record('2026-11-19', 200);
        $this->record('2026-11-20', 900);
        Sanctum::actingAs($this->user);
        foreach (['node', 'user'] as $type) {
            $this->getJson($this->endpoint.'?'.http_build_query(['type' => $type, 'period' => 'custom',
                'start_date' => '2026-11-19', 'end_date' => '2026-11-19']))
                ->assertOk()->assertJsonPath('data.0.value', 200)->assertJsonPath('data.0.previousValue', 100);
        }
    }

    public function test_panel_timezone_with_daylight_saving_keeps_previous_calendar_day_comparison(): void
    {
        config(['app.timezone' => 'America/New_York']);
        $this->record('2026-10-31', 100, 'America/New_York');
        $this->record('2026-11-01', 500, 'America/New_York');
        $this->record('2026-11-02', 900, 'America/New_York');
        Sanctum::actingAs($this->user);
        $this->getJson($this->endpoint.'?'.http_build_query(['type' => 'user', 'period' => 'custom',
            'start_date' => '2026-11-01', 'end_date' => '2026-11-01']))
            ->assertOk()->assertJsonPath('data.0.value', 500)->assertJsonPath('data.0.previousValue', 100);
    }

    public function test_ranking_requires_authentication_and_empty_results_remain_arrays(): void
    {
        $this->getJson($this->endpoint.'?type=node&period=today')->assertForbidden();
        Sanctum::actingAs($this->user);
        $this->getJson($this->endpoint.'?type=node&period=today')->assertOk()->assertJsonPath('data', []);
        $this->getJson($this->endpoint.'?type=user&period=custom&start_date=2026-11-20&end_date=2026-11-19')
            ->assertUnprocessable();
    }

    public function test_existing_timestamp_clients_are_still_supported(): void
    {
        // The legacy ranking action does not use its constructor's Redis-backed service.
        $this->mock(\App\Services\StatisticalService::class);
        $this->record('2026-11-20', 300);
        Sanctum::actingAs($this->user);
        $this->getJson($this->endpoint.'?'.http_build_query(['type' => 'node',
            'start_time' => 1795104000, 'end_time' => 1795190399]))
            ->assertOk()->assertJsonPath('data.0.value', 300);
    }
}
