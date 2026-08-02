<?php

use App\Http\Controllers\AreaController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepositController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Public Auth Route
Route::post('/login', [AuthController::class, 'login']);

// Protected Routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);

    // Customer Routes
    Route::get('/customers/download-sample-excel', [CustomerController::class, 'downloadSampleExcel']);
    Route::post('/customers/import-excel', [CustomerController::class, 'importExcel']);
    Route::get('/customers/export-excel', [CustomerController::class, 'exportExcel']);
    Route::get('/customers', [CustomerController::class, 'index']);
    Route::get('/customers/{customer}', [CustomerController::class, 'show']);
    Route::post('/customers', [CustomerController::class, 'store']);
    Route::put('/customers/{customer}', [CustomerController::class, 'update']);
    Route::patch('/customers/{customer}/toggle-status', [CustomerController::class, 'toggleStatus']);
    Route::delete('/customers/{customer}', [CustomerController::class, 'destroy']);

    // Deposit Routes
    Route::get('/customers/{customer}/deposits', [DepositController::class, 'index']);
    Route::post('/deposits', [DepositController::class, 'store']);
    Route::post('/deposits/refund', [DepositController::class, 'refund']);

    // Billing Routes
    Route::post('/bills/generate', [BillController::class, 'generate']);
    Route::get('/bills/export-excel', [BillController::class, 'exportExcel']);
    Route::get('/bills', [BillController::class, 'index']);
    Route::get('/customers/{customer}/bills', [BillController::class, 'customerBills']);

    // Collection & Payment Routes
    Route::get('/payments/download-sample-excel', [PaymentController::class, 'downloadSampleExcel']);
    Route::post('/payments/import-excel', [PaymentController::class, 'importExcel']);
    Route::get('/reports/collection-summary/export-excel', [PaymentController::class, 'exportCollectionSummaryExcel']);
    Route::get('/collector/customers', [PaymentController::class, 'collectorCustomers']);
    Route::post('/payments', [PaymentController::class, 'store']);
    Route::get('/payments', [PaymentController::class, 'index']);
    Route::put('/payments/{payment}', [PaymentController::class, 'update']);
    Route::delete('/payments/{payment}', [PaymentController::class, 'destroy']);
    Route::get('/receipts/{id}', [PaymentController::class, 'receipt']);

    // Areas Routes
    Route::get('/areas', [AreaController::class, 'index']);
    Route::post('/areas', [AreaController::class, 'store']);
    Route::put('/areas/{area}', [AreaController::class, 'update']);
    Route::delete('/areas/{area}', [AreaController::class, 'destroy']);

    // Users Management Routes
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{user}', [UserController::class, 'update']);
    Route::delete('/users/{user}', [UserController::class, 'destroy']);

    // Reports Routes
    Route::get('/reports/collection-summary', [ReportController::class, 'collectionSummary']);
    Route::get('/reports/due-customers', [ReportController::class, 'dueCustomers']);
    Route::get('/reports/deposit-ledger', [ReportController::class, 'depositLedger']);
});
