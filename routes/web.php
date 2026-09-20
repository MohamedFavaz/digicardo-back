<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| In the Digicardo architecture, Laravel acts purely as a stateless/session-based
| JSON API backend. Web routes return a safe default message for root requests.
|
*/

Route::get('/', function () {
    return response()->json([
        'name' => config('app.name', 'Digicardo API'),
        'version' => 'v1',
        'status' => 'online',
    ]);
});

/**
 * Public Media Asset Serving Route
 * Serves uploaded avatars, cover photos, and block images safely.
 */
Route::get('/storage/{path}', function (string $path) {
    $publicPath = storage_path('app/public/' . $path);
    $privatePath = storage_path('app/private/' . $path);

    $file = file_exists($publicPath) ? $publicPath : (file_exists($privatePath) ? $privatePath : null);

    if (!$file || !is_file($file)) {
        abort(404);
    }

    $mime = mime_content_type($file) ?: 'image/jpeg';

    return response()->file($file, [
        'Content-Type' => $mime,
        'Cache-Control' => 'public, max-age=31536000, immutable',
        'Access-Control-Allow-Origin' => '*',
    ]);
})->where('path', '.*');

/**
 * Named login route for Laravel internal redirects.
 * In the BFF architecture, auth is handled by the Next.js frontend at /login.
 */
Route::get('/login', function () {
    return redirect(config('app.frontend_url', 'http://localhost:3000') . '/login');
})->name('login');

