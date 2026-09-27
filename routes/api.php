<?php

use App\Http\Controllers\AuditController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => [
    'ok' => true,
    'version' => config('audit.version').'-laravel',
]);

Route::post('/audit', AuditController::class)
    ->middleware('throttle:10,1');
