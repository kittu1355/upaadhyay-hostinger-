<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteVisit;
use App\Support\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/admin/analytics?range=1|7|30 — feeds the admin "Live traffic" tab.
 * "Today" / hourly / daily buckets follow Indian time (IST, UTC+5:30) while the
 * database keeps its normal UTC timestamps, so nothing existing is touched.
 */
class AnalyticsController extends Controller
{
    private const TZ = 'Asia/Kolkata';
    private const OFFSET_MIN = 330;

    public function index(Request $r)
    {
        $range = in_array((int) $r->query('range'), [1, 7, 30], true) ? (int) $r->query('range') : 7;

        $now      = now();
        $db       = fn (Carbon $c) => $c->copy()->setTimezone(config('app.timezone')); // IST moment -> DB timezone
        $todayIst = Carbon::now(self::TZ)->startOfDay();
        $today    = $db($todayIst);
        $since    = $db($todayIst->copy()->subDays($range - 1));
        $liveCut  = $now->copy()->subSeconds(75); // heartbeat is every 30 s

        $agg = fn ($q) => [
            'visitors'     => (clone $q)->distinct()->count('visitor_id'),
            'views'        => (clone $q)->count(),
            'sessions'     => (clone $q)->distinct()->count('session_id'),
            'new_visitors' => (clone $q)->where('is_new', true)->count(),
        ];
        $todayAgg = $agg(SiteVisit::where('created_at', '>=', $today));
        $yAgg     = $agg(SiteVisit::whereBetween('created_at', [$today->copy()->subDay(), $now->copy()->subDay()]));

        // Last 30 IST days, missing days filled with zero
        $rows = SiteVisit::where('created_at', '>=', $db($todayIst->copy()->subDays(29)))
            ->selectRaw($this->dayExpr().' as d, COUNT(*) as views, COUNT(DISTINCT visitor_id) as visitors')
            ->groupBy('d')->get()->keyBy('d');
        $daily = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = $todayIst->copy()->subDays($i)->toDateString();
            $daily[] = [
                'date'     => $d,
                'visitors' => (int) ($rows[$d]->visitors ?? 0),
                'views'    => (int) ($rows[$d]->views ?? 0),
            ];
        }

        $hourly = SiteVisit::where('created_at', '>=', $today)
            ->selectRaw($this->hourExpr().' as hr, COUNT(*) as views, COUNT(DISTINCT visitor_id) as visitors')
            ->groupBy('hr')->orderBy('hr')->get()
            ->map(fn ($x) => ['hour' => (int) $x->hr, 'views' => (int) $x->views, 'visitors' => (int) $x->visitors])
            ->values();

        $in = fn () => SiteVisit::where('created_at', '>=', $since);

        return ApiResponse::success([
            'generated_at'     => $now->toIso8601String(),
            'range'            => $range,
            'live_now'         => SiteVisit::where('last_seen_at', '>=', $liveCut)->distinct()->count('session_id'),
            'live_pages'       => SiteVisit::where('last_seen_at', '>=', $liveCut)
                ->selectRaw('path, COUNT(DISTINCT session_id) as count')
                ->groupBy('path')->orderByDesc('count')->limit(5)->get()
                ->map(fn ($x) => ['path' => $x->path, 'count' => (int) $x->count])->values(),
            'today'            => $todayAgg,
            'yesterday_so_far' => $yAgg,
            'daily'            => $daily,
            'hourly'           => $hourly,
            'pages'            => $in()->selectRaw('path, COUNT(*) as views, COUNT(DISTINCT visitor_id) as visitors')
                ->groupBy('path')->orderByDesc('views')->limit(8)->get()
                ->map(fn ($x) => ['path' => $x->path, 'views' => (int) $x->views, 'visitors' => (int) $x->visitors])->values(),
            'referrers'        => $in()->where('first_in_session', true)
                ->selectRaw("COALESCE(referrer, 'Direct') as source, COUNT(DISTINCT visitor_id) as visitors")
                ->groupBy('source')->orderByDesc('visitors')->limit(8)->get()
                ->map(fn ($x) => ['source' => $x->source, 'visitors' => (int) $x->visitors])->values(),
            'devices'          => $in()->selectRaw('device as name, COUNT(DISTINCT session_id) as count')
                ->groupBy('device')->orderByDesc('count')->get()
                ->map(fn ($x) => ['name' => ucfirst($x->name), 'count' => (int) $x->count])->values(),
            'countries'        => $in()->whereNotNull('country')
                ->selectRaw('country as code, COUNT(DISTINCT visitor_id) as visitors')
                ->groupBy('country')->orderByDesc('visitors')->limit(8)->get()
                ->map(fn ($c) => ['name' => $this->countryName($c->code), 'visitors' => (int) $c->visitors])->values(),
            'recent'           => SiteVisit::orderByDesc('id')->limit(15)->get()->map(fn ($v) => [
                'at'      => $v->created_at->toIso8601String(),
                'path'    => $v->path,
                'device'  => ucfirst($v->device),
                'browser' => $v->browser,
                'country' => $v->country ? $this->countryName($v->country) : null,
                'ref'     => $v->referrer ?: 'Direct',
            ])->values(),
        ]);
    }

    /** created_at shifted to IST, as a SQL expression (MySQL/MariaDB in production, SQLite in tests). */
    private function istExpr(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "datetime(created_at, '+".self::OFFSET_MIN." minutes')"
            : 'DATE_ADD(created_at, INTERVAL '.self::OFFSET_MIN.' MINUTE)';
    }

    private function dayExpr(): string
    {
        return 'DATE('.$this->istExpr().')';
    }

    private function hourExpr(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(strftime('%H', ".$this->istExpr().') AS INTEGER)'
            : 'HOUR('.$this->istExpr().')';
    }

    private function countryName(string $code): string
    {
        if (class_exists(\Locale::class)) {
            return \Locale::getDisplayRegion('-'.$code, 'en') ?: $code;
        }
        return $code;
    }
}
