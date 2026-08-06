<?php

namespace Oliweb\StatamicAnalytics\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgeRawEvents extends Command
{
    protected $signature = 'analytics:purge-raw-events
                            {--dry-run : Affiche le nombre de lignes concernées sans les supprimer}';

    protected $description = 'Supprime définitivement les pages vues plus anciennes que la période de rétention brute configurée. Les agrégats quotidiens (dimension _overview et autres) ne sont pas affectés et restent disponibles indéfiniment.';

    public function handle(): int
    {
        $rawRetentionDays = config('statamic-analytics.privacy.raw_retention_days');

        if ($rawRetentionDays === null || $rawRetentionDays === '') {
            $this->warn('Rétention illimitée configurée. Aucune purge effectuée.');
            return self::SUCCESS;
        }

        if (!is_numeric($rawRetentionDays) || (int) $rawRetentionDays <= 0) {
            $this->error("Valeur invalide pour privacy.raw_retention_days : '{$rawRetentionDays}'. Purge annulée par sécurité.");
            return self::FAILURE;
        }

        $threshold = now()->subDays((int) $rawRetentionDays);

        $query = fn () => DB::table('statamic_analytics_page_views')
            ->where('visited_at', '<', $threshold);

        if ($this->option('dry-run')) {
            $count = $query()->count();
            $this->info("Dry run : {$count} ligne(s) seraient supprimées (seuil : {$threshold->toDateString()}).");
            return self::SUCCESS;
        }

        $chunkSize = config('statamic-analytics.processing.chunk_size', 1000);
        $deleted = 0;

        $query()->chunkById($chunkSize, function ($rows) use (&$deleted) {
            $ids = $rows->pluck('id')->all();

            DB::table('statamic_analytics_page_views')
                ->whereIn('id', $ids)
                ->delete();

            $deleted += count($ids);
        });

        $this->info("{$deleted} ligne(s) supprimées définitivement (seuil : {$threshold->toDateString()}).");
        return self::SUCCESS;
    }
}
