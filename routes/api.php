<?php

use App\Http\Controllers\Api\V1\NormalizeExampleController;
use App\Http\Middleware\EnsureEngineeringReferenceEndpointIsEnabled;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'application' => 'udumbara-internal-hub',
        'version' => 'v1',
    ]))->name('api.v1.health');

    Route::post('/examples/normalize', NormalizeExampleController::class)
        ->middleware(EnsureEngineeringReferenceEndpointIsEnabled::class)
        ->name('api.v1.examples.normalize');
});
