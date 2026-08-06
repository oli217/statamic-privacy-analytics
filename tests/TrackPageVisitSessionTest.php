<?php

namespace Mohammedshuaau\EnhancedAnalytics\Tests;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Oliweb\StatamicAnalytics\Middleware\TrackPageVisit;
use PHPUnit\Framework\Attributes\Test;

class TrackPageVisitSessionTest extends TestCase
{
    #[Test]
    public function test_tracking_does_not_destroy_existing_session(): void
    {
        // Désactiver le consentement pour que shouldTrack() passe
        config(['statamic-analytics.tracking.consent.enabled' => false]);

        // Préparer une session avec une valeur applicative préexistante
        $session = $this->app['session']->driver('array');
        $session->start();
        $session->put('panier', ['produit_x']);

        $request = Request::create('/page-test', 'GET');
        $request->setLaravelSession($session);

        // Réponse 200 text/html : isTrackableResponse() retourne true
        $response = new Response('OK', 200, ['Content-Type' => 'text/html; charset=utf-8']);

        // Le middleware peut lever une exception DB (pas de table en test)
        // — elle est catchée en interne, seule la préservation de session nous importe
        (new TrackPageVisit())->handle($request, fn($req) => $response);

        $this->assertEquals(
            ['produit_x'],
            $request->session()->get('panier'),
            'Le middleware ne doit pas détruire les données de session existantes'
        );
    }
}
