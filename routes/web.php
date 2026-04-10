<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SalesController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('welcome');

Route::get('/stores/assignature', fn() => view('stores.assignature'))->name('stores.assignature');
Route::get('/stores/ramen-naijiro', fn() => view('stores.ramen-naijiro'))->name('stores.ramen-naijiro');
Route::get('/stores/marugo-takoyaki', fn() => view('stores.marugo-takoyaki'))->name('stores.marugo-takoyaki');

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/attendance', [AttendanceController::class, 'index'])
        ->middleware('role:owner|cashier')
        ->name('attendance.index');
    Route::post('/attendance/clock-in', [AttendanceController::class, 'clockIn'])
        ->middleware('role:owner|cashier')
        ->name('attendance.clock-in');
    Route::post('/attendance/clock-out', [AttendanceController::class, 'clockOut'])
        ->middleware('role:owner|cashier')
        ->name('attendance.clock-out');
    Route::post('/attendance/manual-entry', [AttendanceController::class, 'manualEntry'])
        ->middleware('role:owner')
        ->name('attendance.manual-entry');
    Route::post('/attendance/update-times', [AttendanceController::class, 'updateTimes'])
        ->middleware('role:owner')
        ->name('attendance.update-times');
    Route::delete('/attendance/{attendanceRecord}', [AttendanceController::class, 'destroy'])
        ->middleware('role:owner')
        ->name('attendance.destroy');

    Route::get('/employees', [EmployeeController::class, 'index'])
        ->middleware('role:owner|cashier')
        ->name('employees.index');
    Route::post('/employees', [EmployeeController::class, 'store'])
        ->middleware('role:owner|cashier')
        ->name('employees.store');
    Route::put('/employees/{employee}', [EmployeeController::class, 'update'])
        ->middleware('role:owner')
        ->name('employees.update');
    Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy'])
        ->middleware('role:owner')
        ->name('employees.destroy');

    Route::get('/sales', [SalesController::class, 'index'])
        ->middleware('role:owner')
        ->name('sales.index');

    Route::get('/expenses', [ExpenseController::class, 'index'])
        ->middleware('role:owner')
        ->name('expenses.index');
    Route::post('/expenses', [ExpenseController::class, 'store'])
        ->middleware('role:owner')
        ->name('expenses.store');

    Route::get('/payroll', [PayrollController::class, 'index'])
        ->middleware('role:owner')
        ->name('payroll.index');
    Route::get('/payroll/{payrollPeriod}/details', [PayrollController::class, 'show'])
        ->middleware('role:owner')
        ->name('payroll.show');
    Route::post('/payroll/rules', [PayrollController::class, 'updateRules'])
        ->middleware('role:owner')
        ->name('payroll.rules.update');
    Route::post('/payroll/generate', [PayrollController::class, 'generate'])
        ->middleware('role:owner')
        ->name('payroll.generate');
    Route::post('/payroll/finalize', [PayrollController::class, 'finalize'])
        ->middleware('role:owner')
        ->name('payroll.finalize');
    Route::delete('/payroll/reports/{payrollEntry}', [PayrollController::class, 'destroyReport'])
        ->middleware('role:owner')
        ->name('payroll.reports.destroy');
    Route::delete('/payroll/{payrollPeriod}', [PayrollController::class, 'destroy'])
        ->middleware('role:owner')
        ->name('payroll.destroy');

    // Inventory
    Route::get('/inventory', [InventoryController::class, 'index'])
        ->middleware('role:owner')
        ->name('inventory.index');
    Route::post('/inventory', [InventoryController::class, 'store'])
        ->middleware('role:owner')
        ->name('inventory.store');
    Route::put('/inventory/{ingredient}', [InventoryController::class, 'update'])
        ->middleware('role:owner')
        ->name('inventory.update');
    Route::delete('/inventory/{ingredient}', [InventoryController::class, 'destroy'])
        ->middleware('role:owner')
        ->name('inventory.destroy');
    Route::post('/inventory/adjust', [InventoryController::class, 'adjust'])
        ->middleware('role:owner')
        ->name('inventory.adjust');
});

require __DIR__.'/auth.php';
