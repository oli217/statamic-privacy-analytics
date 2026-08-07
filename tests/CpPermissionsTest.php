<?php

namespace Oliweb\StatamicAnalytics\Tests;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Auth\PermissionCache;
use Statamic\Facades\User;
use Statamic\Http\Middleware\CP\ContactOutpost;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

/**
 * Tests d'intégration HTTP pour les permissions CP introduites en v4.4.0.
 *
 * Architecture Statamic CP (à comprendre pour interpréter les assertions) :
 *
 * Les routes additionnelles d'addons sont chargées dans le groupe
 * `statamic.cp.authenticated`, qui inclut le middleware `Authorize`.
 * Ce middleware vérifie `access cp` et, en cas de refus, délègue à
 * `ControlPanelExceptionHandler` (via `SwapCpExceptionHandler`) qui
 * convertit toute `AuthorizationException` en redirect 302 vers /cp
 * (avec toast d'erreur), pas en 403.
 *
 * Conséquences sur les assertions :
 * - Accès autorisé  → assertOk() (200)
 * - Accès refusé    → assertRedirect() (302 vers /cp)
 * - Non authentifié → assertRedirect() (302 vers /cp/auth/login)
 *
 * ⚠️ Inertia middleware et StreamedResponse :
 * `withHeader('X-Inertia', 'true')` modifie les `$this->defaultHeaders`
 * de façon persistante — le header contaminerait TOUTES les requêtes
 * suivantes du même test, déclenchant `onEmptyResponse()` (Redirect::back())
 * sur la route `export` (StreamedResponse). Solution : passer le header
 * directement dans get($url, ['X-Inertia' => 'true']) pour qu'il ne
 * s'applique qu'à cette seule requête.
 *
 * Stratégie d'isolation :
 * - PreventsSavingStacheItemsToDisk : redirige les I/O Stache vers dev-null.
 * - $user->save() : nécessaire pour UserProvider::retrieveById() lors des
 *   requêtes HTTP simulées.
 * - PermissionCache::put() : injecte les permissions sans créer de fichiers
 *   de rôles. Inclut toujours 'access cp' (seuil minimum pour passer
 *   l'Authorize middleware et atteindre nos vérifications `can:`).
 * - ContactOutpost désactivé : évite les appels HTTP vers outpost.statamic.com.
 * - VerifyCsrfToken désactivé : tests POST sans session CSRF.
 */
class CpPermissionsTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            ContactOutpost::class,
            VerifyCsrfToken::class,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Crée un utilisateur en mémoire avec les permissions données.
     * 'access cp' est toujours inclus (nécessaire pour passer Authorize).
     */
    private function userWithPermissions(string $id, array $permissions)
    {
        $user = User::make()->email($id . '@test.local')->id($id);
        $user->save();
        app(PermissionCache::class)->put($id, collect(array_merge(['access cp'], $permissions)));

        return $user;
    }

    private function superAdmin()
    {
        $user = User::make()->email('super@test.local')->id('super-admin')->makeSuper();
        $user->save();

        return $user;
    }

    // -------------------------------------------------------------------------
    // 1. Aucune permission analytics → redirect sur routes analytics.view
    // -------------------------------------------------------------------------

    #[Test]
    public function test_utilisateur_sans_permission_analytics_est_redirige_sur_routes_view(): void
    {
        $user = $this->userWithPermissions('no-perm', []);

        $this->actingAs($user)
            ->get(route('statamic.cp.statamic-analytics.index'), ['X-Inertia' => 'true'])
            ->assertRedirect();

        $this->actingAs($user)
            ->get(route('statamic.cp.statamic-analytics.data'))
            ->assertRedirect();
    }

    // -------------------------------------------------------------------------
    // 2. analytics.view → 200 sur les quatre routes de lecture
    // -------------------------------------------------------------------------

    #[Test]
    public function test_utilisateur_avec_analytics_view_accede_aux_routes_de_lecture(): void
    {
        $user = $this->userWithPermissions('view-user', ['analytics.view']);

        $this->actingAs($user)
            ->get(route('statamic.cp.statamic-analytics.index'), ['X-Inertia' => 'true'])
            ->assertOk();

        $this->actingAs($user)
            ->get(route('statamic.cp.statamic-analytics.data'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('statamic.cp.statamic-analytics.geo-stats'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('statamic.cp.statamic-analytics.realtime'))
            ->assertOk();
    }

    // -------------------------------------------------------------------------
    // 3. analytics.view seul → redirect sur export et reset-stats
    // -------------------------------------------------------------------------

    #[Test]
    public function test_utilisateur_avec_analytics_view_seul_est_redirige_sur_export_et_reset(): void
    {
        $user = $this->userWithPermissions('view-only', ['analytics.view']);

        $this->actingAs($user)
            ->get(route('statamic.cp.statamic-analytics.export'))
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('statamic.cp.statamic-analytics.reset-stats'))
            ->assertRedirect();
    }

    // -------------------------------------------------------------------------
    // 4. analytics.manage → 200 sur export/reset, redirect sur index/data
    // -------------------------------------------------------------------------

    #[Test]
    public function test_utilisateur_avec_analytics_manage_accede_a_export_et_reset(): void
    {
        $user = $this->userWithPermissions('manage-user', ['analytics.manage']);

        $this->actingAs($user)
            ->get(route('statamic.cp.statamic-analytics.export'))
            ->assertOk();

        $this->actingAs($user)
            ->post(route('statamic.cp.statamic-analytics.reset-stats'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('statamic.cp.statamic-analytics.index'), ['X-Inertia' => 'true'])
            ->assertRedirect();

        $this->actingAs($user)
            ->get(route('statamic.cp.statamic-analytics.data'))
            ->assertRedirect();
    }

    // -------------------------------------------------------------------------
    // 5. Super-admin → 200 sur toutes les routes sans permission explicite
    // -------------------------------------------------------------------------

    #[Test]
    public function test_super_admin_accede_a_tout_sans_permission_explicite(): void
    {
        $user = $this->superAdmin();

        $this->actingAs($user)
            ->get(route('statamic.cp.statamic-analytics.index'), ['X-Inertia' => 'true'])
            ->assertOk();

        $this->actingAs($user)
            ->get(route('statamic.cp.statamic-analytics.data'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('statamic.cp.statamic-analytics.geo-stats'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('statamic.cp.statamic-analytics.realtime'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('statamic.cp.statamic-analytics.export'))
            ->assertOk();

        $this->actingAs($user)
            ->post(route('statamic.cp.statamic-analytics.reset-stats'))
            ->assertOk();
    }

    // -------------------------------------------------------------------------
    // 6. Utilisateur non authentifié → redirigé vers la page de login
    // -------------------------------------------------------------------------

    #[Test]
    public function test_utilisateur_non_authentifie_redirige_vers_login(): void
    {
        $this->get(route('statamic.cp.statamic-analytics.data'))
            ->assertRedirect();
    }
}
