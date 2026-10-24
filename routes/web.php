<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DayClosureController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\GcashReportController;
use App\Http\Controllers\InventoryCategoryController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\SpecialExpenseController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('welcome');

Route::get('/stores/assignature', fn () => view('stores.assignature'))->name('stores.assignature');
Route::get('/stores/ramen-naijiro', fn () => view('stores.ramen-naijiro'))->name('stores.ramen-naijiro');
Route::get('/stores/marugo-takoyaki', fn () => view('stores.marugo-takoyaki'))->name('stores.marugo-takoyaki');

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified', 'cashier.pos', 'branch.lock'])
    ->name('dashboard');

// The website is for the super admin (`owner`) and branch admins (`admin`). A
// cashier works the POS only (the iPad app on the API), so 'cashier.pos' sends them
// there from any of these routes; 'branch.lock' confines a branch admin to their
// own branch (see LockToOwnBranch).
Route::middleware(['auth', 'cashier.pos', 'branch.lock'])->group(function () {
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
        ->middleware('role:owner|admin')
        ->name('attendance.index');
    Route::post('/attendance/clock-in', [AttendanceController::class, 'clockIn'])
        ->middleware('role:owner|admin')
        ->name('attendance.clock-in');
    Route::post('/attendance/clock-out', [AttendanceController::class, 'clockOut'])
        ->middleware('role:owner|admin')
        ->name('attendance.clock-out');
    Route::post('/attendance/manual-entry', [AttendanceController::class, 'manualEntry'])
        ->middleware('role:owner|admin')
        ->name('attendance.manual-entry');
    Route::post('/attendance/update-times', [AttendanceController::class, 'updateTimes'])
        ->middleware('role:owner|admin')
        ->name('attendance.update-times');
    Route::delete('/attendance/{attendanceRecord}', [AttendanceController::class, 'destroy'])
        ->middleware('role:owner|admin')
        ->name('attendance.destroy');

    Route::get('/employees', [EmployeeController::class, 'index'])
        ->middleware('role:owner|admin')
        ->name('employees.index');
    Route::get('/employees/{employee}/details', [EmployeeController::class, 'show'])
        ->middleware('role:owner|admin')
        ->name('employees.show');
    Route::post('/employees', [EmployeeController::class, 'store'])
        ->middleware('role:owner|admin')
        ->name('employees.store');
    Route::put('/employees/{employee}', [EmployeeController::class, 'update'])
        ->middleware('role:owner|admin')
        ->name('employees.update');
    // A cashier login for this employee. The login carries no branch of its own; it
    // follows the employee's (User::resolveBranchId), so moving them moves it too.
    Route::post('/employees/{employee}/login', [EmployeeController::class, 'storeLogin'])
        ->middleware('role:owner|admin')
        ->name('employees.login.store');
    Route::patch('/employees/{employee}/toggle-active', [EmployeeController::class, 'toggleActive'])
        ->middleware('role:owner|admin')
        ->name('employees.toggle-active');
    Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy'])
        ->middleware('role:owner|admin')
        ->name('employees.destroy');

    Route::get('/menu', [MenuController::class, 'index'])
        ->middleware('role:owner|admin')
        ->name('menu.index');
    Route::post('/menu/categories', [MenuController::class, 'storeCategory'])
        ->middleware('role:owner|admin')
        ->name('menu.categories.store');
    Route::put('/menu/categories/{category}', [MenuController::class, 'updateCategory'])
        ->middleware('role:owner|admin')
        ->name('menu.categories.update');
    Route::delete('/menu/categories/{category}', [MenuController::class, 'destroyCategory'])
        ->middleware('role:owner|admin')
        ->name('menu.categories.destroy');
    Route::post('/menu/items', [MenuController::class, 'storeItem'])
        ->middleware('role:owner|admin')
        ->name('menu.items.store');
    Route::put('/menu/items/{item}', [MenuController::class, 'updateItem'])
        ->middleware('role:owner|admin')
        ->name('menu.items.update');
    Route::delete('/menu/items/{item}', [MenuController::class, 'destroyItem'])
        ->middleware('role:owner|admin')
        ->name('menu.items.destroy');

    Route::get('/sales', [SalesController::class, 'index'])
        ->middleware('role:owner|admin')
        ->name('sales.index');
    Route::get('/sales/{sale}/details', [SalesController::class, 'show'])
        ->middleware('role:owner|admin')
        ->name('sales.show');

    // Day closures (cash report)
    Route::get('/cash-report', [DayClosureController::class, 'index'])
        ->middleware('role:owner|admin')
        ->name('day-closures.index');
    Route::get('/day-close/preview', [DayClosureController::class, 'preview'])
        ->middleware('role:owner|admin')
        ->name('day-close.preview');
    Route::post('/day-close', [DayClosureController::class, 'store'])
        ->middleware('role:owner|admin')
        ->name('day-close.store');
    // A closed day is corrected, never reopened. Deleting the closure used to be the
    // only way to fix a day, which threw away the record of it having been closed.
    Route::get('/day-close/{dayClosure}/edit', [DayClosureController::class, 'edit'])
        ->middleware('role:owner|admin')
        ->name('day-close.edit');
    Route::put('/day-close/{dayClosure}', [DayClosureController::class, 'update'])
        ->middleware('role:owner|admin')
        ->name('day-close.update');

    // GCash report (read-only, built from individual GCash sales). Owner-only, like
    // every page in this group.
    Route::get('/gcash-report', [GcashReportController::class, 'index'])
        ->middleware('role:owner|admin')
        ->name('gcash-report.index');

    // Manually recorded GCash income (stored as completed GCash sales). GCash expenses reuse
    // the expenses.* routes below rather than growing a second way to create an expense.
    Route::post('/gcash-report/records', [GcashReportController::class, 'store'])
        ->middleware('role:owner|admin')
        ->name('gcash-report.records.store');
    Route::put('/gcash-report/records/{sale}', [GcashReportController::class, 'update'])
        ->middleware('role:owner|admin')
        ->name('gcash-report.records.update');
    Route::delete('/gcash-report/records/{sale}', [GcashReportController::class, 'destroy'])
        ->middleware('role:owner|admin')
        ->name('gcash-report.records.destroy');

    // Correcting entries. Their own table, so they never reach the Sales or Expenses pages
    // and cannot invalidate a closed day's GCash snapshot.
    Route::post('/gcash-report/adjustments', [GcashReportController::class, 'storeAdjustment'])
        ->middleware('role:owner|admin')
        ->name('gcash-report.adjustments.store');
    Route::put('/gcash-report/adjustments/{gcashAdjustment}', [GcashReportController::class, 'updateAdjustment'])
        ->middleware('role:owner|admin')
        ->name('gcash-report.adjustments.update');
    Route::delete('/gcash-report/adjustments/{gcashAdjustment}', [GcashReportController::class, 'destroyAdjustment'])
        ->middleware('role:owner|admin')
        ->name('gcash-report.adjustments.destroy');

    // Wallet reconciliation: the opening balance the running total counts from, and whether
    // each entry was actually seen on the GCash statement.
    Route::put('/gcash-report/wallet', [GcashReportController::class, 'updateWallet'])
        ->middleware('role:owner|admin')
        ->name('gcash-report.wallet.update');
    Route::put('/gcash-report/entries/{type}/{id}/status', [GcashReportController::class, 'updateEntryStatus'])
        ->middleware('role:owner|admin')
        ->whereIn('type', ['sale', 'expense'])
        ->whereNumber('id')
        ->name('gcash-report.entries.status');

    // Asked by the expense form before it lets you save onto a day that is already
    // signed off. Must stay above /expenses/{expense}/details.
    Route::get('/expenses/day-status', [ExpenseController::class, 'dayStatus'])
        ->middleware('role:owner|admin')
        ->name('expenses.day-status');

    Route::get('/expenses', [ExpenseController::class, 'index'])
        ->middleware('role:owner|admin')
        ->name('expenses.index');

    // Monthly overhead (rent, electricity). Declared ABOVE the {expense} routes:
    // a literal segment must beat a model-bound one, per the hazard noted further
    // down this file. Reads and writes `special_expenses` only — never `expenses`,
    // which feeds the drawer count and today's net income.
    Route::get('/expenses/special', [SpecialExpenseController::class, 'index'])
        ->middleware('role:owner|admin')
        ->name('special-expenses.index');
    Route::get('/expenses/special/{specialExpense}/details', [SpecialExpenseController::class, 'show'])
        ->middleware('role:owner|admin')
        ->name('special-expenses.show');
    Route::post('/expenses/special', [SpecialExpenseController::class, 'store'])
        ->middleware('role:owner|admin')
        ->name('special-expenses.store');
    Route::put('/expenses/special/{specialExpense}', [SpecialExpenseController::class, 'update'])
        ->middleware('role:owner|admin')
        ->name('special-expenses.update');
    Route::delete('/expenses/special/{specialExpense}', [SpecialExpenseController::class, 'destroy'])
        ->middleware('role:owner|admin')
        ->name('special-expenses.destroy');

    Route::get('/expenses/{expense}/details', [ExpenseController::class, 'show'])
        ->middleware('role:owner|admin')
        ->name('expenses.show');
    Route::post('/expenses', [ExpenseController::class, 'store'])
        ->middleware('role:owner|admin')
        ->name('expenses.store');
    Route::put('/expenses/{expense}', [ExpenseController::class, 'update'])
        ->middleware('role:owner|admin')
        ->name('expenses.update');
    Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy'])
        ->middleware('role:owner|admin')
        ->name('expenses.destroy');

    Route::get('/payroll', [PayrollController::class, 'index'])
        ->middleware('role:owner|admin')
        ->name('payroll.index');
    Route::get('/payroll/{payrollPeriod}/details', [PayrollController::class, 'show'])
        ->middleware('role:owner|admin')
        ->name('payroll.show');
    Route::post('/payroll/rules', [PayrollController::class, 'updateRules'])
        ->middleware('role:owner|admin')
        ->name('payroll.rules.update');
    Route::post('/payroll/generate', [PayrollController::class, 'generate'])
        ->middleware('role:owner|admin')
        ->name('payroll.generate');
    Route::post('/payroll/bulk-generate', [PayrollController::class, 'bulkGenerate'])
        ->middleware('role:owner|admin')
        ->name('payroll.bulk-generate');
    Route::post('/payroll/finalize', [PayrollController::class, 'finalize'])
        ->middleware('role:owner|admin')
        ->name('payroll.finalize');
    Route::delete('/payroll/reports/{payrollEntry}', [PayrollController::class, 'destroyReport'])
        ->middleware('role:owner|admin')
        ->name('payroll.reports.destroy');
    Route::delete('/payroll/{payrollPeriod}', [PayrollController::class, 'destroy'])
        ->middleware('role:owner|admin')
        ->name('payroll.destroy');

    // Inventory
    Route::get('/inventory', [InventoryController::class, 'index'])
        ->middleware('role:owner|admin')
        ->name('inventory.index');
    Route::get('/inventory/counts/start', [InventoryController::class, 'startCount'])
        ->middleware('role:owner|admin')
        ->name('inventory.counts.start');
    Route::post('/inventory/counts', [InventoryController::class, 'storeCount'])
        ->middleware('role:owner|admin')
        ->name('inventory.counts.store');
    Route::get('/inventory/counts/{stockCount}/details', [InventoryController::class, 'showCount'])
        ->middleware('role:owner|admin')
        ->name('inventory.counts.show');
    Route::delete('/inventory/counts/{stockCount}', [InventoryController::class, 'destroyCount'])
        ->middleware('role:owner|admin')
        ->name('inventory.counts.destroy');
    Route::get('/inventory/{ingredient}/details', [InventoryController::class, 'showIngredient'])
        ->middleware('role:owner|admin')
        ->name('inventory.show');
    Route::post('/inventory', [InventoryController::class, 'store'])
        ->middleware('role:owner|admin')
        ->name('inventory.store');
    Route::put('/inventory/{ingredient}', [InventoryController::class, 'update'])
        ->middleware('role:owner|admin')
        ->name('inventory.update');
    Route::delete('/inventory/{ingredient}', [InventoryController::class, 'destroy'])
        ->middleware('role:owner|admin')
        ->name('inventory.destroy');

    // Inventory categories — the physical walk order a stock count follows.
    // /categories/reorder MUST stay ABOVE /categories/{ingredientCategory}, or
    // the literal is swallowed by the model-bound route (the same hazard as
    // /inventory/counts/start).
    Route::post('/inventory/categories', [InventoryCategoryController::class, 'store'])
        ->middleware('role:owner|admin')
        ->name('inventory.categories.store');
    Route::post('/inventory/categories/reorder', [InventoryCategoryController::class, 'reorder'])
        ->middleware('role:owner|admin')
        ->name('inventory.categories.reorder');
    Route::put('/inventory/categories/{ingredientCategory}', [InventoryCategoryController::class, 'update'])
        ->middleware('role:owner|admin')
        ->name('inventory.categories.update');
    Route::delete('/inventory/categories/{ingredientCategory}', [InventoryCategoryController::class, 'destroy'])
        ->middleware('role:owner|admin')
        ->name('inventory.categories.destroy');
});

require __DIR__.'/auth.php';
