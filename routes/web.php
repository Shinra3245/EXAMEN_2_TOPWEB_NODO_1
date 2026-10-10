<?php

use App\Http\Controllers\Admin\AdminNodeController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\RealtimeController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('admin.login');
});

// Admin Auth Routes
Route::get('/admin/login', [AuthController::class, 'showLoginForm'])->name('admin.login');
Route::post('/admin/login', [AuthController::class, 'login'])->name('admin.login.post');
Route::post('/admin/logout', [AuthController::class, 'logout'])->name('admin.logout');

// Protected Admin Routes
Route::middleware(['admin.auth'])->group(function () {
    Route::get('/admin/realtime', [RealtimeController::class, 'show'])->name('admin.realtime');
    Route::get('/admin', [AdminNodeController::class, 'dashboard'])->name('admin.dashboard');
    Route::post('/admin/nodes', [AdminNodeController::class, 'createNode'])->name('admin.nodes.store');
    Route::post('/admin/nodes/{id}/disable', [AdminNodeController::class, 'disableNode'])->name('admin.nodes.disable');
    Route::post('/admin/nodes/{id}/rotate', [AdminNodeController::class, 'rotateKey'])->name('admin.nodes.rotate');
    Route::post('/admin/nodes/{id}/cash', [AdminNodeController::class, 'updateCash'])->name('admin.nodes.cash');

    Route::get('/admin/transactions', [AdminNodeController::class, 'transactions'])->name('admin.transactions');
});
