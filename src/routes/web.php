<?php

use Illuminate\Support\Facades\Route;
use Shah\LaravelUpdater\Http\Controllers\UpdaterController;

Route::group([
    'prefix' => config('updater.route_prefix', 'updater'),
    'middleware' => config('updater.middleware', ['web'])
], function () {
    // Dashboard
    Route::get('/', [UpdaterController::class, 'index'])->name('updater.dashboard');

    // Check for updates
    Route::post('/check', [UpdaterController::class, 'check'])->name('updater.check');

    // Run update
    Route::post('/update', [UpdaterController::class, 'update'])->name('updater.update');

    // Manual update
    Route::post('/manual-update', [UpdaterController::class, 'uploadAndInstall'])->name('updater.manual');

    // Install package
    Route::post('/install-package', [UpdaterController::class, 'installPackage'])->name('updater.package');

    // Verify license
    Route::post('/verify-license', [UpdaterController::class, 'verifyLicense'])->name('updater.license');
});
