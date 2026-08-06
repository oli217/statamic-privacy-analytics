<?php

namespace Oliweb\StatamicAnalytics\Tests;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Oliweb\StatamicAnalytics\Services\PageViewRecorder;
use PHPUnit\Framework\Attributes\Test;

class PageViewRecorderIdempotenceTest extends TestCase
{
    private function baseData(string $eventId): array
    {
        $now = Carbon::now();
        return [
            'event_id'   => $eventId,
            'page_url'   => '/test',
            'ip_address' => null, // évite tout appel géoloc (GeolocationService::isPrivateIp filtre null → emptyResult)
            'user_agent' => null,
            'user_id'    => null,
            'visitor_id' => (string) Str::uuid(),
            'session_id' => (string) Str::uuid(),
            'is_new_visitor'    => false,
            'is_new_day_visit'  => false,
            'is_new_hour_visit' => false,
            'is_new_page_visit' => false,
            'visited_at' => $now->format('Y-m-d H:i:s'),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    // -------------------------------------------------------------------------
    // 1. Même event_id → une seule ligne
    // -------------------------------------------------------------------------

    #[Test]
    public function test_deux_insertions_meme_event_id_ne_creent_qu_une_ligne(): void
    {
        config(['statamic-analytics.geolocation.provider' => 'disabled']);

        $eventId = (string) Str::uuid();
        $recorder = new PageViewRecorder();

        $recorder->record($this->baseData($eventId));
        $recorder->record($this->baseData($eventId));

        $this->assertSame(
            1,
            DB::table('statamic_analytics_page_views')->where('event_id', $eventId)->count()
        );
    }

    // -------------------------------------------------------------------------
    // 2. Event IDs différents → deux lignes distinctes
    // -------------------------------------------------------------------------

    #[Test]
    public function test_deux_event_id_differents_creent_deux_lignes(): void
    {
        config(['statamic-analytics.geolocation.provider' => 'disabled']);

        $recorder = new PageViewRecorder();
        $id1 = (string) Str::uuid();
        $id2 = (string) Str::uuid();

        $recorder->record($this->baseData($id1));
        $recorder->record($this->baseData($id2));

        $this->assertSame(
            1,
            DB::table('statamic_analytics_page_views')->where('event_id', $id1)->count()
        );
        $this->assertSame(
            1,
            DB::table('statamic_analytics_page_views')->where('event_id', $id2)->count()
        );
    }

    // -------------------------------------------------------------------------
    // 3. Lignes sans event_id (rétrocompatibilité) → pas de violation de contrainte
    // -------------------------------------------------------------------------

    #[Test]
    public function test_lignes_sans_event_id_restent_possibles(): void
    {
        $now = Carbon::now();
        $base = [
            'page_url'   => '/test',
            'ip_address' => '203.0.113.1',
            'visited_at' => $now->format('Y-m-d H:i:s'),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        // Deux insertions directes sans event_id — ne doivent pas lever d'exception
        DB::table('statamic_analytics_page_views')->insert($base);
        DB::table('statamic_analytics_page_views')->insert($base);

        $this->assertSame(
            2,
            DB::table('statamic_analytics_page_views')->whereNull('event_id')->count()
        );
    }
}
