<?php

namespace App\Http\Controllers\Track;

use App\Http\Controllers\Controller;
use App\Models\SiteVisit;
use Illuminate\Http\Request;

/**
 * POST /api/track — public. Called by the website's js/track.js via sendBeacon
 * (JSON body sent as text/plain so the browser needs no CORS pre-flight).
 * Stores a random visitor id only: no IP address, no cookies.
 */
class TrackController extends Controller
{
    private const BOT = '/bot|crawl|spider|slurp|headless|lighthouse|pagespeed|preview|monitor|uptime|curl|wget|python|axios|java|go-http|facebookexternalhit/i';

    public function store(Request $r)
    {
        try {
            $this->record($r);
        } catch (\Throwable $e) {
            report($e); // tracking must never break anything
        }
        return response()->noContent();
    }

    private function record(Request $r): void
    {
        $ua = (string) $r->userAgent();
        if ($ua === '' || preg_match(self::BOT, $ua)) return;

        $b = json_decode($r->getContent(), true);
        if (!is_array($b)) return;

        $type = $b['type'] ?? '';
        $vid  = (string) ($b['vid'] ?? '');
        $sid  = (string) ($b['sid'] ?? '');
        $path = (string) ($b['path'] ?? '');
        if (!in_array($type, ['view', 'ping'], true)
            || !preg_match('/^[A-Za-z0-9\-]{8,64}$/', $vid)
            || !preg_match('/^[A-Za-z0-9\-]{8,64}$/', $sid)
            || $path === '' || $path[0] !== '/') return;

        $path = substr(preg_replace('/[?#].*$/', '', $path), 0, 255);
        $now  = now();

        if ($type === 'ping') {
            $id = SiteVisit::where('session_id', $sid)->where('path', $path)->orderByDesc('id')->value('id');
            if ($id) SiteVisit::where('id', $id)->update(['last_seen_at' => $now]);
            return;
        }

        SiteVisit::create([
            'visitor_id'       => $vid,
            'session_id'       => $sid,
            'path'             => $path,
            'referrer'         => $this->source((string) ($b['ref'] ?? '')),
            'device'           => $this->device($ua),
            'browser'          => $this->browser($ua),
            'os'               => $this->os($ua),
            'country'          => $this->country($r),
            'is_new'           => !SiteVisit::where('visitor_id', $vid)->exists(),
            'first_in_session' => !SiteVisit::where('session_id', $sid)->exists(),
            'created_at'       => $now,
            'last_seen_at'     => $now,
        ]);
    }

    private function device(string $ua): string
    {
        if (preg_match('/ipad|tablet|kindle|silk/i', $ua) || (preg_match('/android/i', $ua) && !preg_match('/mobile/i', $ua))) return 'tablet';
        if (preg_match('/mobi|iphone|ipod|android/i', $ua)) return 'mobile';
        return 'desktop';
    }

    private function browser(string $ua): string
    {
        if (preg_match('/edg\//i', $ua)) return 'Edge';
        if (preg_match('/opr\/|opera/i', $ua)) return 'Opera';
        if (preg_match('/firefox|fxios/i', $ua)) return 'Firefox';
        if (preg_match('/chrome|crios/i', $ua)) return 'Chrome';
        if (preg_match('/safari/i', $ua)) return 'Safari';
        return 'Other';
    }

    private function os(string $ua): string
    {
        if (preg_match('/windows/i', $ua)) return 'Windows';
        if (preg_match('/android/i', $ua)) return 'Android';
        if (preg_match('/iphone|ipad|ipod/i', $ua)) return 'iOS';
        if (preg_match('/mac os|macintosh/i', $ua)) return 'macOS';
        if (preg_match('/linux|cros/i', $ua)) return 'Linux';
        return 'Other';
    }

    /** Friendly traffic source; null for direct visits and for navigation inside our own site. */
    private function source(string $ref): ?string
    {
        $host = strtolower((string) parse_url($ref, PHP_URL_HOST));
        if ($host === '') return null;
        $host = preg_replace('/^www\./', '', $host);

        $own = array_filter(array_map(
            fn ($u) => preg_replace('/^www\./', '', strtolower((string) parse_url(trim($u), PHP_URL_HOST))),
            explode(',', (string) (env('CORS_ORIGINS') ?: env('FRONTEND_URL')))
        ));
        if (str_contains($host, 'upaadhyay') || str_contains($host, 'upadyay') || in_array($host, $own, true)) return null;

        $map = [
            'google' => 'Google', 'bing' => 'Bing', 'duckduckgo' => 'DuckDuckGo', 'yahoo' => 'Yahoo',
            'facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn',
            'whatsapp' => 'WhatsApp', 'youtube' => 'YouTube', 't.co' => 'X / Twitter',
            'twitter' => 'X / Twitter', 'x.com' => 'X / Twitter', 'telegram' => 'Telegram', 't.me' => 'Telegram',
        ];
        foreach ($map as $needle => $label) {
            if (str_contains($host, $needle)) return $label;
        }
        return substr($host, 0, 120);
    }

    private function country(Request $r): ?string
    {
        foreach (['CF-IPCountry', 'CloudFront-Viewer-Country', 'X-Vercel-IP-Country'] as $h) {
            $c = strtoupper((string) $r->header($h));
            if (preg_match('/^[A-Z]{2}$/', $c) && !in_array($c, ['XX', 'T1'], true)) return $c;
        }
        return null;
    }
}
