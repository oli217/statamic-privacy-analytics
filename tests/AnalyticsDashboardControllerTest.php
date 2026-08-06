<?php

namespace Oliweb\StatamicAnalytics\Tests;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Oliweb\StatamicAnalytics\Http\Controllers\AnalyticsDashboardController;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests de non-régression ciblés sur getTopPages() et getUserFlow() — les deux
 * méthodes corrigées en v3.0.2 (bug Carbon diffInSeconds() retournant des valeurs
 * négatives).
 *
 * Stratégie : appel direct de getData() sur le contrôleur (méthode publique qui
 * agrège tout). Pas de routing CP ni d'Inertia impliqués — getData() ne dépend
 * que de DB et Carbon, compatibles avec Testbench.
 *
 * Plage de test fixe : 2024-06-15 00:00:00 → 2024-06-16 00:00:00 (mode custom).
 * Toutes les lignes insérées sont datées du 2024-06-15 pour rester dans la plage.
 */
class AnalyticsDashboardControllerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Retourne un Carbon sur le 2024-06-15 à l'heure donnée.
     */
    private function ts(int $hour, int $minute = 0, int $second = 0): Carbon
    {
        return Carbon::create(2024, 6, 15, $hour, $minute, $second);
    }

    /**
     * Insère une ligne dans statamic_analytics_page_views avec des valeurs
     * minimales saines, toutes les colonnes pertinentes surchargeables.
     */
    private function insertPageView(Carbon $visitedAt, array $overrides = []): int
    {
        return DB::table('statamic_analytics_page_views')->insertGetId(array_merge([
            'page_url'          => '/test',
            'ip_address'        => null,
            'user_agent'        => null,
            'user_id'           => null,
            'session_id'        => 'sess-default',
            'visitor_id'        => 'visitor-default',
            'is_new_visitor'    => false,
            'is_new_day_visit'  => false,
            'is_new_hour_visit' => false,
            'is_new_page_visit' => false,
            'visited_at'        => $visitedAt->format('Y-m-d H:i:s'),
            'created_at'        => $visitedAt->format('Y-m-d H:i:s'),
            'updated_at'        => $visitedAt->format('Y-m-d H:i:s'),
        ], $overrides));
    }

    /**
     * Appelle getData() avec une plage custom couvrant le 2024-06-15 entier.
     * Retourne le tableau PHP décodé du JSON.
     */
    private function getData(): array
    {
        $controller = new AnalyticsDashboardController();
        $request = Request::create('/data', 'GET', [
            'range'      => 'custom',
            'start_date' => '2024-06-15',
            'end_date'   => '2024-06-16',
        ]);
        return json_decode($controller->getData($request)->getContent(), true);
    }

    // -------------------------------------------------------------------------
    // 1. avg_time dans top_pages — garde-fou principal contre le bug Carbon
    // -------------------------------------------------------------------------

    #[Test]
    public function test_avg_time_top_pages_est_positif_et_correct(): void
    {
        // Session 'sess1' visite '/contact' à 10:00, 10:02, 10:05
        // Intervalles : 120 s puis 180 s → avg = (120 + 180) / 2 = 150 s
        $this->insertPageView($this->ts(10, 0, 0), ['page_url' => '/contact', 'session_id' => 'sess1']);
        $this->insertPageView($this->ts(10, 2, 0), ['page_url' => '/contact', 'session_id' => 'sess1']);
        $this->insertPageView($this->ts(10, 5, 0), ['page_url' => '/contact', 'session_id' => 'sess1']);

        $data = $this->getData();

        $contact = collect($data['top_pages'])->first(fn ($p) => $p['page_url'] === '/contact');

        $this->assertNotNull($contact, '/contact doit apparaître dans top_pages.');
        $this->assertGreaterThan(
            0,
            $contact['avg_time'],
            'avg_time doit être positif — valeur négative = régression bug Carbon diffInSeconds().'
        );
        $this->assertEquals(
            150,
            $contact['avg_time'],
            'avg_time doit être (120 + 180) / 2 = 150 s.'
        );
    }

    // -------------------------------------------------------------------------
    // 2. avg_time dans user_flow.engaged_pages — même garde-fou
    // -------------------------------------------------------------------------

    #[Test]
    public function test_avg_time_engaged_pages_est_positif(): void
    {
        // Même setup : 3 visites à '/contact' dans la même session
        $this->insertPageView($this->ts(10, 0, 0), ['page_url' => '/contact', 'session_id' => 'sess1']);
        $this->insertPageView($this->ts(10, 2, 0), ['page_url' => '/contact', 'session_id' => 'sess1']);
        $this->insertPageView($this->ts(10, 5, 0), ['page_url' => '/contact', 'session_id' => 'sess1']);

        $data = $this->getData();

        $engaged = collect($data['user_flow']['engaged_pages'])
            ->first(fn ($p) => $p['url'] === '/contact');

        $this->assertNotNull($engaged, '/contact doit apparaître dans user_flow.engaged_pages.');
        $this->assertGreaterThan(
            0,
            $engaged['avg_time'],
            'avg_time dans engaged_pages doit être positif — régression bug Carbon.'
        );
        $this->assertEquals(150, $engaged['avg_time']);
    }

    // -------------------------------------------------------------------------
    // 3. Totaux overview cohérents avec les données insérées
    // -------------------------------------------------------------------------

    #[Test]
    public function test_overview_totaux_corrects(): void
    {
        $now = $this->ts(10);
        $this->insertPageView($now, ['is_new_visitor' => true]);
        $this->insertPageView($now, ['is_new_visitor' => true]);
        $this->insertPageView($now, ['is_new_visitor' => false]);

        $data = $this->getData();

        $this->assertSame(3, $data['overview']['total_visits']);
        $this->assertSame(2, $data['overview']['unique_visitors']);
    }

    // -------------------------------------------------------------------------
    // 4. Limite à 10 pages, ordre décroissant par vues
    // -------------------------------------------------------------------------

    #[Test]
    public function test_top_pages_respecte_la_limite(): void
    {
        $now = $this->ts(10);

        // /page-1 a 1 vue, /page-2 a 2 vues, ..., /page-12 a 12 vues
        for ($i = 1; $i <= 12; $i++) {
            for ($j = 0; $j < $i; $j++) {
                $this->insertPageView($now, ['page_url' => "/page-$i"]);
            }
        }

        $data = $this->getData();
        $topPages = $data['top_pages'];

        $this->assertCount(10, $topPages, 'getData() doit retourner au plus 10 pages.');
        $this->assertSame(12, (int) $topPages[0]['views'], 'La page la plus visitée (/page-12) doit être en tête.');

        // Vérification de l'ordre décroissant
        for ($i = 0; $i < count($topPages) - 1; $i++) {
            $this->assertGreaterThanOrEqual(
                (int) $topPages[$i + 1]['views'],
                (int) $topPages[$i]['views'],
                "top_pages[$i] (views={$topPages[$i]['views']}) doit avoir ≥ autant de vues que top_pages[" . ($i + 1) . "] (views={$topPages[$i+1]['views']})."
            );
        }
    }

    // -------------------------------------------------------------------------
    // 5. Période sans données — pas d'exception, totaux à zéro
    // -------------------------------------------------------------------------

    #[Test]
    public function test_periode_sans_donnees_ne_plante_pas(): void
    {
        // Aucune ligne insérée
        $data = $this->getData();

        $this->assertSame(0, $data['overview']['total_visits']);
        $this->assertSame(0, $data['overview']['unique_visitors']);
        $this->assertIsNumeric(
            $data['overview']['bounce_rate'],
            'bounce_rate ne doit pas être null ni NaN (division par zéro protégée).'
        );
        $this->assertSame(
            0,
            $data['overview']['avg_time_on_site'],
            'avg_time_on_site doit être 0 quand aucune session multi-visite n\'existe.'
        );
        $this->assertIsArray($data['top_pages']);
        $this->assertEmpty($data['top_pages']);
        $this->assertIsArray($data['user_flow']['engaged_pages']);
        $this->assertEmpty($data['user_flow']['engaged_pages']);
    }
}
