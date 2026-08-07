<?php

namespace Oliweb\StatamicAnalytics\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeolocationService
{
    protected string $provider;

    public function __construct()
    {
        $this->provider = $this->resolveProvider();
    }

    protected function resolveProvider(): string
    {
        $provider = config('statamic-analytics.geolocation.provider');

        if ($provider !== null) {
            return $provider;
        }

        // Rétrocompat : ancienne clé booléenne enabled
        $enabled = config('statamic-analytics.geolocation.enabled', true);
        return $enabled ? 'ip-api' : 'disabled';
    }

    public function lookup(string $ip): array
    {
        if ($this->provider === 'disabled') {
            return $this->emptyResult();
        }

        if ($this->isPrivateIp($ip)) {
            return $this->emptyResult();
        }

        $cacheKey = 'statamic_analytics_geo_' . hash_hmac('sha256', $ip, config('app.key'));
        $ttl = config('statamic-analytics.geolocation.cache_duration', 60 * 24) * 60;

        return Cache::remember($cacheKey, $ttl, function () use ($ip) {
            return match ($this->provider) {
                'maxmind' => $this->lookupViaMaxMind($ip),
                default   => $this->lookupViaIpApi($ip),
            };
        });
    }

    protected function lookupViaIpApi(string $ip): array
    {
        $rateLimitKey = 'statamic_analytics_geo_ratelimit';
        $currentMinute = now()->format('Y-m-d H:i');
        $lockKey = 'statamic-analytics:ratelimit-lock:' . $currentMinute;

        $rateLimit = config(
            'statamic-analytics.geolocation.ip_api.rate_limit',
            config('statamic-analytics.geolocation.rate_limit', 45)
        );

        // Verrou atomique : contrairement aux stats (best-effort), le rate limiting
        // protège un service tiers. On laisse LockTimeoutException remonter plutôt
        // que de risquer un dépassement silencieux de la limite.
        $allowed = Cache::lock($lockKey, 5)->block(2, function () use ($rateLimitKey, $currentMinute, $rateLimit) {
            $requestCount = Cache::get($rateLimitKey . '_' . $currentMinute, 0);
            if ($requestCount >= $rateLimit) {
                return false;
            }
            Cache::put($rateLimitKey . '_' . $currentMinute, $requestCount + 1, 60);
            return true;
        });

        if ($allowed === false) {
            Log::warning('StatamicAnalytics: ip-api rate limit reached.');
            return $this->emptyResult();
        }

        $data = null;
        try {
            $response = Http::timeout(2)->connectTimeout(1)->get("http://ip-api.com/json/{$ip}", [
                'fields' => 'status,message,countryCode,country,city',
            ]);
            $data = $response->successful() ? $response->json() : null;
        } catch (ConnectionException $e) {
            Log::warning('StatamicAnalytics: ip-api connection error', ['error' => $e->getMessage()]);
        }

        if ($data && ($data['status'] ?? null) === 'success') {
            $this->trackLookup($ip, true);
            return [
                'country_code' => $data['countryCode'] ?? null,
                'country_name' => $data['country'] ?? null,
                'city'         => $data['city'] ?? null,
            ];
        }

        if ($data !== null) {
            Log::warning('StatamicAnalytics: ip-api lookup failed', [
                'status'  => $data['status'] ?? 'unknown',
                'message' => $data['message'] ?? '',
            ]);
        }

        $this->trackLookup($ip, false);
        return $this->emptyResult();
    }

    protected function lookupViaMaxMind(string $ip): array
    {
        $dbPath = config(
            'statamic-analytics.geolocation.maxmind.database_path',
            storage_path('app/geoip/GeoLite2-City.mmdb')
        );

        if (!file_exists($dbPath)) {
            // Throttle : au plus un log par heure pour éviter le flood sur trafic soutenu
            Cache::remember('statamic-analytics:geoip-missing-warning', 3600, function () use ($dbPath) {
                Log::warning('StatamicAnalytics: GeoLite2 database not found.', ['path' => $dbPath]);
                return true;
            });
            return $this->emptyResult();
        }

        try {
            $reader = new \GeoIp2\Database\Reader($dbPath);
            $record = $reader->city($ip);
            $reader->close();

            $this->trackLookup($ip, true);
            return [
                'country_code' => $record->country->isoCode ?? null,
                'country_name' => $record->country->name ?? null,
                'city'         => $record->city->name ?? null,
            ];
        } catch (\GeoIp2\Exception\AddressNotFoundException $e) {
            return $this->emptyResult();
        } catch (\Exception $e) {
            Log::error('StatamicAnalytics: MaxMind lookup error', ['error' => $e->getMessage()]);
            $this->trackLookup($ip, false);
            return $this->emptyResult();
        }
    }

    protected function isPrivateIp(string $ip): bool
    {
        if (in_array($ip, ['127.0.0.1', '::1'])) {
            return true;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    protected function emptyResult(): array
    {
        return ['country_code' => null, 'country_name' => null, 'city' => null];
    }

    protected function trackLookup(string $ip, bool $success): void
    {
        $statsKey = 'statamic_analytics_geolocation_stats_' . now()->format('Y-m-d');
        $lockKey  = 'statamic-analytics:geo-stats-lock:' . now()->format('Y-m-d');

        try {
            Cache::lock($lockKey, 5)->block(2, function () use ($statsKey, $ip, $success) {
                $stats = Cache::get($statsKey, [
                    'total_lookups'      => 0,
                    'successful_lookups' => 0,
                    'failed_lookups'     => 0,
                    'unique_ips'         => [],
                    'last_lookup'        => null,
                ]);

                $stats['total_lookups']++;
                $success ? $stats['successful_lookups']++ : $stats['failed_lookups']++;

                $ipHash = hash_hmac('sha256', $ip, config('app.key'));
                if (!in_array($ipHash, $stats['unique_ips'], true)) {
                    $stats['unique_ips'][] = $ipHash;
                }

                $stats['last_lookup'] = now()->toDateTimeString();

                // TTL fixe : la clé expire naturellement après 32 jours, bornant
                // la mémoire dans le temps (pas de croissance indéfinie de unique_ips).
                Cache::put($statsKey, $stats, now()->addDays(32));
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            // Best-effort : ces statistiques sont opérationnelles, pas critiques
            // (aucune UI ne les affiche actuellement) — on préfère ignorer un
            // point de mesure plutôt que bloquer la résolution géographique.
            Log::info('StatamicAnalytics: verrou stats géo non acquis, mise à jour ignorée.', [
                'lock_key' => $lockKey,
            ]);
        }
    }

    public static function getStats(int $days = 30): array
    {
        $aggregate = [
            'total_lookups'      => 0,
            'successful_lookups' => 0,
            'failed_lookups'     => 0,
            'unique_ips'         => [],
            'last_lookup'        => null,
        ];

        for ($i = 0; $i < $days; $i++) {
            $daily = Cache::get('statamic_analytics_geolocation_stats_' . now()->subDays($i)->format('Y-m-d'));

            if (!$daily) {
                continue;
            }

            $aggregate['total_lookups']      += $daily['total_lookups'];
            $aggregate['successful_lookups'] += $daily['successful_lookups'];
            $aggregate['failed_lookups']     += $daily['failed_lookups'];
            $aggregate['unique_ips']          = array_unique(array_merge(
                $aggregate['unique_ips'],
                $daily['unique_ips']
            ));

            if ($daily['last_lookup'] && (!$aggregate['last_lookup'] || $daily['last_lookup'] > $aggregate['last_lookup'])) {
                $aggregate['last_lookup'] = $daily['last_lookup'];
            }
        }

        $aggregate['unique_ips'] = array_values($aggregate['unique_ips']);

        return $aggregate;
    }

    public static function resetStats(int $days = 32): void
    {
        for ($i = 0; $i < $days; $i++) {
            Cache::forget('statamic_analytics_geolocation_stats_' . now()->subDays($i)->format('Y-m-d'));
        }
    }
}
