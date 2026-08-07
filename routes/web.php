<?php

use Illuminate\Support\Facades\Route;
use Oliweb\StatamicAnalytics\Http\Controllers\ConsentController;

Route::post('/statamic-analytics/consent', [ConsentController::class, 'store'])
    ->middleware(['web', 'throttle:10,1']);