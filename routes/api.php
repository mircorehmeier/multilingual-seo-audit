<?php

use App\Http\Controllers\AuditController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => [
    'ok' => true,
    'version' => config('audit.version').'-laravel',
]);

Route::post('/audit', AuditController::class)
    ->middleware('throttle:10,1');

use App\Http\Controllers\AsyncAuditController;

Route::post('/audits', [AsyncAuditController::class, 'start'])->middleware('throttle:4,1');
Route::get('/audits/{id}/status', [AsyncAuditController::class, 'status'])->whereUuid('id')->middleware('throttle:120,1');
Route::get('/audits/{id}/result', [AsyncAuditController::class, 'result'])->whereUuid('id')->middleware('throttle:20,1');
