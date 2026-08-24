<?php

use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DayClosureController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\IngredientCategoryController;
use App\Http\Controllers\Api\V1\IngredientController;
use App\Http\Controllers\Api\V1\MenuController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\RestockController;
use App\Http\Controllers\Api\V1\StockCountController;
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
            Route::put('/expenses/{expense}', [ExpenseController::class, 'update']);
            Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy']);
            Route::get('/expense-categories', [ExpenseController::class, 'categories']);
        });

        // Inventory. Throttled because a stock count submit is a large batch
        // and there is no global API rate limit in this app.
        Route::middleware(['role:owner|cashier', 'throttle:60,1'])
            ->prefix('inventory')
            ->group(function () {
                Route::get('/summary', [IngredientController::class, 'summary']);

                // /categories/reorder MUST stay above /categories/{ingredientCategory} —
                // the same shadowing hazard as /counts/start.
                Route::get('/categories', [IngredientCategoryController::class, 'index']);
                Route::post('/categories', [IngredientCategoryController::class, 'store']);
                Route::post('/categories/reorder', [IngredientCategoryController::class, 'reorder']);
                Route::put('/categories/{ingredientCategory}', [IngredientCategoryController::class, 'update']);
                Route::delete('/categories/{ingredientCategory}', [IngredientCategoryController::class, 'destroy']);

                Route::get('/ingredients', [IngredientController::class, 'index']);
                Route::post('/ingredients', [IngredientController::class, 'store']);
                Route::get('/ingredients/{ingredient}', [IngredientController::class, 'show']);
                Route::put('/ingredients/{ingredient}', [IngredientController::class, 'update']);
                Route::delete('/ingredients/{ingredient}', [IngredientController::class, 'destroy']);

                Route::post('/restocks', [RestockController::class, 'store']);

                // /counts/start MUST stay above any /counts/{id} route.
                Route::get('/counts/start', [StockCountController::class, 'start']);
                Route::get('/counts', [StockCountController::class, 'index']);
                Route::post('/counts', [StockCountController::class, 'store']);
            });

        Route::middleware('role:owner|cashier')->group(function () {
            Route::get('/day-close/preview', [DayClosureController::class, 'preview']);
            Route::post('/day-close', [DayClosureController::class, 'store']);
            Route::get('/day-close/history', [DayClosureController::class, 'history']);
        });
    });
});
