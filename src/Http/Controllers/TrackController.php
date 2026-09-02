<?php

namespace Oliweb\StatamicAnalytics\Http\Controllers;

use DeviceDetector\DeviceDetector;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Oliweb\StatamicAnalytics\Jobs\TrackPageViewJob;
use Oliweb\StatamicAnalytics\Services\PageViewRecorder;

class TrackController extends Controller
{
    /**
     * Reçoit un beacon de tracking depuis le JS (mode static caching full).
     * Route GET — pas de CSRF requis (lecture seule côté navigateur).
     *
     * Modèle de confiance :
     * - page_url    : dérivée du header Referer du beacon, fourni automatiquement par les
     *                 navigateurs (non manipulable via le query string). Un client HTTP arbitraire
     *                 peut cependant forger ce header — risque d'intégrité analytics, pas de
     *                 risque de sécurité. Fallback sur la valeur client si le header est absent
     *                 (Referrer-Policy stricte ou extension navigateur).
     * - referrer_url: fournie par le JS (document.referrer). Le serveur ne peut pas la dériver
     *                 autrement. Valeur client, sanitisée (scheme + host + path uniquement).
     * - visitor_id, session_id : UUIDs générés côté client (localStorage/sessionStorage).
     *                 Protégés par validation de format UUID, rate limiting et bot filtering.
     * - flags n/nd/nh/np : calculés côté client depuis localStorage. Fiables en conditions
     *                 normales ; non critiques pour l'intégrité des totaux agrégés.
     */
    public function track(Request $request)
    {
        try {
            $data = $request->validate([
                // page_url acceptée en fallback uniquement — voir dérivation ci-dessous
                'page_url'     => 'nullable|string|max:2048',
                'referrer_url' => 'nullable|string|max:2048',
                'visitor_id'   => 'required|uuid',
                'session_id'   => 'required|uuid',
                'n'            => 'required|in:0,1',  // is_new_visitor
                'nd'           => 'required|in:0,1',  // is_new_day_visit
                'nh'           => 'required|in:0,1',  // is_new_hour_visit
                'np'           => 'required|in:0,1',  // is_new_page_visit
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->noContent(422);
        }

        // Dériver page_url depuis le header Referer du beacon (source de confiance côté serveur).
        // Le navigateur positionne automatiquement ce header sur la page qui a déclenché le beacon.
        // Fallback sur la valeur client si le header est absent (Referrer-Policy stricte ou
        // extension navigateur bloquant les Referer headers).
        $refererHeader = $request->header('referer', '');
        if ($refererHeader) {
            $parsedPath = parse_url($refererHeader, PHP_URL_PATH);
            $pageUrl    = $parsedPath ?: '/';
        } else {
            $clientPath = ltrim($data['page_url'] ?? '', '/');
            $pageUrl    = '/' . $clientPath;
        }

        $ip = $request->ip();

        // IPs exclues
        if (in_array($ip, config('statamic-analytics.tracking.exclude_ips', []))) {
            return response()->noContent();
        }

        // Chemins exclus
        foreach (config('statamic-analytics.tracking.exclude_paths', []) as $pattern) {
            if (Str::is($pattern, ltrim($pageUrl, '/'))) {
                return response()->noContent();
            }
        }

        // Détection de bot
        $dd = new DeviceDetector($request->userAgent() ?? '');
        $dd->parse();

        if (config('statamic-analytics.tracking.exclude_bots', true) && $dd->isBot()) {
            return response()->noContent();
        }

        // Utilisateurs authentifiés
        if (!config('statamic-analytics.tracking.track_authenticated_users', true) && auth()->check()) {
            return response()->noContent();
        }

        $now = now();

        $record = [
            'event_id'          => (string) Str::uuid(),
            'page_url'          => $pageUrl,
            'ip_address'        => $ip,
            'user_agent'        => $request->userAgent(),
            'device_type'       => $this->getDeviceType($dd),
            'browser'           => $dd->getClient('name'),
            'platform'          => $dd->getOs('name'),
            'referrer_url'      => $this->sanitizeReferrer($data['referrer_url'] ?? null),
            'user_id'           => auth()->id(),
            'session_id'        => $data['session_id'],
            'visitor_id'        => $data['visitor_id'],
            'is_new_visitor'    => (bool) $data['n'],
            'is_new_day_visit'  => (bool) $data['nd'],
            'is_new_hour_visit' => (bool) $data['nh'],
            'is_new_page_visit' => (bool) $data['np'],
            'visited_at'        => $now->format('Y-m-d H:i:s'),
            'created_at'        => $now,
            'updated_at'        => $now,
        ];

        $queueConnection = config('statamic-analytics.tracking.queue_connection');

        if ($queueConnection) {
            try {
                TrackPageViewJob::dispatch($record)
                    ->onConnection($queueConnection)
                    ->onQueue(config('statamic-analytics.tracking.queue_name', 'analytics'));
            } catch (\Exception $e) {
                Log::error('StatamicAnalytics: JS tracker queue dispatch failed, fallback sync', [
                    'error' => $e->getMessage(),
                ]);
                (new PageViewRecorder())->record($record);
            }
        } else {
            (new PageViewRecorder())->record($record);
        }

        return response()->noContent();
    }

    protected function getDeviceType(DeviceDetector $dd): string
    {
        if ($dd->isTablet()) return 'tablet';
        if ($dd->isMobile()) return 'mobile';
        return 'desktop';
    }

    protected function sanitizeReferrer(?string $referer): ?string
    {
        if (!$referer) return null;

        $parts = parse_url($referer);

        if ($parts === false || !isset($parts['host'])) return null;

        $scheme = $parts['scheme'] ?? 'https';
        $path   = $parts['path'] ?? '';

        return "{$scheme}://{$parts['host']}{$path}";
    }
}
