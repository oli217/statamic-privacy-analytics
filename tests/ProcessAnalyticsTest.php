<?php

namespace Oliweb\StatamicAnalytics\Tests;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

class ProcessAnalyticsTest extends TestCase
{
    /**
     * Insère une ligne dans statamic_analytics_page_views avec les colonnes
     * utilisées par ProcessAnalytics::rebuildAggregatesForDate() contrôlables
     * via $overrides.
     */
    private function insertPageView(Carbon $visitedAt, array $overrides = []): int
    {
        return DB::table('statamic_analytics_page_views')->insertGetId(array_merge([
            'page_url'          => '/test',
            'ip_address'        => '203.0.113.1',
            'user_agent'        => 'Mozilla/5.0 Test',
            'user_id'           => null,
            'country_code'      => null,
            'device_type'       => 'desktop',
            'browser'           => 'Chrome',
            'platform'          => 'Windows',
            'is_new_visitor'    => false,
            'is_new_day_visit'  => false,
            'is_new_hour_visit' => false,
            'is_new_page_visit' => false,
            'visited_at'        => $visitedAt,
            'created_at'        => $visitedAt,
            'updated_at'        => $visitedAt,
        ], $overrides));
    }

    // -------------------------------------------------------------------------
    // 1. Recalcul des agrégats par dimension
    // -------------------------------------------------------------------------

    #[Test]
    public function test_recalcule_les_agregats_par_dimension(): void
    {
        $now = Carbon::now();

        $this->insertPageView($now, ['country_code' => 'CH', 'is_new_visitor' => true]);
        $this->insertPageView($now, ['country_code' => 'CH', 'is_new_visitor' => false]);
        $this->insertPageView($now, ['country_code' => 'FR', 'is_new_visitor' => true]);

        Artisan::call('analytics:process');

        $today = Carbon::today()->toDateString();

        $ch = DB::table('statamic_analytics_aggregates')
            ->where('type', 'daily')
            ->where('date', $today)
            ->where('dimension', 'country_code')
            ->where('dimension_value', 'CH')
            ->first();

        $this->assertNotNull($ch);
        $this->assertSame(2, (int) $ch->total_visits);
        $this->assertSame(1, (int) $ch->unique_visitors);
        $this->assertSame(1, (int) $ch->returning_visitors);

        $fr = DB::table('statamic_analytics_aggregates')
            ->where('type', 'daily')
            ->where('date', $today)
            ->where('dimension', 'country_code')
            ->where('dimension_value', 'FR')
            ->first();

        $this->assertNotNull($fr);
        $this->assertSame(1, (int) $fr->total_visits);
    }

    // -------------------------------------------------------------------------
    // 2. Le second passage remplace, ne duplique pas
    // -------------------------------------------------------------------------

    #[Test]
    public function test_second_passage_ne_duplique_pas(): void
    {
        $now = Carbon::now();

        $this->insertPageView($now, ['country_code' => 'CH', 'is_new_visitor' => true]);
        $this->insertPageView($now, ['country_code' => 'CH', 'is_new_visitor' => false]);
        $this->insertPageView($now, ['country_code' => 'FR', 'is_new_visitor' => true]);

        Artisan::call('analytics:process');

        $today = Carbon::today()->toDateString();
        $countAfterFirst = DB::table('statamic_analytics_aggregates')
            ->where('type', 'daily')
            ->where('date', $today)
            ->where('dimension', 'country_code')
            ->count();

        Artisan::call('analytics:process');

        $countAfterSecond = DB::table('statamic_analytics_aggregates')
            ->where('type', 'daily')
            ->where('date', $today)
            ->where('dimension', 'country_code')
            ->count();

        $this->assertSame($countAfterFirst, $countAfterSecond);
    }

    // -------------------------------------------------------------------------
    // 3. Agrégat _overview correct
    // -------------------------------------------------------------------------

    #[Test]
    public function test_agregat_overview_correct(): void
    {
        $now = Carbon::now();

        $this->insertPageView($now, ['is_new_visitor' => true]);
        $this->insertPageView($now, ['is_new_visitor' => true]);
        $this->insertPageView($now, ['is_new_visitor' => false]);

        Artisan::call('analytics:process');

        $overview = DB::table('statamic_analytics_aggregates')
            ->where('type', 'daily')
            ->where('date', Carbon::today()->toDateString())
            ->where('dimension', '_overview')
            ->where('dimension_value', '_all')
            ->first();

        $this->assertNotNull($overview);
        $this->assertSame(3, (int) $overview->total_visits);
        $this->assertSame(2, (int) $overview->unique_visitors);
        $this->assertSame(1, (int) $overview->returning_visitors);
    }

    // -------------------------------------------------------------------------
    // 4. Seuls aujourd'hui et hier sont recalculés — les agrégats historiques
    //    restent permanents
    // -------------------------------------------------------------------------

    #[Test]
    public function test_ne_traite_que_aujourd_hui_et_hier(): void
    {
        $oldDate = Carbon::now()->subDays(5)->toDateString();

        // Ligne brute ancienne (hors fenêtre de recalcul)
        $this->insertPageView(
            Carbon::now()->subDays(5),
            ['country_code' => 'DE']
        );

        // Agrégat historique "figé" simulant un calcul antérieur
        DB::table('statamic_analytics_aggregates')->insert([
            'type'              => 'daily',
            'date'              => $oldDate,
            'dimension'         => 'country_code',
            'dimension_value'   => 'DE',
            'total_visits'      => 99, // valeur sentinelle
            'unique_visitors'   => 77,
            'unique_page_views' => 55,
            'returning_visitors'=> 22,
            'updated_at'        => Carbon::now(),
        ]);

        Artisan::call('analytics:process');

        $frozen = DB::table('statamic_analytics_aggregates')
            ->where('type', 'daily')
            ->where('date', $oldDate)
            ->where('dimension', 'country_code')
            ->where('dimension_value', 'DE')
            ->first();

        $this->assertNotNull($frozen, 'L\'agrégat historique doit exister après le passage de la commande.');
        $this->assertSame(99, (int) $frozen->total_visits, 'La valeur sentinelle doit être inchangée.');
    }

    // -------------------------------------------------------------------------
    // 5. Lignes avec dimension null ou chaîne vide sont ignorées
    // -------------------------------------------------------------------------

    #[Test]
    public function test_lignes_sans_dimension_ignorees(): void
    {
        $now = Carbon::now();

        $this->insertPageView($now, ['country_code' => null]);
        $this->insertPageView($now, ['country_code' => '']);

        Artisan::call('analytics:process');

        $count = DB::table('statamic_analytics_aggregates')
            ->where('dimension', 'country_code')
            ->count();

        $this->assertSame(0, $count, 'Aucun agrégat country_code ne doit être créé pour des valeurs null ou vides.');
    }

    // -------------------------------------------------------------------------
    // 6. --days recalcule plusieurs jours en arrière
    // -------------------------------------------------------------------------

    #[Test]
    public function test_option_days_recalcule_plusieurs_jours_en_arriere(): void
    {
        // Insérer une page view sur chacun des 4 derniers jours
        for ($i = 0; $i < 4; $i++) {
            $this->insertPageView(Carbon::today()->subDays($i), [
                'country_code' => 'CH',
                'is_new_visitor' => true,
            ]);
        }

        Artisan::call('analytics:process', ['--days' => 4]);

        for ($i = 0; $i < 4; $i++) {
            $date = Carbon::today()->subDays($i)->toDateString();
            $aggregate = DB::table('statamic_analytics_aggregates')
                ->where('type', 'daily')
                ->where('date', $date)
                ->where('dimension', '_overview')
                ->first();

            $this->assertNotNull(
                $aggregate,
                "L'agrégat _overview pour la date {$date} doit exister avec --days=4."
            );
            $this->assertSame(1, (int) $aggregate->total_visits);
        }
    }

    // -------------------------------------------------------------------------
    // 7. Le verrou empêche l'exécution concurrente
    // -------------------------------------------------------------------------

    #[Test]
    public function test_lock_empeche_execution_concurrente(): void
    {
        $this->insertPageView(Carbon::now(), ['country_code' => 'US', 'is_new_visitor' => true]);

        // Acquérir le même verrou que la commande
        $lock = Cache::lock('statamic-analytics:processing', 60);
        $acquired = $lock->get();
        $this->assertTrue($acquired, 'Le verrou doit être acquérable dans ce contexte de test.');

        try {
            Artisan::call('analytics:process');

            $this->assertSame(
                0,
                DB::table('statamic_analytics_aggregates')->count(),
                'Aucun agrégat ne doit être écrit quand le verrou est déjà tenu.'
            );
        } finally {
            $lock->release();
        }
    }
}
