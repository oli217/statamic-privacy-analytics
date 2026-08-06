<?php

namespace Oliweb\StatamicAnalytics\Tests;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Queue;
use Jenssegers\Agent\Agent;
use Oliweb\StatamicAnalytics\Jobs\TrackPageViewJob;
use Oliweb\StatamicAnalytics\Middleware\TrackPageVisit;
use PHPUnit\Framework\Attributes\Test;

class TrackPageVisitFilteringTest extends TestCase
{
    /**
     * Config de base pour tous les tests de filtrage.
     *
     * queue_connection = 'sync' est indispensable : si shouldTrack() ou
     * isTrackableResponse() laissent passer la requête alors qu'ils ne devraient
     * pas, un job sera dispatché et Queue::assertNothingPushed() échouera —
     * c'est exactement l'effet détecteur recherché.
     */
    private function baseConfig(array $override = []): void
    {
        config(array_merge([
            'statamic-analytics.tracking.consent.enabled'  => false,
            'statamic-analytics.tracking.exclude_ips'      => [],
            'statamic-analytics.tracking.queue_connection'  => 'sync',
            'statamic-analytics.tracking.queue_name'        => 'analytics',
        ], $override));
    }

    /**
     * Crée une Request attachée au session.store global de l'app.
     *
     * Utiliser app('session.store') (singleton lié à SessionManager::driver())
     * garantit que session() global et $request->session() opèrent sur la même
     * instance — indispensable pour que shouldTrack() voie les valeurs de
     * consentement posées dans le test.
     *
     * Note : méthode intentionnellement privée. La déplacer dans TestCase
     * comme 'protected' briserait TrackPageVisitQueueTest qui déclare sa propre
     * version 'private makeRequest()' — PHP interdit de restreindre la
     * visibilité dans une sous-classe.
     *
     * REMOTE_ADDR par défaut : 203.0.113.1 (RFC 5737 TEST-NET-3, jamais dans
     * une liste d'exclusion réelle).
     */
    private function makeRequest(string $path = '/page-test', array $server = []): Request
    {
        $sessionStore = app('session.store');

        if (!$sessionStore->isStarted()) {
            $sessionStore->start();
        }

        $request = Request::create($path, 'GET', [], [], [], array_merge(
            ['REMOTE_ADDR' => '203.0.113.1'],
            $server
        ));

        $request->setLaravelSession($sessionStore);

        return $request;
    }

    private function htmlResponse(): Response
    {
        return new Response('OK', 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * Instancie TrackPageVisit en substituant l'Agent via réflexion.
     *
     * La réflexion est limitée à l'écriture de la propriété protected $agent ;
     * les méthodes protected du middleware (shouldTrack, isTrackableResponse)
     * ne sont jamais appelées directement — cohérent avec le pattern du repo.
     */
    private function middlewareWithAgent(string $userAgent): TrackPageVisit
    {
        $middleware = new TrackPageVisit();

        $agent = new Agent();
        $agent->setUserAgent($userAgent);

        $prop = new \ReflectionProperty($middleware, 'agent');
        $prop->setValue($middleware, $agent);

        return $middleware;
    }

    private function fakeUser(): \Illuminate\Contracts\Auth\Authenticatable
    {
        return new class implements \Illuminate\Contracts\Auth\Authenticatable {
            public function getAuthIdentifierName(): string { return 'id'; }
            public function getAuthIdentifier(): mixed { return 1; }
            public function getAuthPasswordName(): string { return 'password'; }
            public function getAuthPassword(): string { return ''; }
            public function getRememberToken(): ?string { return null; }
            public function setRememberToken($value): void {}
            public function getRememberTokenName(): string { return 'remember_token'; }
        };
    }

    // -------------------------------------------------------------------------
    // 1. Consentement
    // -------------------------------------------------------------------------

    #[Test]
    public function test_tracking_bloque_si_consentement_requis_et_absent(): void
    {
        Queue::fake();
        $this->baseConfig(['statamic-analytics.tracking.consent.enabled' => true]);
        // Aucune valeur 'analytics_consent' en session → null → bloqué

        (new TrackPageVisit())->handle($this->makeRequest(), fn($req) => $this->htmlResponse());

        Queue::assertNothingPushed();
    }

    #[Test]
    public function test_tracking_bloque_si_consentement_refuse(): void
    {
        Queue::fake();
        $this->baseConfig(['statamic-analytics.tracking.consent.enabled' => true]);

        $request = $this->makeRequest();
        $request->session()->put('analytics_consent', false);

        (new TrackPageVisit())->handle($request, fn($req) => $this->htmlResponse());

        Queue::assertNothingPushed();
    }

    #[Test]
    public function test_tracking_autorise_si_consentement_accepte(): void
    {
        Queue::fake();
        $this->baseConfig(['statamic-analytics.tracking.consent.enabled' => true]);

        $request = $this->makeRequest();
        $request->session()->put('analytics_consent', true);

        (new TrackPageVisit())->handle($request, fn($req) => $this->htmlResponse());

        Queue::assertPushed(TrackPageViewJob::class);
    }

    #[Test]
    public function test_tracking_autorise_si_consentement_desactive(): void
    {
        Queue::fake();
        $this->baseConfig(); // consent.enabled = false

        (new TrackPageVisit())->handle($this->makeRequest(), fn($req) => $this->htmlResponse());

        Queue::assertPushed(TrackPageViewJob::class);
    }

    // -------------------------------------------------------------------------
    // 2. Chemin exclu
    // -------------------------------------------------------------------------

    #[Test]
    public function test_tracking_bloque_sur_chemin_exclu(): void
    {
        Queue::fake();
        $this->baseConfig(['statamic-analytics.tracking.exclude_paths' => ['cp/*']]);

        (new TrackPageVisit())->handle(
            $this->makeRequest('/cp/dashboard'),
            fn($req) => $this->htmlResponse()
        );

        Queue::assertNothingPushed();
    }

    #[Test]
    public function test_tracking_autorise_sur_chemin_non_exclu(): void
    {
        Queue::fake();
        $this->baseConfig(['statamic-analytics.tracking.exclude_paths' => ['cp/*']]);

        (new TrackPageVisit())->handle(
            $this->makeRequest('/contact'),
            fn($req) => $this->htmlResponse()
        );

        Queue::assertPushed(TrackPageViewJob::class);
    }

    // -------------------------------------------------------------------------
    // 3. IP exclue
    // -------------------------------------------------------------------------

    #[Test]
    public function test_tracking_bloque_sur_ip_exclue(): void
    {
        Queue::fake();
        $this->baseConfig(['statamic-analytics.tracking.exclude_ips' => ['203.0.113.9']]);

        (new TrackPageVisit())->handle(
            $this->makeRequest('/page-test', ['REMOTE_ADDR' => '203.0.113.9']),
            fn($req) => $this->htmlResponse()
        );

        Queue::assertNothingPushed();
    }

    // -------------------------------------------------------------------------
    // 4. Bot
    // -------------------------------------------------------------------------

    #[Test]
    public function test_tracking_bloque_sur_bot_detecte(): void
    {
        Queue::fake();
        $this->baseConfig(['statamic-analytics.tracking.exclude_bots' => true]);

        $middleware = $this->middlewareWithAgent(
            'Googlebot/2.1 (+http://www.google.com/bot.html)'
        );

        $middleware->handle($this->makeRequest(), fn($req) => $this->htmlResponse());

        Queue::assertNothingPushed();
    }

    #[Test]
    public function test_tracking_autorise_sur_navigateur_normal(): void
    {
        Queue::fake();
        $this->baseConfig(['statamic-analytics.tracking.exclude_bots' => true]);

        $middleware = $this->middlewareWithAgent(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        );

        $middleware->handle($this->makeRequest(), fn($req) => $this->htmlResponse());

        Queue::assertPushed(TrackPageViewJob::class);
    }

    // -------------------------------------------------------------------------
    // 5. Utilisateur authentifié
    //
    // $this->actingAs() pose l'utilisateur sur le guard par défaut ('web') sans
    // passer par le provider — auth()->check() retourne true sans dépendance
    // envers le système de fichiers Statamic.
    // -------------------------------------------------------------------------

    #[Test]
    public function test_tracking_bloque_utilisateur_authentifie_si_desactive(): void
    {
        Queue::fake();
        $this->baseConfig(['statamic-analytics.tracking.track_authenticated_users' => false]);
        $this->actingAs($this->fakeUser());

        (new TrackPageVisit())->handle($this->makeRequest(), fn($req) => $this->htmlResponse());

        Queue::assertNothingPushed();
    }

    #[Test]
    public function test_tracking_autorise_utilisateur_authentifie_si_active(): void
    {
        Queue::fake();
        $this->baseConfig(['statamic-analytics.tracking.track_authenticated_users' => true]);
        $this->actingAs($this->fakeUser());

        (new TrackPageVisit())->handle($this->makeRequest(), fn($req) => $this->htmlResponse());

        Queue::assertPushed(TrackPageViewJob::class);
    }

    // -------------------------------------------------------------------------
    // 6. Codes de réponse (isTrackableResponse)
    //
    // Le cas 200 text/html est couvert par les tests "autorise" ci-dessus
    // et par TrackPageVisitQueueTest::dispatche_un_job_quand_queue_connection_est_configure
    // — pas de duplication ici.
    // -------------------------------------------------------------------------

    #[Test]
    public function test_tracking_ignore_reponse_404(): void
    {
        Queue::fake();
        $this->baseConfig();

        (new TrackPageVisit())->handle(
            $this->makeRequest(),
            fn($req) => new Response('Not Found', 404)
        );

        Queue::assertNothingPushed();
    }

    #[Test]
    public function test_tracking_ignore_reponse_500(): void
    {
        Queue::fake();
        $this->baseConfig();

        (new TrackPageVisit())->handle(
            $this->makeRequest(),
            fn($req) => new Response('Error', 500)
        );

        Queue::assertNothingPushed();
    }

    #[Test]
    public function test_tracking_ignore_reponse_json(): void
    {
        Queue::fake();
        $this->baseConfig();

        (new TrackPageVisit())->handle(
            $this->makeRequest(),
            fn($req) => new Response('{}', 200, ['Content-Type' => 'application/json'])
        );

        Queue::assertNothingPushed();
    }
}
