<?php

use App\Http\Middleware\SyncApiKeyMiddleware;
use App\Http\Setting\SyncController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sync Routes
|--------------------------------------------------------------------------
|
| Prefix: api/sync (Assumed to be configured in RouteServiceProvider or api.php)
|
*/

Route::middleware([SyncApiKeyMiddleware::class])->prefix('api/sync')->group(function () {
    Route::post('/download-all', [SyncController::class, 'downloadAllFiles'])->name('sync.download-all');
    Route::post('/get-schema', [SyncController::class, 'getSchema'])->name('sync.get-schema');
});
