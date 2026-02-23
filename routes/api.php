<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\QuotationController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ShipmentController;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.reset');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Role & User Management API (Admin only)
    Route::middleware('role:Admin')->group(function () {
        Route::apiResource('roles', RoleController::class);
        Route::apiResource('users', UserController::class);
        Route::put('users/{user}/role', [UserController::class, 'updateRole']);

        // Quotations API
        Route::get('quotations', [QuotationController::class, 'index']);
        Route::post('quotations', [QuotationController::class, 'store']);
        Route::get('quotations/{id}', [QuotationController::class, 'show']);
        Route::match(['put', 'patch'], 'quotations/{id}', [QuotationController::class, 'update']);
        Route::patch('quotations/{id}/status', [QuotationController::class, 'updateStatus']);
        Route::delete('quotations/{id}', [QuotationController::class, 'destroy']);

        // Leads API
        Route::apiResource('leads', LeadController::class);
        Route::patch('leads/{lead}/status', [LeadController::class, 'updateStatus']);

        // Audit Logs API
        Route::get('audit-logs/export', [AuditLogController::class, 'export']);
        Route::get('audit-logs', [AuditLogController::class, 'index']);

        // Orders API (Admin actions)
        Route::post('orders', [OrderController::class, 'store']);
        Route::patch('orders/{id}/status', [OrderController::class, 'updateStatus']);
    });

    // Orders API (Admin & Customer)
    Route::middleware('role:Admin,Customer')->group(function () {
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{id}', [OrderController::class, 'show']);
        Route::get('orders/{id}/timeline', [OrderController::class, 'timeline']);
    });

    // Shipments API (CHA & Customer)
    Route::middleware('role:CHA,Customer')->group(function () {
        Route::get('shipments', [ShipmentController::class, 'index']);
        Route::get('shipments/{id}', [ShipmentController::class, 'show']);
        Route::patch('shipments/{id}/status', [ShipmentController::class, 'updateStatus']);
        Route::post('shipments/{id}/documents', [ShipmentController::class, 'uploadDocuments']);
    });

    // Customer quotations API
    Route::middleware('role:Customer')->group(function () {
        Route::get('my/quotations', [QuotationController::class, 'myIndex']);
        Route::post('my/quotations', [QuotationController::class, 'myStore']);
        Route::get('my/quotations/{id}', [QuotationController::class, 'myShow']);
        Route::post('my/quotations/{id}/approve', [QuotationController::class, 'approve']);
        Route::post('my/quotations/{id}/reject', [QuotationController::class, 'reject']);
        Route::post('my/quotations/{id}/request-changes', [QuotationController::class, 'requestChanges']);
    });
});
