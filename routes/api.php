<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\MembershipApplicationController;
use App\Http\Controllers\Api\MembershipPeriodController;
use App\Http\Controllers\Api\MembershipSetupController;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware('admin.auth')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('members/meta', [MemberController::class, 'meta']);
    Route::apiResource('members', MemberController::class);
    Route::apiResource('membership-periods', MembershipPeriodController::class)->only(['index', 'store', 'update']);
    Route::post('membership-periods/{membershipPeriod}/transition', [MembershipPeriodController::class, 'transition']);
    Route::get('membership-applications/meta', [MembershipApplicationController::class, 'meta']);
    Route::apiResource('membership-applications', MembershipApplicationController::class)->only(['index', 'store', 'show', 'update']);
    Route::post('membership-applications/{membershipApplication}/action', [MembershipApplicationController::class, 'action']);

    Route::prefix('membership-setup')->group(function () {
        Route::get('/', [MembershipSetupController::class, 'index']);
        Route::post('sport-codes', [MembershipSetupController::class, 'storeSportCode']);
        Route::put('sport-codes/{sportCode}', [MembershipSetupController::class, 'updateSportCode']);
        Route::post('categories', [MembershipSetupController::class, 'storeCategory']);
        Route::put('categories/{category}', [MembershipSetupController::class, 'updateCategory']);
    });
});
