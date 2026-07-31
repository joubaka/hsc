<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\BookingPolicyController;
use App\Http\Controllers\Api\CreditNoteController;
use App\Http\Controllers\Api\FacilityController;
use App\Http\Controllers\Api\FacilityScheduleController;
use App\Http\Controllers\Api\FinancialReportController;
use App\Http\Controllers\Api\FinancialSettingsController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\MembershipApplicationController;
use App\Http\Controllers\Api\MembershipPeriodController;
use App\Http\Controllers\Api\MembershipSetupController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PublicAvailabilityController;
use App\Http\Controllers\Api\RenewalController;
use App\Http\Controllers\ReceiptPrintController;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::get('public/availability', PublicAvailabilityController::class)->middleware('throttle:60,1');

Route::middleware('admin.auth')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('members/meta', [MemberController::class, 'meta']);
    Route::apiResource('members', MemberController::class);
    Route::apiResource('membership-periods', MembershipPeriodController::class)
        ->parameters(['membership-periods' => 'membershipPeriod'])
        ->only(['index', 'store', 'update']);
    Route::post('membership-periods/{membershipPeriod}/transition', [MembershipPeriodController::class, 'transition']);
    Route::get('membership-applications/meta', [MembershipApplicationController::class, 'meta']);
    Route::get('membership-applications/{membershipApplication}/audit', [MembershipApplicationController::class, 'audit']);
    Route::apiResource('membership-applications', MembershipApplicationController::class)
        ->parameters(['membership-applications' => 'membershipApplication'])
        ->only(['index', 'store', 'show', 'update']);
    Route::post('membership-applications/{membershipApplication}/action', [MembershipApplicationController::class, 'action']);
    Route::get('renewals/dashboard', [RenewalController::class, 'dashboard']);
    Route::post('renewals/bulk-drafts', [RenewalController::class, 'bulk']);

    Route::get('finance/settings', [FinancialSettingsController::class, 'show']);
    Route::put('finance/settings', [FinancialSettingsController::class, 'update']);
    Route::get('finance/invoices', [InvoiceController::class, 'index']);
    Route::get('finance/meta', [InvoiceController::class, 'meta']);
    Route::post('finance/invoices', [InvoiceController::class, 'store']);
    Route::get('finance/invoices/{invoice}', [InvoiceController::class, 'show']);
    Route::put('finance/invoices/{invoice}', [InvoiceController::class, 'update']);
    Route::post('finance/invoices/{invoice}/action', [InvoiceController::class, 'action']);
    Route::post('finance/memberships/{sportMembership}/invoice', [InvoiceController::class, 'generate']);
    Route::post('finance/membership-invoices/bulk', [InvoiceController::class, 'bulk']);
    Route::get('finance/payments', [PaymentController::class, 'index']);
    Route::post('finance/payments', [PaymentController::class, 'store']);
    Route::get('finance/payments/{payment}', [PaymentController::class, 'show']);
    Route::post('finance/payments/{payment}/confirm', [PaymentController::class, 'confirm']);
    Route::get('finance/payments/{payment}/allocation-preview', [PaymentController::class, 'preview']);
    Route::post('finance/payments/{payment}/allocations', [PaymentController::class, 'allocate']);
    Route::post('finance/allocations/{allocation}/reverse', [PaymentController::class, 'reverseAllocation']);
    Route::post('finance/payments/{payment}/reverse', [PaymentController::class, 'reverse']);
    Route::post('finance/payments/{payment}/refund', [PaymentController::class, 'refund']);
    Route::post('finance/invoices/{invoice}/credit-notes', [CreditNoteController::class, 'store']);
    Route::post('finance/credit-notes/{creditNote}/issue', [CreditNoteController::class, 'issue']);
    Route::post('finance/credit-notes/{creditNote}/apply', [CreditNoteController::class, 'apply']);
    Route::get('finance/reports/member/{member}', [FinancialReportController::class, 'statement']);
    Route::get('finance/reports/outstanding', [FinancialReportController::class, 'outstanding']);
    Route::get('finance/reports/sport/{sportCode}', [FinancialReportController::class, 'sport']);
    Route::get('finance/reports/hsc-levy', [FinancialReportController::class, 'levy']);
    Route::get('finance/receipts/{receipt}/print', ReceiptPrintController::class);

    Route::get('booking/facilities/meta', [FacilityController::class, 'meta']);
    Route::apiResource('booking/facilities', FacilityController::class)->only(['index', 'store', 'update']);
    Route::post('booking/facilities/{facility}/opening-hours', [FacilityScheduleController::class, 'storeHours']);
    Route::post('booking/facilities/{facility}/closures', [FacilityScheduleController::class, 'storeClosure']);
    Route::post('booking/closures/{closure}/cancel', [FacilityScheduleController::class, 'cancelClosure']);
    Route::get('booking/policies', [BookingPolicyController::class, 'index']);
    Route::post('booking/policies', [BookingPolicyController::class, 'storePolicy']);
    Route::post('booking/pricing-rules', [BookingPolicyController::class, 'storePricing']);
    Route::get('bookings', [BookingController::class, 'index']);
    Route::get('bookings/{booking}', [BookingController::class, 'show']);
    Route::post('booking/simulate', [BookingController::class, 'simulate']);
    Route::post('booking/holds', [BookingController::class, 'hold']);
    Route::post('booking/holds/{hold}/confirm', [BookingController::class, 'confirm']);
    Route::post('bookings/{booking}/recognize-payment', [BookingController::class, 'recognizePayment']);
    Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel']);

    Route::prefix('membership-setup')->group(function () {
        Route::get('/', [MembershipSetupController::class, 'index']);
        Route::post('sport-codes', [MembershipSetupController::class, 'storeSportCode']);
        Route::put('sport-codes/{sportCode}', [MembershipSetupController::class, 'updateSportCode']);
        Route::post('categories', [MembershipSetupController::class, 'storeCategory']);
        Route::put('categories/{category}', [MembershipSetupController::class, 'updateCategory']);
    });
});
