<?php

namespace Oliweb\StatamicAnalytics\Services;

use Illuminate\Support\Facades\Cache;
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

        $cacheKey = 'statamic_analytics_geo_' . $ip;
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
        $rateLimit = config(
            'statamic-analytics.geolocation.ip_api.rate_limit',
            config('statamic-analytics.geolocation.rate_limit', 45)
        );
        $currentMinute = now()->format('Y-m-d H:i');
        $requestCount = Cache::get($rateLimitKey . '_' . $currentMinute, 0);

        if ($requestCount >= $rateLimit) {
            Log::warning('StatamicAnalytics: ip-api rate limit reached.');
            return $this->emptyResult();
        }

        try {
            Cache::put($rateLimitKey . '_' . $currentMinute, $requestCount + 1, 60);

            $response = file_get_contents("http://ip-api.com/json/{$ip}?fields=status,message,countryCode,country,city");
            $data = json_decode($response, true);

            if ($data && ($data['status'] ?? null) === 'success') {
                $this->trackLookup($ip, true);
                return [
                    'country_code' => $data['countryCode'] ?? null,
                    'country_name' => $data['country'] ?? null,
                    'city'         => $data['city'] ?? null,
                ];
            }

            Log::warning('StatamicAnalytics: ip-api lookup failed', [
                'status'  => $data['status'] ?? 'unknown',
                'message' => $data['message'] ?? '',
            ]);
        } catch (\Exception $e) {
            Log::error('StatamicAnalytics: ip-api error', ['error' => $e->getMessage()]);
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
        $statsKey = 'statamic_analytics_geolocation_stats';
        $stats = Cache::get($statsKey, [
            'total_lookups'      => 0,
            'successful_lookups' => 0,
            'failed_lookups'     => 0,
            'unique_ips'         => [],
            'last_lookup'        => null,
        ]);

        $stats['total_lookups']++;
        $success ? $stats['successful_lookups']++ : $stats['failed_lookups']++;

        if (!in_array($ip, $stats['unique_ips'])) {
            $stats['unique_ips'][] = $ip;
        }

        $stats['last_lookup'] = now()->toDateTimeString();

        Cache::put($statsKey, $stats, now()->addDays(30));
    }

    public static function getStats(): array
    {
        return Cache::get('statamic_analytics_geolocation_stats', [
            'total_lookups'      => 0,
            'successful_lookups' => 0,
            'failed_lookups'     => 0,
            'unique_ips'         => [],
            'last_lookup'        => null,
        ]);
    }

    public static function clearCache(): void
    {
        Cache::forget('statamic_analytics_geolocation_stats');
        // Redis/file : on ne peut pas énumérer les clés préfixées sans pattern store
        // On vide juste les stats ; les entrées geo_* expirent selon leur TTL
    }
}
