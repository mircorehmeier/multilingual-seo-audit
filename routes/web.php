<?php

use App\Http\Controllers\AuditHistoryController;
use App\Http\Middleware\AuditAdminAuth;
use Illuminate\Support\Facades\Route;

Route::view('/', 'audit')->name('home');

Route::middleware(AuditAdminAuth::class)->group(function (): void {
    Route::get('/admin/audits', [AuditHistoryController::class, 'index'])->name('audit.history');
    Route::get('/admin/audits/{id}/open', function (string $id) {
        return redirect('/?audit='.urlencode($id));
    })->whereUuid('id')->name('audit.history.open');
});
