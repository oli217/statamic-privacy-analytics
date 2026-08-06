<?php

namespace Oliweb\StatamicAnalytics\Tests;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Queue;
use Oliweb\StatamicAnalytics\Jobs\TrackPageViewJob;
use Oliweb\StatamicAnalytics\Middleware\TrackPageVisit;
use PHPUnit\Framework\Attributes\Test;

class TrackPageVisitQueueTest extends TestCase
{
    private function makeRequest(string $path = '/page-test'): Request
    {
        $session = $this->app['session']->driver('array');
        $session->start();

        $request = Request::create($path, 'GET');
        $request->setLaravelSession($session);

        return $request;
    }

    private function htmlResponse(): Response
    {
        return new Response('OK', 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    #[Test]
    public function dispatche_un_job_quand_queue_connection_est_configure(): void
    {
        Queue::fake();

        config([
            'statamic-analytics.tracking.consent.enabled'  => false,
            'statamic-analytics.tracking.exclude_ips'      => [],
            'statamic-analytics.tracking.queue_connection'  => 'sync',
            'statamic-analytics.tracking.queue_name'        => 'analytics',
        ]);

        $response = $this->htmlResponse();

        (new TrackPageVisit())->handle($this->makeRequest(), fn($req) => $response);

        Queue::assertPushed(TrackPageViewJob::class);
    }

    #[Test]
    public function ne_dispatche_pas_de_job_quand_queue_connection_est_null(): void
    {
        Queue::fake();

        config([
            'statamic-analytics.tracking.consent.enabled'  => false,
            'statamic-analytics.tracking.queue_connection'  => null,
        ]);

        $response = $this->htmlResponse();

        // L'écriture synchrone via PageViewRecorder échouera (pas de table en test),
        // l'exception est catchée dans le middleware — seul le comportement queue nous importe.
        (new TrackPageVisit())->handle($this->makeRequest(), fn($req) => $response);

        Queue::assertNothingPushed();
    }
}
