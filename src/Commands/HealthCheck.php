<?php

namespace Oliweb\StatamicAnalytics\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class HealthCheck extends Command
{
    protected $signature = 'analytics:health';
    protected $description = 'Diagnostic checks for the Statamic Analytics addon';

    private int $warnings = 0;
    private int $errors = 0;

    public function handle(): int
    {
        $this->line('');
        $this->line('<fg=cyan;options=bold>Statamic Analytics — Health Check</>');
        $this->line(str_repeat('─', 50));

        $this->checkConfiguration();
        $this->checkDatabase();
        $this->checkCache();
        $this->checkEncryption();
        $this->checkQueue();
        $this->checkGeoProvider();
        $this->checkStoragePermissions();
        $this->checkScheduler();
        $this->checkFailedJobs();
        $this->checkStaticCacheCompatibility();
        $this->checkBeaconEndpoint();

        $this->line(str_repeat('─', 50));

        if ($this->errors > 0) {
            $this->line("<fg=red>  {$this->errors} error(s), {$this->warnings} warning(s)</>");
            return self::FAILURE;
        }

        if ($this->warnings > 0) {
            $this->line("<fg=yellow>  {$this->warnings} warning(s) — review output above</>");
            return self::SUCCESS;
        }

        $this->line('<fg=green>  All checks passed</>');
        return self::SUCCESS;
    }

    // ─── Output helpers ────────────────────────────────────────────────────

    private function pass(string $label, string $detail = ''): void
    {
        $line = "<fg=green>✓</> {$label}";
        if ($detail) {
            $line .= " <fg=gray>{$detail}</>";
        }
        $this->line("  {$line}");
    }

    private function alert(string $label, string $detail = ''): void
    {
        $line = "<fg=yellow>⚠</> {$label}";
        if ($detail) {
            $line .= "\n    <fg=yellow>{$detail}</>";
        }
        $this->line("  {$line}");
        $this->warnings++;
    }

    private function fail(string $label, string $detail = ''): void
    {
        $line = "<fg=red>✗</> {$label}";
        if ($detail) {
            $line .= "\n    <fg=red>{$detail}</>";
        }
        $this->line("  {$line}");
        $this->errors++;
    }

    // ─── Checks ────────────────────────────────────────────────────────────

    private function checkConfiguration(): void
    {
        if (empty(config('statamic-analytics'))) {
            $this->fail('Configuration', 'Config not loaded — run: php artisan vendor:publish --tag=statamic-analytics-config');
            return;
        }

        $this->pass('Configuration');
    }

    private function checkDatabase(): void
    {
        try {
            DB::connection()->getPdo();
        } catch (\Exception $e) {
            $this->fail('Database', 'Cannot connect: ' . $e->getMessage());
            return;
        }

        $tables = ['statamic_analytics_page_views', 'statamic_analytics_aggregates'];
        $missing = array_values(array_filter($tables, fn ($t) => !Schema::hasTable($t)));

        if ($missing) {
            $this->fail('Database', 'Missing tables: ' . implode(', ', $missing) . ' — run: php artisan migrate');
            return;
        }

        $count = DB::table('statamic_analytics_page_views')->count();
        $this->pass('Database', "({$count} events)");
    }

    private function checkCache(): void
    {
        try {
            $key = 'statamic-analytics:health-' . uniqid();
            Cache::put($key, 'ok', 10);
            Cache::forget($key);
            $this->pass('Cache', '(' . config('cache.default') . ')');
        } catch (\Exception $e) {
            $this->fail('Cache', $e->getMessage());
        }
    }

    private function checkEncryption(): void
    {
        if (empty(config('app.key'))) {
            $this->fail('Encryption', 'APP_KEY is not set — encrypted jobs will fail');
            return;
        }

        $this->pass('Encryption');
    }

    private function checkQueue(): void
    {
        $connection = config('statamic-analytics.tracking.queue_connection');

        if ($connection === null) {
            $this->pass('Queue', '(synchronous)');
            return;
        }

        if (!array_key_exists($connection, config('queue.connections', []))) {
            $this->fail('Queue', "Connection '{$connection}' not found in queue.connections");
            return;
        }

        $queueName = config('statamic-analytics.tracking.queue_name', 'analytics');
        $this->pass('Queue', "(async · {$connection} · {$queueName})");
    }

    private function checkGeoProvider(): void
    {
        $provider = config('statamic-analytics.geolocation.provider', 'disabled');

        match ($provider) {
            'disabled' => $this->alert('Geolocation', 'Disabled — country data will not be collected'),
            'ip-api'   => $this->pass('Geolocation', '(ip-api.com, 45 req/min free tier)'),
            'maxmind'  => $this->checkMaxMind(),
            default    => $this->fail('Geolocation', "Unknown provider: {$provider}"),
        };
    }

    private function checkMaxMind(): void
    {
        $accountId  = config('statamic-analytics.geolocation.maxmind.account_id');
        $licenseKey = config('statamic-analytics.geolocation.maxmind.license_key');
        $dbPath     = config(
            'statamic-analytics.geolocation.maxmind.database_path',
            storage_path('app/geoip/GeoLite2-City.mmdb')
        );

        if (!$accountId || !$licenseKey) {
            $this->alert('MaxMind credentials', 'MAXMIND_ACCOUNT_ID or MAXMIND_LICENSE_KEY missing — auto-update disabled');
        } else {
            $this->pass('MaxMind credentials');
        }

        if (!file_exists($dbPath)) {
            $this->fail('MaxMind database', "Not found: {$dbPath} — run: php artisan analytics:update-geoip");
            return;
        }

        if (!is_readable($dbPath)) {
            $this->fail('MaxMind database', "Not readable: {$dbPath}");
            return;
        }

        $age = (int) Carbon::createFromTimestamp(filemtime($dbPath))->diffInDays(now());

        if ($age > 30) {
            $this->alert('MaxMind database', "{$age} days old — run: php artisan analytics:update-geoip");
        } else {
            $this->pass('MaxMind database', "({$age} days old)");
        }
    }

    private function checkStoragePermissions(): void
    {
        $dirs = [
            storage_path('app/statamic-analytics') => 'analytics cache',
            storage_path('logs')                   => 'logs',
        ];

        if (config('statamic-analytics.geolocation.provider') === 'maxmind') {
            $geoipDir = dirname(config(
                'statamic-analytics.geolocation.maxmind.database_path',
                storage_path('app/geoip/GeoLite2-City.mmdb')
            ));
            $dirs[$geoipDir] = 'geoip';
        }

        $problems = [];

        foreach ($dirs as $path => $label) {
            if (is_dir($path) && !is_writable($path)) {
                $problems[] = "{$label} ({$path})";
            }
        }

        if ($problems) {
            $this->fail('Storage permissions', 'Not writable: ' . implode(', ', $problems));
        } else {
            $this->pass('Storage permissions');
        }
    }

    private function checkScheduler(): void
    {
        $logPath = storage_path('logs/analytics-scheduler.log');

        if (!file_exists($logPath)) {
            $this->alert('Scheduler', 'No scheduler log found — add to crontab: * * * * * php artisan schedule:run');
            return;
        }

        $hours = (int) Carbon::createFromTimestamp(filemtime($logPath))->diffInHours(now());

        if ($hours > 25) {
            $this->alert('Scheduler', "Last activity {$hours}h ago — scheduler may not be running");
        } else {
            $this->pass('Scheduler', "(last run {$hours}h ago)");
        }
    }

    private function checkFailedJobs(): void
    {
        try {
            if (!Schema::hasTable('failed_jobs')) {
                $this->alert('Failed jobs', 'failed_jobs table not found');
                return;
            }

            $count = DB::table('failed_jobs')
                ->where('payload', 'like', '%TrackPageViewJob%')
                ->count();

            if ($count > 0) {
                $this->alert('Failed jobs', "{$count} failed analytics job(s) — run: php artisan analytics:purge-failed-jobs");
            } else {
                $this->pass('Failed jobs');
            }
        } catch (\Exception $e) {
            $this->alert('Failed jobs', 'Could not query: ' . $e->getMessage());
        }
    }

    private function checkStaticCacheCompatibility(): void
    {
        $strategy = config('statamic.static_caching.strategy');

        if (!$strategy) {
            $this->pass('Static cache', '(not enabled)');
            return;
        }

        if ($strategy === 'full') {
            $this->alert(
                'Static cache (full)',
                'PHP middleware is bypassed — add {{ statamic_analytics:tracker }} to your layout'
            );
            return;
        }

        // half strategy: middleware runs on cache miss, no action needed
        $this->pass('Static cache', "(strategy: {$strategy})");
    }

    private function checkBeaconEndpoint(): void
    {
        if (!Route::has('statamic-analytics.track')) {
            $this->fail('Beacon endpoint', 'Route statamic-analytics.track not found — addon may not be installed correctly');
            return;
        }

        $this->pass('Beacon endpoint');
    }
}
