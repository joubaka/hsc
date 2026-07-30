<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\MembershipSetupController;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware('admin.auth')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('members/meta', [MemberController::class, 'meta']);
    Route::apiResource('members', MemberController::class);

    Route::prefix('membership-setup')->group(function () {
        Route::get('/', [MembershipSetupController::class, 'index']);
        Route::post('sport-codes', [MembershipSetupController::class, 'storeSportCode']);
        Route::put('sport-codes/{sportCode}', [MembershipSetupController::class, 'updateSportCode']);
        Route::post('categories', [MembershipSetupController::class, 'storeCategory']);
        Route::put('categories/{category}', [MembershipSetupController::class, 'updateCategory']);
    });
});
