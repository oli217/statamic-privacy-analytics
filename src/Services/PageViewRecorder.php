<?php

namespace Oliweb\StatamicAnalytics\Services;

use Illuminate\Support\Facades\DB;

class PageViewRecorder
{
    public function record(array $data): void
    {
        $ipAddress = $data['ip_address'] ?? '';
        $geoData = (new GeolocationService())->lookup((string) $ipAddress);

        DB::table('statamic_analytics_page_views')->insert(array_merge($data, [
            'country_code' => $geoData['country_code'],
            'country_name' => $geoData['country_name'],
            'city'         => $geoData['city'],
        ]));
    }
}
