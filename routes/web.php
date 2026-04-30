<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DayClosureController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SalesController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('welcome');

Route::get('/stores/assignature', fn () => view('stores.assignature'))->name('stores.assignature');
Route::get('/stores/ramen-naijiro', fn () => view('stores.ramen-naijiro'))->name('stores.ramen-naijiro');
Route::get('/stores/marugo-takoyaki', fn () => view('stores.marugo-takoyaki'))->name('stores.marugo-takoyaki');

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Branches (owner-only)
    Route::get('/branches', [BranchController::class, 'index'])
        ->middleware('role:owner')
        ->name('branches.index');
    Route::post('/branches', [BranchController::class, 'store'])
        ->middleware('role:owner')
        ->name('branches.store');
    Route::put('/branches/{branch}', [BranchController::class, 'update'])
        ->middleware('role:owner')
        ->name('branches.update');
    Route::delete('/branches/{branch}', [BranchController::class, 'destroy'])
        ->middleware('role:owner')
        ->name('branches.destroy');
    Route::post('/branches/{branch}/assign-to-me', [BranchController::class, 'assignToMe'])
        ->middleware('role:owner')
        ->name('branches.assign-to-me');

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
    Route::get('/employees/{employee}/details', [EmployeeController::class, 'show'])
        ->middleware('role:owner|cashier')
        ->name('employees.show');
    Route::post('/employees', [EmployeeController::class, 'store'])
        ->middleware('role:owner|cashier')
        ->name('employees.store');
    Route::put('/employees/{employee}', [EmployeeController::class, 'update'])
        ->middleware('role:owner')
        ->name('employees.update');
    Route::patch('/employees/{employee}/toggle-active', [EmployeeController::class, 'toggleActive'])
        ->middleware('role:owner')
        ->name('employees.toggle-active');
    Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy'])
        ->middleware('role:owner')
        ->name('employees.destroy');

    Route::get('/menu', [MenuController::class, 'index'])
        ->middleware('role:owner')
        ->name('menu.index');
    Route::post('/menu/categories', [MenuController::class, 'storeCategory'])
        ->middleware('role:owner')
        ->name('menu.categories.store');
    Route::put('/menu/categories/{category}', [MenuController::class, 'updateCategory'])
        ->middleware('role:owner')
        ->name('menu.categories.update');
    Route::delete('/menu/categories/{category}', [MenuController::class, 'destroyCategory'])
        ->middleware('role:owner')
        ->name('menu.categories.destroy');
    Route::post('/menu/items', [MenuController::class, 'storeItem'])
        ->middleware('role:owner')
        ->name('menu.items.store');
    Route::put('/menu/items/{item}', [MenuController::class, 'updateItem'])
        ->middleware('role:owner')
        ->name('menu.items.update');
    Route::delete('/menu/items/{item}', [MenuController::class, 'destroyItem'])
        ->middleware('role:owner')
        ->name('menu.items.destroy');

    Route::get('/sales', [SalesController::class, 'index'])
        ->middleware('role:owner')
        ->name('sales.index');
    Route::get('/sales/{sale}/details', [SalesController::class, 'show'])
        ->middleware('role:owner')
        ->name('sales.show');

    // Day closures (cash report)
    Route::get('/cash-report', [DayClosureController::class, 'index'])
        ->middleware('role:owner|cashier')
        ->name('day-closures.index');
    Route::get('/day-close/preview', [DayClosureController::class, 'preview'])
        ->middleware('role:owner|cashier')
        ->name('day-close.preview');
    Route::post('/day-close', [DayClosureController::class, 'store'])
        ->middleware('role:owner|cashier')
        ->name('day-close.store');
    Route::delete('/day-close/{dayClosure}', [DayClosureController::class, 'destroy'])
        ->middleware('role:owner')
        ->name('day-close.destroy');

    Route::get('/expenses', [ExpenseController::class, 'index'])
        ->middleware('role:owner')
        ->name('expenses.index');
    Route::get('/expenses/{expense}/details', [ExpenseController::class, 'show'])
        ->middleware('role:owner')
        ->name('expenses.show');
    Route::post('/expenses', [ExpenseController::class, 'store'])
        ->middleware('role:owner')
        ->name('expenses.store');
    Route::put('/expenses/{expense}', [ExpenseController::class, 'update'])
        ->middleware('role:owner')
        ->name('expenses.update');
    Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy'])
        ->middleware('role:owner')
        ->name('expenses.destroy');

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
    Route::post('/payroll/bulk-generate', [PayrollController::class, 'bulkGenerate'])
        ->middleware('role:owner')
        ->name('payroll.bulk-generate');
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
    Route::get('/inventory/counts/start', [InventoryController::class, 'startCount'])
        ->middleware('role:owner')
        ->name('inventory.counts.start');
    Route::post('/inventory/counts', [InventoryController::class, 'storeCount'])
        ->middleware('role:owner')
        ->name('inventory.counts.store');
    Route::get('/inventory/counts/{stockCount}/details', [InventoryController::class, 'showCount'])
        ->middleware('role:owner')
        ->name('inventory.counts.show');
    Route::delete('/inventory/counts/{stockCount}', [InventoryController::class, 'destroyCount'])
        ->middleware('role:owner')
        ->name('inventory.counts.destroy');
    Route::get('/inventory/{ingredient}/details', [InventoryController::class, 'showIngredient'])
        ->middleware('role:owner')
        ->name('inventory.show');
    Route::post('/inventory', [InventoryController::class, 'store'])
        ->middleware('role:owner')
        ->name('inventory.store');
    Route::put('/inventory/{ingredient}', [InventoryController::class, 'update'])
        ->middleware('role:owner')
        ->name('inventory.update');
    Route::delete('/inventory/{ingredient}', [InventoryController::class, 'destroy'])
        ->middleware('role:owner')
        ->name('inventory.destroy');
});

require __DIR__.'/auth.php';
