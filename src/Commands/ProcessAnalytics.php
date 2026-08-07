<?php

namespace Oliweb\StatamicAnalytics\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ProcessAnalytics extends Command
{
    protected $signature = 'analytics:process
                            {--days=2 : Nombre de jours à traiter en partant d\'aujourd\'hui (2 = aujourd\'hui + hier, comportement par défaut inchangé)}';
    protected $description = 'Recalculate analytics aggregates from page_views for the last N days (default: 2)';

    public function handle()
    {
        $this->info('Processing analytics aggregates...');

        $lock = Cache::lock('statamic-analytics:processing', config('statamic-analytics.processing.lock_timeout', 60));

        try {
            if (!$lock->get()) {
                $this->warn('Another process is already running. Skipping...');
                return;
            }

            $days = max(1, (int) $this->option('days'));
            $dates = [];
            for ($i = 0; $i < $days; $i++) {
                $dates[] = Carbon::today()->subDays($i)->toDateString();
            }

            foreach ($dates as $date) {
                DB::transaction(function () use ($date) {
                    $this->rebuildAggregatesForDate($date);
                });
            }

            $this->info('Aggregates updated for: ' . implode(', ', $dates));
        } catch (\Exception $e) {
            $this->error("Fatal error during processing: {$e->getMessage()}");
            Log::error('Enhanced Analytics: ProcessAnalytics error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        } finally {
            $lock->release();
        }
    }

    protected function rebuildAggregatesForDate(string $date): void
    {
        $dimensions = ['country_code', 'device_type', 'browser', 'platform'];

        foreach ($dimensions as $dimension) {
            // Delete existing aggregates for this date/dimension
            DB::table('statamic_analytics_aggregates')
                ->where('type', 'daily')
                ->where('date', $date)
                ->where('dimension', $dimension)
                ->delete();

            // Re-insert from page_views
            $rows = DB::table('statamic_analytics_page_views')
                ->select(
                    DB::raw("'{$dimension}' as dimension"),
                    DB::raw("{$dimension} as dimension_value"),
                    DB::raw('COUNT(*) as total_visits'),
                    DB::raw('SUM(CASE WHEN is_new_visitor = 1 THEN 1 ELSE 0 END) as unique_visitors'),
                    DB::raw('SUM(CASE WHEN is_new_page_visit = 1 THEN 1 ELSE 0 END) as unique_page_views'),
                    DB::raw('SUM(CASE WHEN is_new_visitor = 0 THEN 1 ELSE 0 END) as returning_visitors')
                )
                ->where('visited_at', '>=', Carbon::parse($date)->startOfDay())
                ->where('visited_at', '<', Carbon::parse($date)->addDay()->startOfDay())
                ->whereNotNull($dimension)
                ->where($dimension, '!=', '')
                ->groupBy($dimension)
                ->get();

            $now = Carbon::now();
            foreach ($rows as $row) {
                DB::table('statamic_analytics_aggregates')->insert([
                    'type'              => 'daily',
                    'date'              => $date,
                    'dimension'         => $dimension,
                    'dimension_value'   => $row->dimension_value,
                    'total_visits'      => $row->total_visits,
                    'unique_visitors'   => $row->unique_visitors,
                    'unique_page_views' => $row->unique_page_views,
                    'returning_visitors'=> $row->returning_visitors,
                    'updated_at'        => $now,
                ]);
            }
        }

        // Agrégat _overview : résumé journalier sans groupement, survit à la purge des événements bruts
        DB::table('statamic_analytics_aggregates')
            ->where('type', 'daily')
            ->where('date', $date)
            ->where('dimension', '_overview')
            ->delete();

        $overview = DB::table('statamic_analytics_page_views')
            ->where('visited_at', '>=', Carbon::parse($date)->startOfDay())
            ->where('visited_at', '<', Carbon::parse($date)->addDay()->startOfDay())
            ->selectRaw('
                COUNT(*) as total_visits,
                SUM(CASE WHEN is_new_visitor = 1 THEN 1 ELSE 0 END) as unique_visitors,
                SUM(CASE WHEN is_new_page_visit = 1 THEN 1 ELSE 0 END) as unique_page_views,
                SUM(CASE WHEN is_new_visitor = 0 THEN 1 ELSE 0 END) as returning_visitors
            ')
            ->first();

        DB::table('statamic_analytics_aggregates')->insert([
            'type'               => 'daily',
            'date'               => $date,
            'dimension'          => '_overview',
            'dimension_value'    => '_all',
            'total_visits'       => $overview->total_visits ?? 0,
            'unique_visitors'    => $overview->unique_visitors ?? 0,
            'unique_page_views'  => $overview->unique_page_views ?? 0,
            'returning_visitors' => $overview->returning_visitors ?? 0,
            'updated_at'         => Carbon::now(),
        ]);
    }
}
