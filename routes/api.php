<?php

use GeoEngine\Agent\Http\Controllers\BridgeController;
use GeoEngine\Agent\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| GEO Engine bridge — public render-time endpoints
|--------------------------------------------------------------------------
|
| These mirror the FastAPI bridge contract so the platform's direct_api
| connector and SPA/SSR front-ends can read managed SEO state.
|
*/

Route::prefix('api/geo-engine')->group(function () {
    Route::get('/health', [BridgeController::class, 'health']);
    Route::get('/recon', [BridgeController::class, 'recon']);
    Route::get('/page-meta', [BridgeController::class, 'pageMeta']);
    Route::get('/structured-data', [BridgeController::class, 'structuredData']);
    Route::get('/llms-txt', [BridgeController::class, 'llmsTxt']);

    // Managed content pages (published via publish_content_plan).
    Route::get('/pages', [BridgeController::class, 'pages']);
    Route::get('/pages/{slug}', [BridgeController::class, 'page'])->where('slug', '.*');

    // Product mirror — public render-time read + authed bulk upsert.
    Route::get('/products/{externalId}', [BridgeController::class, 'product'])->where('externalId', '.*');

    // Webhook push — platform delivers tasks here when the site is
    // reachable; polling remains the fallback.
    Route::post('/webhook', [WebhookController::class, 'handle']);
});

/*
|--------------------------------------------------------------------------
| GEO Engine agent API — x-api-key / Bearer agent-token authed endpoints
|--------------------------------------------------------------------------
|
| Same paths the FastAPI bridge exposes, so one connector preset covers
| both frameworks. Auth: X-Api-Key header (project key) or
| Authorization: Bearer <agent_token>.
|
*/

Route::prefix('api/geo-engine-bridge')->group(function () {
    Route::middleware(\GeoEngine\Agent\Http\Middleware\AgentAuth::class)->group(function () {
        Route::get('/pages', [BridgeController::class, 'adminPages']);
        Route::get('/pages/{pageId}', [BridgeController::class, 'adminPage'])->where('pageId', '.*');
        Route::put('/pages/{pageId}', [BridgeController::class, 'updatePage'])->where('pageId', '.*');
        Route::post('/pages/{pageId}', [BridgeController::class, 'upsertPage'])->where('pageId', '.*');
        Route::post('/apply-fix', [BridgeController::class, 'applyFix']);
        Route::get('/ai-export', [BridgeController::class, 'aiExport']);
        Route::post('/upload-file', [BridgeController::class, 'uploadFile']);
        Route::post('/publish-file', [BridgeController::class, 'uploadFile']);
        Route::post('/posts', [BridgeController::class, 'createPost']);
        Route::get('/products', [BridgeController::class, 'adminProducts']);
        Route::post('/products', [BridgeController::class, 'upsertProducts']);
        Route::put('/products/{productId}', [BridgeController::class, 'updateProduct'])->where('productId', '.*');
        Route::post('/self-update', [BridgeController::class, 'selfUpdate']);
    });
});
