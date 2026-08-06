<?php

namespace Oliweb\StatamicAnalytics\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PurgeFailedJobs extends Command
{
    protected $signature = 'analytics:purge-failed-jobs
                            {--dry-run : Affiche le nombre de lignes concernées sans les supprimer}';

    protected $description = 'Supprime les entrées failed_jobs correspondant à TrackPageViewJob plus anciennes que la période de rétention IP configurée.';

    public function handle(): int
    {
        $retentionDays = config('statamic-analytics.privacy.ip_retention_days');

        if ($retentionDays === null || $retentionDays === '') {
            $this->warn('Rétention illimitée configurée. Aucune purge des failed_jobs effectuée.');
            return self::SUCCESS;
        }

        if (!is_numeric($retentionDays) || (int) $retentionDays <= 0) {
            $this->error("Valeur invalide pour privacy.ip_retention_days : '{$retentionDays}'. Purge annulée par sécurité.");
            return self::FAILURE;
        }

        if (!Schema::hasTable('failed_jobs')) {
            $this->info('La table failed_jobs est absente — aucune action requise.');
            return self::SUCCESS;
        }

        $threshold = now()->subDays((int) $retentionDays);

        $query = fn () => DB::table('failed_jobs')
            ->where('failed_at', '<', $threshold)
            ->where('payload', 'like', '%TrackPageViewJob%');

        if ($this->option('dry-run')) {
            $count = $query()->count();
            $this->info("Dry run : {$count} entrée(s) failed_jobs seraient supprimées (seuil : {$threshold->toDateString()}).");
            return self::SUCCESS;
        }

        $deleted = $query()->delete();

        $this->info("{$deleted} entrée(s) failed_jobs supprimées (seuil : {$threshold->toDateString()}).");
        return self::SUCCESS;
    }
}
