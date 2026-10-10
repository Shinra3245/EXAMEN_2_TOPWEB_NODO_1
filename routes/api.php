<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AtmOperationController;
use App\Http\Controllers\Api\NodeController;
use App\Http\Controllers\Api\TransactionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['node.auth'])->group(function () {
    Route::get('/nodes/me', [NodeController::class, 'show']);
    Route::get('/transactions/by-idempotency-key/{key}', [AtmOperationController::class, 'show']);
    Route::post('/accounts', [AccountController::class, 'store']);
    Route::get('/accounts/{numero_cuenta}', [AccountController::class, 'show']);

    Route::post('/transactions', [TransactionController::class, 'store']);
    Route::get('/transactions', [TransactionController::class, 'index']);
});
