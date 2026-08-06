<?php

namespace Oliweb\StatamicAnalytics\Tests;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

class PurgeRawEventsTest extends TestCase
{
    private function insertPageView(Carbon $visitedAt, array $overrides = []): int
    {
        return DB::table('statamic_analytics_page_views')->insertGetId(array_merge([
            'page_url'   => '/test',
            'ip_address' => '203.0.113.1',
            'user_agent' => 'Mozilla/5.0 Test',
            'user_id'    => 1,
            'visited_at' => $visitedAt,
            'created_at' => $visitedAt,
            'updated_at' => $visitedAt,
        ], $overrides));
    }

    // -------------------------------------------------------------------------
    // 1. Suppression normale
    // -------------------------------------------------------------------------

    #[Test]
    public function test_supprime_les_lignes_plus_anciennes_que_la_retention(): void
    {
        $now = Carbon::now();
        Carbon::setTestNow($now);

        try {
            config(['statamic-analytics.privacy.raw_retention_days' => 90]);

            $oldId    = $this->insertPageView($now->copy()->subDays(100));
            $recentId = $this->insertPageView($now->copy()->subDays(10));

            Artisan::call('analytics:purge-raw-events');

            $this->assertNull(DB::table('statamic_analytics_page_views')->find($oldId));

            $recent = DB::table('statamic_analytics_page_views')->find($recentId);
            $this->assertNotNull($recent);
            $this->assertSame('203.0.113.1', $recent->ip_address);
            $this->assertSame('Mozilla/5.0 Test', $recent->user_agent);
            $this->assertSame(1, (int) $recent->user_id);
        } finally {
            Carbon::setTestNow(null);
        }
    }

    // -------------------------------------------------------------------------
    // 2. Dry run
    // -------------------------------------------------------------------------

    #[Test]
    public function test_dry_run_ne_modifie_rien(): void
    {
        $now = Carbon::now();
        Carbon::setTestNow($now);

        try {
            config(['statamic-analytics.privacy.raw_retention_days' => 90]);

            $oldId    = $this->insertPageView($now->copy()->subDays(100));
            $recentId = $this->insertPageView($now->copy()->subDays(10));

            Artisan::call('analytics:purge-raw-events', ['--dry-run' => true]);

            $this->assertNotNull(DB::table('statamic_analytics_page_views')->find($oldId));
            $this->assertNotNull(DB::table('statamic_analytics_page_views')->find($recentId));
        } finally {
            Carbon::setTestNow(null);
        }
    }

    // -------------------------------------------------------------------------
    // 3. Rétention null → SUCCESS, aucune suppression
    // -------------------------------------------------------------------------

    #[Test]
    public function test_retention_null_ne_modifie_rien(): void
    {
        config(['statamic-analytics.privacy.raw_retention_days' => null]);

        $id = $this->insertPageView(Carbon::now()->subDays(1000));

        $exitCode = Artisan::call('analytics:purge-raw-events');

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertNotNull(DB::table('statamic_analytics_page_views')->find($id));
    }

    // -------------------------------------------------------------------------
    // 4. Rétention chaîne vide → SUCCESS, aucune suppression
    // -------------------------------------------------------------------------

    #[Test]
    public function test_retention_chaine_vide_ne_modifie_rien(): void
    {
        config(['statamic-analytics.privacy.raw_retention_days' => '']);

        $id = $this->insertPageView(Carbon::now()->subDays(1000));

        $exitCode = Artisan::call('analytics:purge-raw-events');

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertNotNull(DB::table('statamic_analytics_page_views')->find($id));
    }

    // -------------------------------------------------------------------------
    // 5. Rétention non-numérique → FAILURE, aucune suppression
    // -------------------------------------------------------------------------

    #[Test]
    public function test_retention_non_numerique_echoue_sans_modifier(): void
    {
        config(['statamic-analytics.privacy.raw_retention_days' => 'abc']);

        $id = $this->insertPageView(Carbon::now()->subDays(100));

        $exitCode = Artisan::call('analytics:purge-raw-events');

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertNotNull(DB::table('statamic_analytics_page_views')->find($id));
    }

    // -------------------------------------------------------------------------
    // 6. Rétention négative → FAILURE, aucune suppression
    // -------------------------------------------------------------------------

    #[Test]
    public function test_retention_negative_echoue_sans_modifier(): void
    {
        config(['statamic-analytics.privacy.raw_retention_days' => -5]);

        $id = $this->insertPageView(Carbon::now()->subDays(100));

        $exitCode = Artisan::call('analytics:purge-raw-events');

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertNotNull(DB::table('statamic_analytics_page_views')->find($id));
    }

    // -------------------------------------------------------------------------
    // 7. Chunking — toutes les lignes supprimées malgré un chunk_size réduit
    // -------------------------------------------------------------------------

    #[Test]
    public function test_chunking_supprime_toutes_les_lignes_malgre_petit_chunk_size(): void
    {
        $now = Carbon::now();
        Carbon::setTestNow($now);

        try {
            config([
                'statamic-analytics.privacy.raw_retention_days' => 90,
                'statamic-analytics.processing.chunk_size'       => 2,
            ]);

            $ids = [];
            for ($i = 0; $i < 5; $i++) {
                $ids[] = $this->insertPageView($now->copy()->subDays(100 + $i));
            }

            Artisan::call('analytics:purge-raw-events');

            foreach ($ids as $id) {
                $this->assertNull(
                    DB::table('statamic_analytics_page_views')->find($id),
                    "La ligne {$id} devrait avoir été supprimée."
                );
            }
        } finally {
            Carbon::setTestNow(null);
        }
    }
}
