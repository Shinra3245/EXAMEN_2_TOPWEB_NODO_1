<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\TransactionController;

Route::middleware(['node.auth'])->group(function () {
    Route::post('/accounts', [AccountController::class, 'store']);
    Route::get('/accounts/{numero_cuenta}', [AccountController::class, 'show']);
    
    Route::post('/transactions', [TransactionController::class, 'store']);
    // History endpoint to be added later
});
