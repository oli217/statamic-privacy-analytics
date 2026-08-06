<?php

namespace Oliweb\StatamicAnalytics\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AnonymizeIps extends Command
{
    protected $signature = 'analytics:anonymize-ips
                            {--dry-run : Affiche le nombre de lignes concernées sans les modifier}';

    protected $description = 'Anonymise ip_address, user_agent et user_id des pages vues plus anciennes que la période de rétention configurée.';

    public function handle(): int
    {
        $retentionDays = config('statamic-analytics.privacy.ip_retention_days');

        if ($retentionDays === null || $retentionDays === '') {
            $this->warn('Rétention illimitée configurée. Aucune anonymisation effectuée.');
            return self::SUCCESS;
        }

        if (!is_numeric($retentionDays) || (int) $retentionDays <= 0) {
            $this->error("Valeur invalide pour privacy.ip_retention_days : '{$retentionDays}'. Anonymisation annulée par sécurité.");
            return self::FAILURE;
        }

        $threshold = now()->subDays((int) $retentionDays);

        $query = fn () => DB::table('statamic_analytics_page_views')
            ->where('visited_at', '<', $threshold)
            ->where(function ($q) {
                $q->whereNotNull('ip_address')
                  ->orWhereNotNull('user_agent')
                  ->orWhereNotNull('user_id');
            });

        if ($this->option('dry-run')) {
            $count = $query()->count();
            $this->info("Dry run : {$count} ligne(s) seraient anonymisées (ip_address + user_agent + user_id → NULL, seuil : {$threshold->toDateString()}).");
            return self::SUCCESS;
        }

        $chunkSize = config('statamic-analytics.processing.chunk_size', 1000);
        $anonymized = 0;

        $query()->chunkById($chunkSize, function ($rows) use (&$anonymized) {
            $ids = $rows->pluck('id')->all();

            DB::table('statamic_analytics_page_views')
                ->whereIn('id', $ids)
                ->update(['ip_address' => null, 'user_agent' => null, 'user_id' => null]);

            $anonymized += count($ids);
        });

        $this->info("{$anonymized} ligne(s) anonymisées (ip_address + user_agent + user_id → NULL, seuil : {$threshold->toDateString()}).");
        return self::SUCCESS;
    }
}
