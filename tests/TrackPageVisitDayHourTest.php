<?php

namespace Oliweb\StatamicAnalytics\Tests;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Oliweb\StatamicAnalytics\Middleware\TrackPageVisit;
use PHPUnit\Framework\Attributes\Test;

/**
 * Vérifie la logique is_new_day_visit / is_new_hour_visit après le correctif
 * qui remplace !$lastVisitDate (test de présence) par une vraie comparaison
 * de chaînes de date tronquées ('Y-m-d' / 'Y-m-d H').
 *
 * Stratégie : mode synchrone (queue_connection = null) + geolocation disabled
 * → chaque handle() insère une ligne réelle dans statamic_analytics_page_views.
 * La même instance de session store (app('session.store')) est partagée par
 * toutes les requêtes d'un même test pour simuler une session continue.
 */
class TrackPageVisitDayHourTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'statamic-analytics.tracking.consent.enabled'  => false,
            'statamic-analytics.tracking.exclude_ips'      => [],
            'statamic-analytics.tracking.exclude_bots'     => false,
            'statamic-analytics.tracking.queue_connection'  => null,
            'statamic-analytics.geolocation.provider'      => 'disabled',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    private function makeRequest(string $path = '/test'): Request
    {
        $sessionStore = app('session.store');
        if (!$sessionStore->isStarted()) {
            $sessionStore->start();
        }

        $request = Request::create($path, 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.1']);
        $request->setLaravelSession($sessionStore);

        return $request;
    }

    private function dispatch(Request $request): void
    {
        (new TrackPageVisit())->handle(
            $request,
            fn ($req) => new Response('OK', 200, ['Content-Type' => 'text/html; charset=utf-8'])
        );
    }

    private function lastRow(): object
    {
        return DB::table('statamic_analytics_page_views')->orderBy('id', 'desc')->first();
    }

    // -------------------------------------------------------------------------
    // 1. Première visite de session → true / true
    // -------------------------------------------------------------------------

    #[Test]
    public function test_premiere_visite_session_new_day_et_new_hour_sont_vrais(): void
    {
        Carbon::setTestNow(Carbon::create(2024, 6, 15, 10, 30, 0));

        $request = $this->makeRequest();
        $this->dispatch($request);

        $row = $this->lastRow();
        $this->assertTrue((bool) $row->is_new_day_visit);
        $this->assertTrue((bool) $row->is_new_hour_visit);
    }

    // -------------------------------------------------------------------------
    // 2. Deuxième visite même jour / même heure → false / false
    // -------------------------------------------------------------------------

    #[Test]
    public function test_deuxieme_visite_meme_jour_meme_heure_false_false(): void
    {
        Carbon::setTestNow(Carbon::create(2024, 6, 15, 10, 30, 0));

        $request = $this->makeRequest();
        $this->dispatch($request); // 1re visite — initialise la session
        $this->dispatch($request); // 2e visite — même jour, même heure

        $row = $this->lastRow();
        $this->assertFalse((bool) $row->is_new_day_visit);
        $this->assertFalse((bool) $row->is_new_hour_visit);
    }

    // -------------------------------------------------------------------------
    // 3. Changement d'heure, même jour → is_new_hour=true, is_new_day=false
    // -------------------------------------------------------------------------

    #[Test]
    public function test_changement_heure_meme_jour_new_hour_true_new_day_false(): void
    {
        $t0 = Carbon::create(2024, 6, 15, 10, 30, 0);
        Carbon::setTestNow($t0);

        $request = $this->makeRequest();
        $this->dispatch($request); // 1re visite à 10h30

        Carbon::setTestNow($t0->copy()->addHour()); // avance à 11h30
        $this->dispatch($request); // 2e visite à 11h30

        $row = $this->lastRow();
        $this->assertFalse((bool) $row->is_new_day_visit);
        $this->assertTrue((bool) $row->is_new_hour_visit);
    }

    // -------------------------------------------------------------------------
    // 4. Changement de jour → true / true
    // -------------------------------------------------------------------------

    #[Test]
    public function test_changement_de_jour_new_day_et_new_hour_vrais(): void
    {
        $t0 = Carbon::create(2024, 6, 15, 23, 30, 0);
        Carbon::setTestNow($t0);

        $request = $this->makeRequest();
        $this->dispatch($request); // visite le 15 juin à 23h30

        Carbon::setTestNow($t0->copy()->addDay()); // 16 juin à 23h30
        $this->dispatch($request); // visite le lendemain

        $row = $this->lastRow();
        $this->assertTrue((bool) $row->is_new_day_visit);
        $this->assertTrue((bool) $row->is_new_hour_visit);
    }

    // -------------------------------------------------------------------------
    // 5. Cas limite autour de minuit (23:59:59 → 00:00:01)
    // -------------------------------------------------------------------------

    #[Test]
    public function test_cas_limite_autour_de_minuit(): void
    {
        $before = Carbon::create(2024, 6, 15, 23, 59, 59);
        Carbon::setTestNow($before);

        $request = $this->makeRequest();
        $this->dispatch($request); // visite à 23:59:59 le 15 juin

        $after = Carbon::create(2024, 6, 16, 0, 0, 1);
        Carbon::setTestNow($after);
        $this->dispatch($request); // visite à 00:00:01 le 16 juin

        $row = $this->lastRow();
        $this->assertTrue((bool) $row->is_new_day_visit);
        $this->assertTrue((bool) $row->is_new_hour_visit);
    }
}
