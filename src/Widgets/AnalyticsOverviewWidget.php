<?php

namespace Oliweb\StatamicAnalytics\Widgets;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Statamic\Widgets\Widget;

class AnalyticsOverviewWidget extends Widget
{
    protected static $handle = 'analytics_overview';

    public function html()
    {
        if (!auth()->user()?->can('analytics.view')) {
            return '';
        }

        try {
            $todayVisits = DB::table('statamic_analytics_page_views')
                ->where('visited_at', '>=', Carbon::today())->count();
            $totalVisits7d = DB::table('statamic_analytics_page_views')
                ->where('visited_at', '>=', Carbon::now()->subDays(7))->count();
            $uniqueVisitors7d = DB::table('statamic_analytics_page_views')
                ->where('visited_at', '>=', Carbon::now()->subDays(7))
                ->where('is_new_visitor', true)->count();
        } catch (\Exception $e) {
            return '<p class="text-sm text-gray-400 p-4">Impossible de charger les données analytiques.</p>';
        }

        return view('statamic-privacy-analytics::widgets.analytics-overview', [
            'todayVisits'      => $todayVisits,
            'totalVisits7d'    => $totalVisits7d,
            'uniqueVisitors7d' => $uniqueVisitors7d,
            'analyticsUrl'     => cp_route('statamic-analytics.index'),
        ])->render();
    }
}
