<?php

use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DayClosureController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\MenuController;
use App\Http\Controllers\Api\V1\OrderController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    Route::post('/auth/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);

        Route::get('/menu/categories', [MenuController::class, 'categories']);
        Route::get('/menu/items', [MenuController::class, 'items']);

        Route::middleware('role:owner|cashier')->group(function () {
            Route::get('/orders', [OrderController::class, 'index']);
            Route::post('/orders', [OrderController::class, 'store']);
            Route::get('/orders/{sale}', [OrderController::class, 'show']);
            Route::post('/orders/{sale}/pay', [OrderController::class, 'pay']);
            Route::post('/orders/{sale}/void', [OrderController::class, 'void']);
        });

        Route::middleware('role:owner|cashier')->group(function () {
            Route::get('/employees', [EmployeeController::class, 'index']);

            Route::post('/attendance/clock-in', [AttendanceController::class, 'clockIn']);
            Route::post('/attendance/clock-out', [AttendanceController::class, 'clockOut']);
            Route::get('/attendance/today', [AttendanceController::class, 'today']);
        });

        Route::middleware('role:owner|cashier')->group(function () {
            Route::get('/expenses', [ExpenseController::class, 'index']);
            Route::post('/expenses', [ExpenseController::class, 'store']);
            Route::get('/expense-categories', [ExpenseController::class, 'categories']);
        });

        Route::middleware('role:owner|cashier')->group(function () {
            Route::get('/day-close/preview', [DayClosureController::class, 'preview']);
            Route::post('/day-close', [DayClosureController::class, 'store']);
            Route::get('/day-close/history', [DayClosureController::class, 'history']);
        });
    });
});
