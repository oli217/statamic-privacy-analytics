<?php

namespace Oliweb\StatamicAnalytics\Tests;

use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Oliweb\StatamicAnalytics\Services\GeolocationService;
use PHPUnit\Framework\Attributes\Test;

class GeolocationServiceTest extends TestCase
{
    private function emptyResult(): array
    {
        return ['country_code' => null, 'country_name' => null, 'city' => null];
    }

    // -------------------------------------------------------------------------
    // 1. IP privées / loopback — jamais d'appel externe
    // -------------------------------------------------------------------------

    #[Test]
    public function test_ip_privee_ipv4_retourne_resultat_vide(): void
    {
        config(['statamic-analytics.geolocation.provider' => 'ip-api']);
        Http::fake();

        $result = (new GeolocationService())->lookup('192.168.1.1');

        Http::assertNothingSent();
        $this->assertSame($this->emptyResult(), $result);
    }

    #[Test]
    public function test_ip_loopback_ipv4_retourne_resultat_vide(): void
    {
        config(['statamic-analytics.geolocation.provider' => 'ip-api']);
        Http::fake();

        $result = (new GeolocationService())->lookup('127.0.0.1');

        Http::assertNothingSent();
        $this->assertSame($this->emptyResult(), $result);
    }

    #[Test]
    public function test_ip_loopback_ipv6_retourne_resultat_vide(): void
    {
        config(['statamic-analytics.geolocation.provider' => 'ip-api']);
        Http::fake();

        $result = (new GeolocationService())->lookup('::1');

        Http::assertNothingSent();
        $this->assertSame($this->emptyResult(), $result);
    }

    // -------------------------------------------------------------------------
    // 2. IPv4 et IPv6 publiques (provider ip-api)
    // -------------------------------------------------------------------------

    #[Test]
    public function test_lookup_ipv4_publique_succes(): void
    {
        config(['statamic-analytics.geolocation.provider' => 'ip-api']);
        Http::fake([
            'ip-api.com/*' => Http::response([
                'status'      => 'success',
                'countryCode' => 'CH',
                'country'     => 'Switzerland',
                'city'        => 'Geneva',
            ], 200),
        ]);

        $result = (new GeolocationService())->lookup('8.8.8.8');

        $this->assertSame([
            'country_code' => 'CH',
            'country_name' => 'Switzerland',
            'city'         => 'Geneva',
        ], $result);
    }

    #[Test]
    public function test_lookup_ipv6_publique_succes(): void
    {
        // Vérifie que isPrivateIp() n'exclut pas les adresses IPv6 publiques.
        // filter_var avec FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        // accepte 2001:4860:4860::8888 (unicast global, RFC 5952).
        config(['statamic-analytics.geolocation.provider' => 'ip-api']);
        Http::fake([
            'ip-api.com/*' => Http::response([
                'status'      => 'success',
                'countryCode' => 'US',
                'country'     => 'United States',
                'city'        => 'Mountain View',
            ], 200),
        ]);

        $result = (new GeolocationService())->lookup('2001:4860:4860::8888');

        $this->assertSame([
            'country_code' => 'US',
            'country_name' => 'United States',
            'city'         => 'Mountain View',
        ], $result);
    }

    // -------------------------------------------------------------------------
    // 3. MaxMind absent
    // -------------------------------------------------------------------------

    #[Test]
    public function test_maxmind_absent_retourne_resultat_vide(): void
    {
        $path = sys_get_temp_dir() . '/inexistant_' . uniqid() . '.mmdb';

        config([
            'statamic-analytics.geolocation.provider'             => 'maxmind',
            'statamic-analytics.geolocation.maxmind.database_path' => $path,
        ]);

        $result = (new GeolocationService())->lookup('8.8.8.8');

        $this->assertSame($this->emptyResult(), $result);
    }

    // -------------------------------------------------------------------------
    // 4. MaxMind base invalide (fichier présent mais pas un .mmdb)
    // -------------------------------------------------------------------------

    #[Test]
    public function test_maxmind_base_invalide_retourne_resultat_vide(): void
    {
        $path = sys_get_temp_dir() . '/invalid_' . uniqid() . '.mmdb';
        file_put_contents($path, 'contenu invalide');

        try {
            config([
                'statamic-analytics.geolocation.provider'             => 'maxmind',
                'statamic-analytics.geolocation.maxmind.database_path' => $path,
            ]);

            $result = (new GeolocationService())->lookup('8.8.8.8');

            // L'exception levée par GeoIp2\Database\Reader est catchée dans
            // lookupViaMaxMind() → résultat vide, aucune exception ne remonte.
            $this->assertSame($this->emptyResult(), $result);
        } finally {
            @unlink($path);
        }
    }

    // -------------------------------------------------------------------------
    // 5. ip-api timeout / erreur de connexion
    // -------------------------------------------------------------------------

    #[Test]
    public function test_ip_api_timeout_retourne_resultat_vide(): void
    {
        config(['statamic-analytics.geolocation.provider' => 'ip-api']);
        Http::fake([
            'ip-api.com/*' => fn() => throw new ConnectionException('Connection timed out'),
        ]);

        $result = (new GeolocationService())->lookup('8.8.8.8');

        // ConnectionException est catchée dans lookupViaIpApi() — rien ne remonte.
        $this->assertSame($this->emptyResult(), $result);
    }

    // -------------------------------------------------------------------------
    // 6. Rate limit — bloqué AVANT tout appel HTTP
    // -------------------------------------------------------------------------

    #[Test]
    public function test_ip_api_rate_limit_bloque_avant_tout_appel_http(): void
    {
        $now = Carbon::now();
        Carbon::setTestNow($now);

        try {
            config([
                'statamic-analytics.geolocation.provider'             => 'ip-api',
                'statamic-analytics.geolocation.ip_api.rate_limit'    => 45,
            ]);
            Http::fake();

            // Pré-remplir la clé de rate limit pour le fuseau de la minute actuelle,
            // identique au format utilisé dans lookupViaIpApi().
            $rateLimitKey = 'statamic_analytics_geo_ratelimit_' . $now->format('Y-m-d H:i');
            Cache::put($rateLimitKey, 45, 60);

            $result = (new GeolocationService())->lookup('8.8.8.8');

            // Le rate limit doit bloquer avant le moindre appel HTTP.
            Http::assertNothingSent();
            $this->assertSame($this->emptyResult(), $result);
        } finally {
            Carbon::setTestNow(null);
        }
    }

    // -------------------------------------------------------------------------
    // 7. Provider disabled et rétrocompatibilité clé booléenne enabled
    // -------------------------------------------------------------------------

    #[Test]
    public function test_provider_disabled_aucun_appel(): void
    {
        config(['statamic-analytics.geolocation.provider' => 'disabled']);
        Http::fake();

        $result = (new GeolocationService())->lookup('8.8.8.8');

        Http::assertNothingSent();
        $this->assertSame($this->emptyResult(), $result);
    }

    #[Test]
    public function test_retrocompat_enabled_false_equivaut_a_disabled(): void
    {
        // provider = null déclenche le chemin rétrocompat dans resolveProvider().
        config([
            'statamic-analytics.geolocation.provider' => null,
            'statamic-analytics.geolocation.enabled'  => false,
        ]);
        Http::fake();

        $result = (new GeolocationService())->lookup('8.8.8.8');

        Http::assertNothingSent();
        $this->assertSame($this->emptyResult(), $result);
    }

    #[Test]
    public function test_retrocompat_enabled_true_equivaut_a_ip_api(): void
    {
        config([
            'statamic-analytics.geolocation.provider' => null,
            'statamic-analytics.geolocation.enabled'  => true,
        ]);
        Http::fake([
            'ip-api.com/*' => Http::response([
                'status'      => 'success',
                'countryCode' => 'FR',
                'country'     => 'France',
                'city'        => 'Paris',
            ], 200),
        ]);

        $result = (new GeolocationService())->lookup('8.8.8.8');

        $this->assertSame([
            'country_code' => 'FR',
            'country_name' => 'France',
            'city'         => 'Paris',
        ], $result);
    }

    // -------------------------------------------------------------------------
    // 8. Cache — un seul appel HTTP pour deux lookups identiques
    // -------------------------------------------------------------------------

    #[Test]
    public function test_lookup_repete_meme_ip_un_seul_appel_http(): void
    {
        config(['statamic-analytics.geolocation.provider' => 'ip-api']);
        Http::fake([
            'ip-api.com/*' => Http::response([
                'status'      => 'success',
                'countryCode' => 'DE',
                'country'     => 'Germany',
                'city'        => 'Berlin',
            ], 200),
        ]);

        $service = new GeolocationService();
        $service->lookup('8.8.8.8');
        $service->lookup('8.8.8.8');

        // Cache::remember() doit intercepter le second appel — un seul appel HTTP.
        Http::assertSentCount(1);
    }
}
