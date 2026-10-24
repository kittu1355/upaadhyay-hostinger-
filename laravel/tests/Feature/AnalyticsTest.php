<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\MakesAccounts;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase, MakesAccounts;

    private function hit(string $type, string $path = '/pages/jobs.html'): void
    {
        $this->call('POST', '/api/track', [], [], [], [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile Safari/604.1',
            'CONTENT_TYPE'    => 'text/plain;charset=UTF-8',
        ], json_encode(['type' => $type, 'vid' => 'visitor-0001', 'sid' => 'session-0001', 'path' => $path, 'ref' => 'https://www.google.com/', 'w' => 390]))
            ->assertNoContent();
    }

    public function test_tracked_visit_shows_up_for_admin_only(): void
    {
        $this->hit('view');
        $this->hit('ping');

        Sanctum::actingAs($this->candidate());
        $this->getJson('/api/admin/analytics')->assertForbidden();

        Sanctum::actingAs($this->admin());
        $this->getJson('/api/admin/analytics?range=7')->assertOk()
            ->assertJsonPath('data.live_now', 1)
            ->assertJsonPath('data.today.visitors', 1)
            ->assertJsonPath('data.today.views', 1)
            ->assertJsonPath('data.pages.0.path', '/pages/jobs.html')
            ->assertJsonPath('data.referrers.0.source', 'Google')
            ->assertJsonPath('data.devices.0.name', 'Mobile');
    }

    public function test_bots_and_bad_payloads_are_ignored(): void
    {
        $this->call('POST', '/api/track', [], [], [], ['HTTP_USER_AGENT' => 'Googlebot/2.1', 'CONTENT_TYPE' => 'text/plain'],
            json_encode(['type' => 'view', 'vid' => 'visitor-0002', 'sid' => 'session-0002', 'path' => '/']))->assertNoContent();
        $this->call('POST', '/api/track', [], [], [], ['HTTP_USER_AGENT' => 'Mozilla/5.0', 'CONTENT_TYPE' => 'text/plain'], 'not json')->assertNoContent();

        Sanctum::actingAs($this->admin());
        $this->getJson('/api/admin/analytics')->assertOk()->assertJsonPath('data.today.views', 0);
    }
}
