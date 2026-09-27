<?php

use App\Http\Controllers\AuditController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => [
    'ok' => true,
    'version' => '0.2.0-laravel',
]);

Route::post('/audit', AuditController::class)
    ->middleware('throttle:10,1');
