<?php

namespace Oliweb\StatamicAnalytics\Tests;

use Oliweb\StatamicAnalytics\Widgets\AnalyticsOverviewWidget;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Auth\PermissionCache;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

/**
 * Tests de la garde d'accès analytics.view dans AnalyticsOverviewWidget::html().
 *
 * Pattern d'authentification identique à CpPermissionsTest :
 * - PermissionCache::put() injecte les permissions sans I/O Stache.
 * - actingAs() positionne auth()->user() pour les appels directs (non-HTTP).
 * - PreventsSavingStacheItemsToDisk redirige les I/O Stache vers dev-null.
 */
class AnalyticsOverviewWidgetTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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

    private function widget(): AnalyticsOverviewWidget
    {
        $widget = new AnalyticsOverviewWidget();
        $widget->setConfig([]);
        return $widget;
    }

    // -------------------------------------------------------------------------
    // 1. Sans analytics.view → chaîne vide (absence silencieuse)
    // -------------------------------------------------------------------------

    #[Test]
    public function test_widget_absent_sans_permission_analytics_view(): void
    {
        $user = $this->userWithPermissions('no-view', []);
        $this->actingAs($user);

        $html = $this->widget()->html();

        $this->assertSame('', $html, 'Le widget doit retourner une chaîne vide sans analytics.view.');
    }

    // -------------------------------------------------------------------------
    // 2. Avec analytics.view → HTML rendu avec contenu distinctif
    // -------------------------------------------------------------------------

    #[Test]
    public function test_widget_visible_avec_permission_analytics_view(): void
    {
        $user = $this->userWithPermissions('has-view', ['analytics.view']);
        $this->actingAs($user);

        $html = $this->widget()->html();

        $this->assertStringContainsString(
            'Analytiques',
            $html,
            'Le widget doit rendre son HTML quand l\'utilisateur a analytics.view.'
        );
    }

    // -------------------------------------------------------------------------
    // 3. Super-admin → HTML rendu sans permission explicite
    // -------------------------------------------------------------------------

    #[Test]
    public function test_super_admin_voit_le_widget_sans_permission_explicite(): void
    {
        $user = $this->superAdmin();
        $this->actingAs($user);

        $html = $this->widget()->html();

        $this->assertStringContainsString(
            'Analytiques',
            $html,
            'Le super-admin doit voir le widget sans permission explicite.'
        );
    }
}
