<?php

use Illuminate\Support\Facades\Route;
use Oliweb\StatamicAnalytics\Http\Controllers\ConsentController;
use Oliweb\StatamicAnalytics\Http\Controllers\TrackController;

Route::post('/statamic-analytics/consent', [ConsentController::class, 'store'])
    ->middleware(['web', 'throttle:10,1']);

// Beacon JS tracker — GET sans CSRF (standard analytics, lecture seule côté navigateur)
Route::get('/statamic-analytics/track', [TrackController::class, 'track'])
    ->middleware(['throttle:120,1'])
    ->name('statamic-analytics.track');