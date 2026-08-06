<?php

namespace Oliweb\StatamicAnalytics\Tests;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

class AnonymizeIpsTest extends TestCase
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
    // 1. Anonymisation normale
    // -------------------------------------------------------------------------

    #[Test]
    public function test_anonymise_les_lignes_plus_anciennes_que_la_retention(): void
    {
        $now = Carbon::now();
        Carbon::setTestNow($now);

        try {
            config(['statamic-analytics.privacy.ip_retention_days' => 90]);

            $oldId    = $this->insertPageView($now->copy()->subDays(100));
            $recentId = $this->insertPageView($now->copy()->subDays(10));

            Artisan::call('analytics:anonymize-ips');

            $old = DB::table('statamic_analytics_page_views')->find($oldId);
            $this->assertNull($old->ip_address);
            $this->assertNull($old->user_agent);
            $this->assertNull($old->user_id);

            $recent = DB::table('statamic_analytics_page_views')->find($recentId);
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
            config(['statamic-analytics.privacy.ip_retention_days' => 90]);

            $oldId    = $this->insertPageView($now->copy()->subDays(100));
            $recentId = $this->insertPageView($now->copy()->subDays(10));

            Artisan::call('analytics:anonymize-ips', ['--dry-run' => true]);

            $old = DB::table('statamic_analytics_page_views')->find($oldId);
            $this->assertSame('203.0.113.1', $old->ip_address);
            $this->assertSame('Mozilla/5.0 Test', $old->user_agent);
            $this->assertSame(1, (int) $old->user_id);

            $recent = DB::table('statamic_analytics_page_views')->find($recentId);
            $this->assertSame('203.0.113.1', $recent->ip_address);
        } finally {
            Carbon::setTestNow(null);
        }
    }

    // -------------------------------------------------------------------------
    // 3. Rétention null → SUCCESS, aucune modification
    // -------------------------------------------------------------------------

    #[Test]
    public function test_retention_null_ne_modifie_rien(): void
    {
        config(['statamic-analytics.privacy.ip_retention_days' => null]);

        $id = $this->insertPageView(Carbon::now()->subDays(1000));

        $exitCode = Artisan::call('analytics:anonymize-ips');

        $this->assertSame(Command::SUCCESS, $exitCode);
        $row = DB::table('statamic_analytics_page_views')->find($id);
        $this->assertSame('203.0.113.1', $row->ip_address);
    }

    // -------------------------------------------------------------------------
    // 4. Rétention chaîne vide → SUCCESS, aucune modification
    // -------------------------------------------------------------------------

    #[Test]
    public function test_retention_chaine_vide_ne_modifie_rien(): void
    {
        config(['statamic-analytics.privacy.ip_retention_days' => '']);

        $id = $this->insertPageView(Carbon::now()->subDays(1000));

        $exitCode = Artisan::call('analytics:anonymize-ips');

        $this->assertSame(Command::SUCCESS, $exitCode);
        $row = DB::table('statamic_analytics_page_views')->find($id);
        $this->assertSame('203.0.113.1', $row->ip_address);
    }

    // -------------------------------------------------------------------------
    // 5. Rétention non-numérique → FAILURE, aucune modification
    // -------------------------------------------------------------------------

    #[Test]
    public function test_retention_non_numerique_echoue_sans_modifier(): void
    {
        config(['statamic-analytics.privacy.ip_retention_days' => 'abc']);

        $id = $this->insertPageView(Carbon::now()->subDays(100));

        $exitCode = Artisan::call('analytics:anonymize-ips');

        $this->assertSame(Command::FAILURE, $exitCode);
        $row = DB::table('statamic_analytics_page_views')->find($id);
        $this->assertSame('203.0.113.1', $row->ip_address);
    }

    // -------------------------------------------------------------------------
    // 6. Rétention négative → FAILURE, aucune modification
    // -------------------------------------------------------------------------

    #[Test]
    public function test_retention_negative_echoue_sans_modifier(): void
    {
        config(['statamic-analytics.privacy.ip_retention_days' => -5]);

        $id = $this->insertPageView(Carbon::now()->subDays(100));

        $exitCode = Artisan::call('analytics:anonymize-ips');

        $this->assertSame(Command::FAILURE, $exitCode);
        $row = DB::table('statamic_analytics_page_views')->find($id);
        $this->assertSame('203.0.113.1', $row->ip_address);
    }

    // -------------------------------------------------------------------------
    // 7. Chunking — toutes les lignes traitées malgré un chunk_size réduit
    // -------------------------------------------------------------------------

    #[Test]
    public function test_chunking_traite_toutes_les_lignes_malgre_petit_chunk_size(): void
    {
        $now = Carbon::now();
        Carbon::setTestNow($now);

        try {
            config([
                'statamic-analytics.privacy.ip_retention_days'  => 90,
                'statamic-analytics.processing.chunk_size'       => 2,
            ]);

            $ids = [];
            for ($i = 0; $i < 5; $i++) {
                $ids[] = $this->insertPageView($now->copy()->subDays(100 + $i));
            }

            Artisan::call('analytics:anonymize-ips');

            foreach ($ids as $id) {
                $row = DB::table('statamic_analytics_page_views')->find($id);
                $this->assertNull($row->ip_address, "La ligne {$id} devrait être anonymisée.");
                $this->assertNull($row->user_agent, "La ligne {$id} devrait être anonymisée.");
                $this->assertNull($row->user_id, "La ligne {$id} devrait être anonymisée.");
            }
        } finally {
            Carbon::setTestNow(null);
        }
    }
}
