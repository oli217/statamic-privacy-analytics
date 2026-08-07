<?php

use Illuminate\Support\Facades\Route;
use Oliweb\StatamicAnalytics\Http\Controllers\AnalyticsDashboardController;

Route::prefix('statamic-analytics')->middleware(['statamic.cp'])->group(function () {

    Route::middleware('can:analytics.view')->group(function () {
        Route::get('/', [AnalyticsDashboardController::class, 'index'])->name('statamic-analytics.index');
        Route::get('/data', [AnalyticsDashboardController::class, 'getData'])->name('statamic-analytics.data');
        Route::get('/geo-stats', [AnalyticsDashboardController::class, 'getGeolocationStats'])->name('statamic-analytics.geo-stats');
        Route::get('/realtime', [AnalyticsDashboardController::class, 'getRealTimeVisitors'])->name('statamic-analytics.realtime');
    });

    Route::middleware('can:analytics.manage')->group(function () {
        Route::get('/export', [AnalyticsDashboardController::class, 'export'])->name('statamic-analytics.export');
        Route::post('/reset-stats', [AnalyticsDashboardController::class, 'resetStats'])->name('statamic-analytics.reset-stats');
    });

});
