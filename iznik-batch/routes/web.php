<?php

use App\Http\Controllers\IncomingMailController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Incoming mail endpoint for Postfix pipe delivery.
// This is called by the postfix container via HTTP.
// Exclude all web middleware (session, CSRF, cookies) - this is a machine-to-machine API.
Route::post('/api/mail/incoming', [IncomingMailController::class, 'receive'])
    ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class)
    ->withoutMiddleware(\Illuminate\Session\Middleware\StartSession::class)
    ->withoutMiddleware(\Illuminate\View\Middleware\ShareErrorsFromSession::class)
    ->withoutMiddleware(\Illuminate\Cookie\Middleware\EncryptCookies::class)
    ->withoutMiddleware(\Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class);
