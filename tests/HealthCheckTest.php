<?php

namespace Oliweb\StatamicAnalytics\Tests;

use PHPUnit\Framework\Attributes\Test;

/**
 * Tests de la commande analytics:health.
 *
 * Environnement de test :
 *   - DB SQLite en mémoire + migrations exécutées → tables présentes
 *   - Cache driver : array → opérationnel
 *   - APP_KEY défini par Testbench → chiffrement OK
 *   - Pas de fichier de log scheduler → warning attendu (ignoré dans les tests)
 *   - failed_jobs table absente → warning attendu (ignoré dans les tests)
 *
 * Stratégie : configNoErrors() neutralise les vérifications fragiles
 * (géolocalisation, queue) pour obtenir un état SUCCESS de référence,
 * puis chaque test fait varier UN paramètre à la fois.
 */
class HealthCheckTest extends TestCase
{
    /**
     * Config minimale garantissant SUCCESS (0) dans l'environnement de test.
     *
     * - geo = disabled : évite la vérification du fichier MaxMind (inexistant en CI)
     * - queue_connection = null : mode synchrone, pas de vérification de connexion
     * - static_caching.strategy = null : static cache désactivé
     */
    private function configNoErrors(): void
    {
        config([
            'statamic-analytics.geolocation.provider'      => 'disabled',
            'statamic-analytics.tracking.queue_connection' => null,
            'statamic.static_caching.strategy'             => null,
        ]);
    }

    // -------------------------------------------------------------------------
    // 1. Cas nominal — pas d'erreur bloquante
    // -------------------------------------------------------------------------

    #[Test]
    public function test_retourne_success_sans_erreur(): void
    {
        $this->configNoErrors();

        $this->artisan('analytics:health')->assertExitCode(0);
    }

    #[Test]
    public function test_configuration_chargee_affiche_ok(): void
    {
        $this->configNoErrors();

        $this->artisan('analytics:health')
            ->expectsOutputToContain('Configuration')
            ->assertExitCode(0);
    }

    #[Test]
    public function test_tables_presentes_affiche_database(): void
    {
        $this->configNoErrors();

        $this->artisan('analytics:health')
            ->expectsOutputToContain('Database')
            ->assertExitCode(0);
    }

    #[Test]
    public function test_chiffrement_ok_si_app_key_definie(): void
    {
        $this->configNoErrors();

        $this->artisan('analytics:health')
            ->expectsOutputToContain('Encryption')
            ->assertExitCode(0);
    }

    // -------------------------------------------------------------------------
    // 2. Queue
    // -------------------------------------------------------------------------

    #[Test]
    public function test_queue_synchrone_affiche_ok(): void
    {
        $this->configNoErrors(); // queue_connection = null → synchronous

        $this->artisan('analytics:health')
            ->expectsOutputToContain('Queue')
            ->assertExitCode(0);
    }

    #[Test]
    public function test_queue_connexion_inconnue_retourne_failure(): void
    {
        $this->configNoErrors();
        config(['statamic-analytics.tracking.queue_connection' => 'connexion-inexistante']);

        $this->artisan('analytics:health')->assertExitCode(1);
    }

    #[Test]
    public function test_queue_connexion_sync_valide_retourne_success(): void
    {
        $this->configNoErrors();
        config(['statamic-analytics.tracking.queue_connection' => 'sync']);

        $this->artisan('analytics:health')->assertExitCode(0);
    }

    // -------------------------------------------------------------------------
    // 3. Géolocalisation
    // -------------------------------------------------------------------------

    #[Test]
    public function test_geo_disabled_ne_retourne_pas_failure(): void
    {
        $this->configNoErrors(); // provider = disabled → warning, pas d'erreur

        $this->artisan('analytics:health')
            ->expectsOutputToContain('Geolocation')
            ->assertExitCode(0);
    }

    #[Test]
    public function test_geo_provider_inconnu_retourne_failure(): void
    {
        $this->configNoErrors();
        config(['statamic-analytics.geolocation.provider' => 'fournisseur-inconnu']);

        $this->artisan('analytics:health')->assertExitCode(1);
    }

    // -------------------------------------------------------------------------
    // 4. Static caching
    // -------------------------------------------------------------------------

    #[Test]
    public function test_static_cache_non_configure_affiche_not_enabled(): void
    {
        $this->configNoErrors();

        $this->artisan('analytics:health')
            ->expectsOutputToContain('Static cache')
            ->assertExitCode(0);
    }

    #[Test]
    public function test_static_cache_full_affiche_detail_beacon_js(): void
    {
        $this->configNoErrors();
        config(['statamic.static_caching.strategy' => 'full']);

        $this->artisan('analytics:health')
            ->expectsOutputToContain('beacon JS')
            ->assertExitCode(0);
    }

    #[Test]
    public function test_static_cache_half_affiche_strategy_half(): void
    {
        $this->configNoErrors();
        config(['statamic.static_caching.strategy' => 'half']);

        $this->artisan('analytics:health')
            ->expectsOutputToContain('half')
            ->assertExitCode(0);
    }

    // -------------------------------------------------------------------------
    // 5. Beacon endpoint
    // -------------------------------------------------------------------------

    #[Test]
    public function test_beacon_endpoint_present_affiche_ok(): void
    {
        $this->configNoErrors();

        $this->artisan('analytics:health')
            ->expectsOutputToContain('Beacon endpoint')
            ->assertExitCode(0);
    }
}
