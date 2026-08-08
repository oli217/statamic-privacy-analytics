<?php

namespace Oliweb\StatamicAnalytics\Middleware;

use Closure;
use DeviceDetector\DeviceDetector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Oliweb\StatamicAnalytics\Jobs\TrackPageViewJob;
use Oliweb\StatamicAnalytics\Services\GeolocationService;
use Oliweb\StatamicAnalytics\Services\PageViewRecorder;

class TrackPageVisit
{
    protected DeviceDetector $deviceDetector;

    public function __construct(?DeviceDetector $deviceDetector = null)
    {
        $this->deviceDetector = $deviceDetector ?? new DeviceDetector();
    }

    public static function getGeolocationStats(): array
    {
        return GeolocationService::getStats();
    }

    public static function resetStats(): void
    {
        GeolocationService::resetStats();
    }

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        try {
            // Configure le détecteur depuis la requête (ignoré si pré-configuré, ex: tests)
            if (!$this->deviceDetector->isParsed()) {
                $this->deviceDetector->setUserAgent($request->userAgent() ?? '');
                $this->deviceDetector->parse();
            }

            if ($this->shouldTrack($request) && $this->isTrackableResponse($response)) {
                $now = now();
                $today = $now->format('Y-m-d');
                $currentHour = $now->format('Y-m-d H');

                if (!$request->session()->has('analytics_session_started')) {
                    $request->session()->put('analytics_session_started', true);
                }

                // Generate or get visitor ID
                $isNewVisitor = !$request->session()->has('visitor_id');
                $visitorId = $isNewVisitor ? (string) Str::uuid() : $request->session()->get('visitor_id');

                if ($isNewVisitor) {
                    $request->session()->put('visitor_id', $visitorId);
                    $request->session()->put('visited_pages', []);
                    $request->session()->put('last_visit_date', null);
                    $request->session()->put('last_visit_hour', null);
                }

                $pageUrl = $request->path();
                $ipAddress = $request->ip();

                // Track page uniqueness per session
                $visitedPages = $request->session()->get('visited_pages', []);
                $isNewPageVisit = !in_array($pageUrl, $visitedPages);

                if ($isNewPageVisit) {
                    $visitedPages[] = $pageUrl;
                    if (count($visitedPages) > 20) {
                        $visitedPages = array_slice($visitedPages, -20);
                    }
                    $request->session()->put('visited_pages', array_unique($visitedPages));
                }

                $lastVisitDate = $request->session()->get('last_visit_date');
                $lastVisitHour = $request->session()->get('last_visit_hour');

                $data = [
                    'event_id'          => (string) Str::uuid(),
                    'page_url'          => $pageUrl,
                    'ip_address'        => $ipAddress,
                    'user_agent'        => $request->userAgent(),
                    'device_type'       => $this->getDeviceType(),
                    'browser'           => $this->deviceDetector->getClient('name'),
                    'platform'          => $this->deviceDetector->getOs('name'),
                    'referrer_url'      => $this->sanitizeReferrer($request->header('referer')),
                    'user_id'           => auth()->id(),
                    'session_id'        => $request->session()->getId(),
                    'visitor_id'        => $visitorId,
                    'is_new_visitor'    => $isNewVisitor,
                    'is_new_day_visit'  => $lastVisitDate !== $today,
                    'is_new_hour_visit' => $lastVisitHour !== $currentHour,
                    'is_new_page_visit' => $isNewPageVisit,
                    'visited_at'        => $now->format('Y-m-d H:i:s'),
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ];

                $queueConnection = config('statamic-analytics.tracking.queue_connection');

                if ($queueConnection) {
                    try {
                        TrackPageViewJob::dispatch($data)
                            ->onConnection($queueConnection)
                            ->onQueue(config('statamic-analytics.tracking.queue_name', 'analytics'));
                    } catch (\Exception $e) {
                        Log::error('StatamicAnalytics: échec du dispatch en file d\'attente, retour en écriture synchrone', [
                            'error' => $e->getMessage(),
                        ]);
                        (new PageViewRecorder())->record($data);
                    }
                } else {
                    (new PageViewRecorder())->record($data);
                }

                // Update session timestamps
                $request->session()->put('last_visit_date', $today);
                $request->session()->put('last_visit_hour', $currentHour);
            }
        } catch (\Exception $e) {
            Log::error('Enhanced Analytics: Error in middleware', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        return $response;
    }

    protected function isTrackableResponse($response): bool
    {
        if ($response->getStatusCode() !== 200) {
            return false;
        }

        $contentType = $response->headers->get('Content-Type', '');

        return Str::startsWith($contentType, 'text/html');
    }

    protected function shouldTrack(Request $request): bool
    {
        // Check if consent is enabled and given
        if (config('statamic-analytics.tracking.consent.enabled', true)) {
            $consent = session('analytics_consent');

            if (is_null($consent)) {
                return false;
            }
            if ($consent === false) {
                return false;
            }
            if ($consent !== true) {
                return false;
            }
        }

        $excludedPaths = config('statamic-analytics.tracking.exclude_paths', []);
        $excludedIps = config('statamic-analytics.tracking.exclude_ips', []);
        $excludeBots = config('statamic-analytics.tracking.exclude_bots', true);
        $trackAuthenticated = config('statamic-analytics.tracking.track_authenticated_users', true);

        foreach ($excludedPaths as $path) {
            if (Str::is($path, $request->path())) {
                return false;
            }
        }

        if (in_array($request->ip(), $excludedIps)) {
            return false;
        }

        if ($excludeBots && $this->deviceDetector->isBot()) {
            return false;
        }

        if (!$trackAuthenticated && auth()->check()) {
            return false;
        }

        return true;
    }

    protected function getDeviceType(): string
    {
        if ($this->deviceDetector->isTablet()) {
            return 'tablet';
        }

        if ($this->deviceDetector->isMobile()) {
            return 'mobile';
        }

        return 'desktop';
    }

    protected function sanitizeReferrer(?string $referer): ?string
    {
        if (!$referer) {
            return null;
        }

        $parts = parse_url($referer);

        if ($parts === false || !isset($parts['host'])) {
            return null;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host   = $parts['host'];
        $path   = $parts['path'] ?? '';

        return "{$scheme}://{$host}{$path}";
    }
}
