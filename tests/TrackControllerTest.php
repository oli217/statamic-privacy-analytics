<?php

namespace Oliweb\StatamicAnalytics\Tests;

use Illuminate\Support\Facades\Queue;
use Oliweb\StatamicAnalytics\Jobs\TrackPageViewJob;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests HTTP du beacon endpoint GET /statamic-analytics/track.
 *
 * Couverture :
 *   1. Validation des paramètres obligatoires (visitor_id, session_id, flags n/nd/nh/np)
 *   2. Filtrage IP exclue
 *   3. Filtrage chemin — via fallback client ET via header Referer (test indirect
 *      de la dérivation page_url côté serveur)
 *   4. Filtrage bot (User-Agent)
 *
 * Stratégie Queue : Queue::fake() + assertPushed/assertNothingPushed.
 * Le job n'est jamais exécuté → pas de dépendance vers GeolocationService ni DB.
 */
class TrackControllerTest extends TestCase
{
    private string $endpoint = '/statamic-analytics/track';

    private function validParams(array $override = []): array
    {
        return array_merge([
            'visitor_id' => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11',
            'session_id' => 'b1ffcd00-0d1c-5f09-cc7e-7cc0ce491b22',
            'n'          => '0',
            'nd'         => '0',
            'nh'         => '0',
            'np'         => '1',
        ], $override);
    }

    private function baseConfig(array $override = []): void
    {
        config(array_merge([
            'statamic-analytics.tracking.exclude_ips'               => [],
            'statamic-analytics.tracking.exclude_paths'             => [],
            'statamic-analytics.tracking.exclude_bots'              => false,
            'statamic-analytics.tracking.track_authenticated_users' => true,
            'statamic-analytics.tracking.queue_connection'          => 'sync',
            'statamic-analytics.tracking.queue_name'                => 'analytics',
        ], $override));
    }

    /**
     * Lance un GET beacon.
     *
     * REMOTE_ADDR par défaut : 203.0.113.1 (RFC 5737 TEST-NET-3, hors listes d'exclusion réelles).
     */
    private function beacon(array $params, array $headers = [], array $server = [])
    {
        return $this
            ->withHeaders($headers)
            ->withServerVariables(array_merge(['REMOTE_ADDR' => '203.0.113.1'], $server))
            ->get($this->endpoint . '?' . http_build_query($params));
    }

    // -------------------------------------------------------------------------
    // 1. Validation
    // -------------------------------------------------------------------------

    #[Test]
    public function test_beacon_valide_retourne_204_et_dispatche_un_job(): void
    {
        Queue::fake();
        $this->baseConfig();

        $this->beacon($this->validParams())->assertNoContent();
        Queue::assertPushed(TrackPageViewJob::class);
    }

    #[Test]
    public function test_visitor_id_absent_retourne_422(): void
    {
        Queue::fake();
        $this->baseConfig();

        $params = $this->validParams();
        unset($params['visitor_id']);

        $this->beacon($params)->assertStatus(422);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function test_visitor_id_non_uuid_retourne_422(): void
    {
        Queue::fake();
        $this->baseConfig();

        $this->beacon($this->validParams(['visitor_id' => 'pas-un-uuid']))->assertStatus(422);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function test_session_id_absent_retourne_422(): void
    {
        Queue::fake();
        $this->baseConfig();

        $params = $this->validParams();
        unset($params['session_id']);

        $this->beacon($params)->assertStatus(422);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function test_flag_n_hors_intervalle_retourne_422(): void
    {
        Queue::fake();
        $this->baseConfig();

        $this->beacon($this->validParams(['n' => '2']))->assertStatus(422);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function test_flag_nd_non_numerique_retourne_422(): void
    {
        Queue::fake();
        $this->baseConfig();

        $this->beacon($this->validParams(['nd' => 'yes']))->assertStatus(422);
        Queue::assertNothingPushed();
    }

    // -------------------------------------------------------------------------
    // 2. Filtrage IP
    // -------------------------------------------------------------------------

    #[Test]
    public function test_ip_exclue_retourne_204_sans_job(): void
    {
        Queue::fake();
        $this->baseConfig(['statamic-analytics.tracking.exclude_ips' => ['203.0.113.1']]);

        $this->beacon($this->validParams())->assertNoContent();
        Queue::assertNothingPushed();
    }

    #[Test]
    public function test_ip_non_exclue_dispatche_un_job(): void
    {
        Queue::fake();
        $this->baseConfig(['statamic-analytics.tracking.exclude_ips' => ['9.9.9.9']]);

        $this->beacon($this->validParams())->assertNoContent();
        Queue::assertPushed(TrackPageViewJob::class);
    }

    // -------------------------------------------------------------------------
    // 3. Filtrage chemin
    //
    // Deux cas couverts :
    //   a) Referer absent  → controller utilise page_url du query string (fallback client)
    //   b) Referer présent → controller dérive page_url depuis le header
    //      Le cas b) teste indirectement la priorité du Referer sur le query string :
    //      si page_url client = "page-normale" (non exclue) mais Referer = "api/*" (exclu),
    //      aucun job ne doit être dispatché.
    // -------------------------------------------------------------------------

    #[Test]
    public function test_chemin_exclu_fallback_client_retourne_204_sans_job(): void
    {
        Queue::fake();
        $this->baseConfig(['statamic-analytics.tracking.exclude_paths' => ['api/*']]);

        $this->beacon($this->validParams(['page_url' => 'api/data']))->assertNoContent();
        Queue::assertNothingPushed();
    }

    #[Test]
    public function test_chemin_exclu_derive_du_referer_retourne_204_sans_job(): void
    {
        Queue::fake();
        $this->baseConfig(['statamic-analytics.tracking.exclude_paths' => ['api/*']]);

        $this->beacon(
            $this->validParams(['page_url' => 'page-normale']),
            ['Referer' => 'https://example.com/api/data']
        )->assertNoContent();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function test_chemin_non_exclu_via_referer_dispatche_un_job(): void
    {
        Queue::fake();
        $this->baseConfig(['statamic-analytics.tracking.exclude_paths' => ['api/*']]);

        $this->beacon(
            $this->validParams(),
            ['Referer' => 'https://example.com/contact']
        )->assertNoContent();

        Queue::assertPushed(TrackPageViewJob::class);
    }

    // -------------------------------------------------------------------------
    // 4. Filtrage bot
    // -------------------------------------------------------------------------

    #[Test]
    public function test_bot_detecte_retourne_204_sans_job(): void
    {
        Queue::fake();
        $this->baseConfig(['statamic-analytics.tracking.exclude_bots' => true]);

        $this->beacon(
            $this->validParams(),
            ['User-Agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)']
        )->assertNoContent();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function test_navigateur_classique_dispatche_un_job(): void
    {
        Queue::fake();
        $this->baseConfig(['statamic-analytics.tracking.exclude_bots' => true]);

        $this->beacon(
            $this->validParams(),
            ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36']
        )->assertNoContent();

        Queue::assertPushed(TrackPageViewJob::class);
    }
}
