<?php

namespace Oliweb\StatamicAnalytics\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class AnalyticsDashboardController
{
    public function index()
    {
        $resolvedProvider = config('statamic-analytics.geolocation.provider')
            ?? (config('statamic-analytics.geolocation.enabled', true) ? 'ip-api' : 'disabled');

        $geoWarning = $resolvedProvider === 'maxmind'
            && (empty(config('statamic-analytics.geolocation.maxmind.account_id'))
                || empty(config('statamic-analytics.geolocation.maxmind.license_key')));

        return Inertia::render('StatamicAnalytics/Dashboard', [
            'config' => [
                'refreshInterval'    => config('statamic-analytics.dashboard.refresh_interval', 300),
                'cacheDuration'      => config('statamic-analytics.geolocation.cache_duration', 1440),
                'rateLimit'          => config('statamic-analytics.geolocation.ip_api.rate_limit', config('statamic-analytics.geolocation.rate_limit', 45)),
                'processingFrequency'=> config('statamic-analytics.processing.frequency', 15),
                'geoProvider'        => $resolvedProvider,
                'geoWarning'         => $geoWarning,
                'routes' => [
                    'data'       => cp_route('statamic-analytics.data'),
                    'export'     => cp_route('statamic-analytics.export'),
                    'clearCache' => cp_route('statamic-analytics.clear-cache'),
                    'geoStats'   => cp_route('statamic-analytics.geo-stats'),
                    'realtime'   => cp_route('statamic-analytics.realtime'),
                ],
            ],
            'translations' => trans('statamic-analytics::messages'),
        ]);
    }

    public function getData(Request $request)
    {
        $range = $request->input('range', '7days');
        $startDate = $this->getStartDate($range, $request);
        $endDate = Carbon::now();

        if ($range === 'custom') {
            if ($request->input('start_date') && $request->input('end_date')) {
                $startDate = Carbon::parse($request->input('start_date'));
                $endDate = Carbon::parse($request->input('end_date'));
            }
        }

        $periodLength = $startDate->diffInDays($endDate, true);
        $previousStartDate = $startDate->copy()->subDays($periodLength);
        $previousEndDate = $startDate->copy()->subDay();

        $data = [
            'overview' => array_merge(
                $this->getOverviewStats($startDate, $endDate),
                ['comparisons' => $this->getComparisons($startDate, $endDate, $previousStartDate, $previousEndDate)]
            ),
            'engagement'       => $this->getEngagementMetrics($startDate, $endDate),
            'page_views'       => $this->getPageViewsData($startDate, $endDate),
            'device_stats'     => $this->getDeviceStats($startDate, $endDate),
            'country_stats'    => $this->getCountryStats($startDate, $endDate),
            'browser_stats'    => $this->getBrowserStats($startDate, $endDate),
            'top_pages'        => $this->getTopPages($startDate, $endDate),
            'user_flow'        => $this->getUserFlow($startDate, $endDate),
            'referrer_stats'   => $this->getReferrerStats($startDate, $endDate),
            'platform_stats'   => $this->getPlatformStats($startDate, $endDate),
            'city_stats'       => $this->getCityStats($startDate, $endDate),
            'heatmap_data'     => $this->getHeatmapData($startDate, $endDate),
            'new_vs_returning' => $this->getNewVsReturningTrend($startDate, $endDate),
            'session_depth'    => $this->getSessionDepth($startDate, $endDate),
        ];

        return response()->json($data);
    }

    public function getRealTimeVisitors()
    {
        $threshold = Carbon::now()->subMinutes(30);

        $totals = DB::table('statamic_analytics_page_views')
            ->where('visited_at', '>=', $threshold)
            ->select(
                DB::raw('COUNT(DISTINCT session_id) as active_sessions'),
                DB::raw('COUNT(DISTINCT visitor_id) as active_visitors'),
                DB::raw('COUNT(*) as page_views')
            )
            ->first();

        $breakdowns = [];
        foreach ([5, 15, 30] as $minutes) {
            $since = Carbon::now()->subMinutes($minutes);
            $breakdowns["last_{$minutes}min"] = DB::table('statamic_analytics_page_views')
                ->where('visited_at', '>=', $since)
                ->select(
                    DB::raw('COUNT(DISTINCT session_id) as active_sessions'),
                    DB::raw('COUNT(DISTINCT visitor_id) as active_visitors'),
                    DB::raw('COUNT(*) as page_views')
                )
                ->first();
        }

        return response()->json([
            'totals'     => $totals,
            'breakdowns' => $breakdowns,
        ]);
    }

    protected function getStartDate($range, Request $request)
    {
        if ($request->input('start_date')) {
            return Carbon::parse($request->input('start_date'));
        }

        return match($range) {
            '24hours' => Carbon::now()->subDay(),
            '7days'   => Carbon::now()->subDays(7),
            '30days'  => Carbon::now()->subDays(30),
            default   => Carbon::now()->subDays(7),
        };
    }

    protected function getOverviewStats($startDate, $endDate)
    {
        $totalVisits = DB::table('statamic_analytics_page_views')
            ->whereBetween('visited_at', [$startDate, $endDate])
            ->count();

        $uniqueVisitors = DB::table('statamic_analytics_page_views')
            ->whereBetween('visited_at', [$startDate, $endDate])
            ->where('is_new_visitor', true)
            ->count();

        $bounceRate = DB::table('statamic_analytics_page_views')
            ->whereBetween('visited_at', [$startDate, $endDate])
            ->where('is_new_page_visit', true)
            ->count() / ($totalVisits ?: 1);

        return [
            'total_visits'    => $totalVisits,
            'unique_visitors' => $uniqueVisitors,
            'avg_time_on_site'=> $this->calculateAverageTimeOnSite($startDate, $endDate),
            'bounce_rate'     => $bounceRate,
        ];
    }

    protected function getPageViewsData($startDate, $endDate)
    {
        return DB::table('statamic_analytics_page_views')
            ->select(
                DB::raw('DATE(visited_at) as date'),
                DB::raw('COUNT(*) as total_views'),
                DB::raw('COUNT(CASE WHEN is_new_page_visit = 1 THEN 1 END) as unique_views')
            )
            ->whereBetween('visited_at', [$startDate, $endDate])
            ->groupBy('date')
            ->orderBy('date')
            ->get();
    }

    protected function getTopPages($startDate, $endDate, $limit = 10)
    {
        $pages = DB::table('statamic_analytics_page_views as a')
            ->select(
                'page_url',
                DB::raw('COUNT(*) as views'),
                DB::raw('COUNT(CASE WHEN is_new_page_visit = 1 THEN 1 END) as unique_views'),
                DB::raw('COUNT(CASE WHEN is_new_page_visit = 1 AND is_new_visitor = 1 THEN 1 END) / COUNT(CASE WHEN is_new_page_visit = 1 THEN 1 END) as bounce_rate')
            )
            ->whereBetween('visited_at', [$startDate, $endDate])
            ->groupBy('page_url')
            ->orderByDesc('views')
            ->limit($limit)
            ->get();

        foreach ($pages as $page) {
            $sessions = DB::table('statamic_analytics_page_views')
                ->select('session_id', 'visited_at')
                ->where('page_url', $page->page_url)
                ->whereBetween('visited_at', [$startDate, $endDate])
                ->orderBy('session_id')
                ->orderBy('visited_at')
                ->get()
                ->groupBy('session_id');

            $totalTime = 0;
            $timeCount = 0;

            foreach ($sessions as $sessionVisits) {
                $visits = $sessionVisits->values();
                for ($i = 0; $i < count($visits) - 1; $i++) {
                    $currentVisit = Carbon::parse($visits[$i]->visited_at);
                    $nextVisit = Carbon::parse($visits[$i + 1]->visited_at);
                    $timeDiff = $nextVisit->diffInSeconds($currentVisit, true);
                    if ($timeDiff < 3600) {
                        $totalTime += $timeDiff;
                        $timeCount++;
                    }
                }
            }

            $page->avg_time = $timeCount > 0 ? $totalTime / $timeCount : 0;

            $totalPageViews = $page->views;
            $exits = DB::table('statamic_analytics_page_views as a')
                ->where('page_url', $page->page_url)
                ->whereBetween('visited_at', [$startDate, $endDate])
                ->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('statamic_analytics_page_views as b')
                        ->whereRaw('a.session_id = b.session_id')
                        ->whereRaw('a.visited_at < b.visited_at');
                })
                ->count();

            $page->exit_rate = $totalPageViews > 0 ? $exits / $totalPageViews : 0;
        }

        return $pages;
    }

    protected function getDeviceStats($startDate, $endDate)
    {
        return DB::table('statamic_analytics_aggregates')
            ->where('dimension', 'device_type')
            ->where('type', 'daily')
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->select('dimension_value', DB::raw('SUM(total_visits) as total'))
            ->groupBy('dimension_value')
            ->get();
    }

    protected function getCountryStats($startDate, $endDate)
    {
        return DB::table('statamic_analytics_aggregates')
            ->where('dimension', 'country_code')
            ->where('type', 'daily')
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->select('dimension_value', DB::raw('SUM(total_visits) as total'))
            ->groupBy('dimension_value')
            ->orderByDesc('total')
            ->limit(10)
            ->get();
    }

    protected function getBrowserStats($startDate, $endDate)
    {
        return DB::table('statamic_analytics_aggregates')
            ->where('dimension', 'browser')
            ->where('type', 'daily')
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->select('dimension_value', DB::raw('SUM(total_visits) as total'))
            ->groupBy('dimension_value')
            ->orderByDesc('total')
            ->get();
    }

    protected function calculateAverageTimeOnSite($startDate, $endDate)
    {
        $sessions = DB::table('statamic_analytics_page_views')
            ->select('session_id', 'visited_at')
            ->whereBetween('visited_at', [$startDate, $endDate])
            ->whereNotNull('session_id')
            ->orderBy('visited_at')
            ->get()
            ->groupBy('session_id');

        $totalTime = 0;
        $sessionCount = 0;

        foreach ($sessions as $sessionVisits) {
            if ($sessionVisits->count() > 1) {
                $firstVisit = Carbon::parse($sessionVisits->first()->visited_at);
                $lastVisit = Carbon::parse($sessionVisits->last()->visited_at);
                $totalTime += $firstVisit->diffInSeconds($lastVisit, true);
                $sessionCount++;
            }
        }

        return $sessionCount > 0 ? round($totalTime / $sessionCount) : 0;
    }

    protected function getReferrerStats($startDate, $endDate)
    {
        $sources = DB::table('statamic_analytics_page_views')
            ->select(
                DB::raw("CASE
                    WHEN referrer_url IS NULL OR referrer_url = '' THEN 'direct'
                    WHEN (referrer_url LIKE '%google.%' OR referrer_url LIKE '%bing.%' OR referrer_url LIKE '%duckduckgo.%' OR referrer_url LIKE '%yahoo.%' OR referrer_url LIKE '%baidu.%') THEN 'search'
                    WHEN (referrer_url LIKE '%facebook.%' OR referrer_url LIKE '%twitter.%' OR referrer_url LIKE '%linkedin.%' OR referrer_url LIKE '%instagram.%' OR referrer_url LIKE '%youtube.%') THEN 'social'
                    ELSE 'referral'
                END as source"),
                DB::raw('COUNT(*) as total')
            )
            ->whereBetween('visited_at', [$startDate, $endDate])
            ->groupBy('source')
            ->get();

        $topDomains = DB::table('statamic_analytics_page_views')
            ->select('referrer_url')
            ->whereBetween('visited_at', [$startDate, $endDate])
            ->whereNotNull('referrer_url')
            ->where('referrer_url', '!=', '')
            ->get()
            ->groupBy(function ($row) {
                return explode('?', parse_url($row->referrer_url, PHP_URL_HOST) ?? $row->referrer_url)[0];
            })
            ->map(fn ($group, $domain) => (object) ['domain' => $domain, 'total' => $group->count()])
            ->sortByDesc('total')
            ->take(10)
            ->values();

        return [
            'sources'     => $sources,
            'top_domains' => $topDomains,
        ];
    }

    protected function getPlatformStats($startDate, $endDate)
    {
        return DB::table('statamic_analytics_page_views')
            ->select('platform', DB::raw('COUNT(*) as total'))
            ->whereBetween('visited_at', [$startDate, $endDate])
            ->whereNotNull('platform')
            ->where('platform', '!=', '')
            ->groupBy('platform')
            ->orderByDesc('total')
            ->get();
    }

    protected function getCityStats($startDate, $endDate)
    {
        return DB::table('statamic_analytics_page_views')
            ->select('city', 'country_name', DB::raw('COUNT(*) as total'))
            ->whereBetween('visited_at', [$startDate, $endDate])
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->groupBy('city', 'country_name')
            ->orderByDesc('total')
            ->limit(10)
            ->get();
    }

    protected function getHeatmapData($startDate, $endDate)
    {
        $rows = DB::table('statamic_analytics_page_views')
            ->select(
                DB::raw("CAST(strftime('%H', visited_at) AS INTEGER) as hour"),
                DB::raw("CAST(strftime('%w', visited_at) AS INTEGER) + 1 as day"),
                DB::raw('COUNT(*) as count')
            )
            ->whereBetween('visited_at', [$startDate, $endDate])
            ->groupBy('hour', 'day')
            ->get();

        // Build a 7×24 matrix (day 1=Sunday ... 7=Saturday, reindex to 0=Monday…6=Sunday)
        $matrix = [];
        foreach ($rows as $row) {
            // DAYOFWEEK: 1=Sun,2=Mon,...,7=Sat → remap to 0=Mon…6=Sun
            $day = (($row->day + 5) % 7);
            $matrix[] = [
                'day'   => $day,
                'hour'  => (int) $row->hour,
                'count' => (int) $row->count,
            ];
        }

        return $matrix;
    }

    protected function getNewVsReturningTrend($startDate, $endDate)
    {
        return DB::table('statamic_analytics_page_views')
            ->select(
                DB::raw('DATE(visited_at) as date'),
                DB::raw('SUM(CASE WHEN is_new_visitor = 1 THEN 1 ELSE 0 END) as new_visitors'),
                DB::raw('SUM(CASE WHEN is_new_visitor = 0 THEN 1 ELSE 0 END) as returning_visitors')
            )
            ->whereBetween('visited_at', [$startDate, $endDate])
            ->groupBy('date')
            ->orderBy('date')
            ->get();
    }

    protected function getSessionDepth($startDate, $endDate)
    {
        $sessions = DB::table('statamic_analytics_page_views')
            ->select('session_id', DB::raw('COUNT(*) as pages'))
            ->whereBetween('visited_at', [$startDate, $endDate])
            ->whereNotNull('session_id')
            ->groupBy('session_id')
            ->pluck('pages');

        $buckets = [
            '1'     => 0,
            '2-3'   => 0,
            '4-5'   => 0,
            '6-10'  => 0,
            '10+'   => 0,
        ];

        foreach ($sessions as $pages) {
            if ($pages == 1) {
                $buckets['1']++;
            } elseif ($pages <= 3) {
                $buckets['2-3']++;
            } elseif ($pages <= 5) {
                $buckets['4-5']++;
            } elseif ($pages <= 10) {
                $buckets['6-10']++;
            } else {
                $buckets['10+']++;
            }
        }

        return collect($buckets)->map(fn($count, $label) => ['label' => $label, 'count' => $count])->values();
    }

    public function export(Request $request)
    {
        $startDate = $this->getStartDate($request->input('range'), $request);
        $endDate = $request->input('end_date') ? Carbon::parse($request->input('end_date')) : Carbon::now();

        $data = DB::table('statamic_analytics_page_views')
            ->whereBetween('visited_at', [$startDate, $endDate])
            ->get();

        return response()->streamDownload(function () use ($data) {
            $output = fopen('php://output', 'w');

            fputcsv($output, [
                'Page URL',
                'IP Address',
                'Country',
                'City',
                'Device Type',
                'Browser',
                'Platform',
                'Visited At'
            ]);

            foreach ($data as $row) {
                fputcsv($output, [
                    $row->page_url,
                    $row->ip_address,
                    $row->country_name,
                    $row->city,
                    $row->device_type,
                    $row->browser,
                    $row->platform,
                    $row->visited_at
                ]);
            }

            fclose($output);
        }, 'analytics-export-' . Carbon::now()->format('Y-m-d') . '.csv');
    }

    public function getGeolocationStats()
    {
        $stats = \Oliweb\StatamicAnalytics\Middleware\TrackPageVisit::getGeolocationStats();
        return response()->json($stats);
    }

    public function clearGeolocationCache()
    {
        \Oliweb\StatamicAnalytics\Middleware\TrackPageVisit::clearGeolocationCache();
        return response()->json(['message' => 'Cache cleared successfully']);
    }

    protected function getComparisons($startDate, $endDate, $previousStartDate, $previousEndDate)
    {
        $current  = $this->getOverviewStats($startDate, $endDate);
        $previous = $this->getOverviewStats($previousStartDate, $previousEndDate);

        return [
            'total_visits'    => $this->calculatePercentageChange($previous['total_visits'],    $current['total_visits']),
            'unique_visitors' => $this->calculatePercentageChange($previous['unique_visitors'], $current['unique_visitors']),
            'bounce_rate'     => $this->calculatePercentageChange($previous['bounce_rate'],     $current['bounce_rate']),
        ];
    }

    protected function calculatePercentageChange($previous, $current)
    {
        if ($previous == 0) return 100;
        return round((($current - $previous) / $previous) * 100, 1);
    }

    protected function getEngagementMetrics($startDate, $endDate)
    {
        $newVisitors = DB::table('statamic_analytics_page_views')
            ->whereBetween('visited_at', [$startDate, $endDate])
            ->where('is_new_visitor', true)
            ->count();

        $returningVisitors = DB::table('statamic_analytics_page_views')
            ->whereBetween('visited_at', [$startDate, $endDate])
            ->where('is_new_visitor', false)
            ->count();

        $sessionCount = DB::table('statamic_analytics_page_views')
            ->whereBetween('visited_at', [$startDate, $endDate])
            ->whereNotNull('session_id')
            ->distinct()
            ->count('session_id');

        $totalPageViews = DB::table('statamic_analytics_page_views')
            ->whereBetween('visited_at', [$startDate, $endDate])
            ->count();

        $pagesPerSession = $sessionCount > 0 ? $totalPageViews / $sessionCount : 0;

        return [
            'new_visitors'        => $newVisitors,
            'returning_visitors'  => $returningVisitors,
            'pages_per_session'   => $pagesPerSession,
            'avg_session_duration'=> $this->calculateAverageTimeOnSite($startDate, $endDate),
        ];
    }

    protected function getUserFlow($startDate, $endDate)
    {
        $entryPages = DB::table('statamic_analytics_page_views')
            ->select('page_url', DB::raw('COUNT(*) as count'))
            ->whereBetween('visited_at', [$startDate, $endDate])
            ->where('is_new_page_visit', true)
            ->groupBy('page_url')
            ->orderByDesc('count')
            ->limit(5)
            ->get();

        $engagedPages = collect();
        $pages = DB::table('statamic_analytics_page_views')
            ->select('page_url', 'session_id', 'visited_at')
            ->whereBetween('visited_at', [$startDate, $endDate])
            ->whereNotNull('session_id')
            ->orderBy('session_id')
            ->orderBy('visited_at')
            ->get()
            ->groupBy('page_url');

        foreach ($pages as $url => $visits) {
            $totalTime = 0;
            $timeCount = 0;

            $sessionVisits = $visits->groupBy('session_id');
            foreach ($sessionVisits as $sessionVisit) {
                $orderedVisits = $sessionVisit->values();
                for ($i = 0; $i < count($orderedVisits) - 1; $i++) {
                    $currentVisit = Carbon::parse($orderedVisits[$i]->visited_at);
                    $nextVisit = Carbon::parse($orderedVisits[$i + 1]->visited_at);
                    $timeDiff = $nextVisit->diffInSeconds($currentVisit, true);
                    if ($timeDiff < 3600) {
                        $totalTime += $timeDiff;
                        $timeCount++;
                    }
                }
            }

            if ($timeCount > 0) {
                $engagedPages->push((object)[
                    'url'      => $url,
                    'avg_time' => $totalTime / $timeCount,
                ]);
            }
        }

        $engagedPages = $engagedPages->sortByDesc('avg_time')->take(5)->values();

        $exitPages = collect();
        $pages = DB::table('statamic_analytics_page_views')
            ->select('page_url', 'session_id', 'visited_at')
            ->whereBetween('visited_at', [$startDate, $endDate])
            ->get()
            ->groupBy('page_url');

        foreach ($pages as $url => $visits) {
            $totalVisits = $visits->count();
            $exits = $visits->filter(function ($visit) use ($visits) {
                return !$visits->where('session_id', $visit->session_id)
                    ->where('visited_at', '>', $visit->visited_at)
                    ->count();
            })->count();

            $exitPages->push((object)[
                'url'       => $url,
                'exits'     => $exits,
                'exit_rate' => $totalVisits > 0 ? $exits / $totalVisits : 0,
            ]);
        }

        $exitPages = $exitPages->sortByDesc('exits')->take(5)->values();

        return [
            'entry_pages'   => $entryPages,
            'engaged_pages' => $engagedPages,
            'exit_pages'    => $exitPages,
        ];
    }
}
