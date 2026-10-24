<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\DayClosure;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Ingredient;
use App\Models\PayrollEntry;
use App\Models\Sale;
use App\Models\SpecialExpense;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        // The super admin sees every branch added up; a branch admin sees only their
        // own. A cashier never reaches here: 'cashier.pos' sends them to the POS.
        abort_unless($request->user()->canManage(), 403);
        $branchId = $request->user()->lockedBranchId();
        // Narrows a query to the admin's branch; a no-op for the super admin.
        $scope = fn ($query, string $column = 'branch_id') => $branchId === null ? $query : $query->where($column, $branchId);

        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();

        // ── Active headcount ──────────────────────────────────────────────
        $activeEmployees = $scope(Employee::where('is_active', true))->count();
        $activeBranches = $scope(Branch::where('is_active', true), 'id')->count();

        // ── Today's sales (split by payment method) ───────────────────────
        $todaySalesQuery = $scope(Sale::whereDate('sale_datetime', $today)->where('status', 'completed'));
        $todaySales = (float) (clone $todaySalesQuery)->sum('grand_total');
        $todayOrderCount = (clone $todaySalesQuery)->count();

        $todayCashOnly = (float) (clone $todaySalesQuery)->where('payment_method', 'cash')->sum('grand_total');
        $todayGcashOnly = (float) (clone $todaySalesQuery)->where('payment_method', 'gcash')->sum('grand_total');
        $todayMixedCash = (float) (clone $todaySalesQuery)->where('payment_method', 'mixed')->sum('cash_amount');
        $todayMixedGcash = (float) (clone $todaySalesQuery)->where('payment_method', 'mixed')->sum('gcash_amount');

        $todayCashSales = $todayCashOnly + $todayMixedCash;
        $todayGcashSales = $todayGcashOnly + $todayMixedGcash;

        $todayCashOrderCount = (clone $todaySalesQuery)->where('payment_method', 'cash')->count();
        $todayGcashOrderCount = (clone $todaySalesQuery)->where('payment_method', 'gcash')->count();
        $todayMixedOrderCount = (clone $todaySalesQuery)->where('payment_method', 'mixed')->count();

        // ── Today's expenses ──────────────────────────────────────────────
        $todayExpensesQuery = $scope(Expense::whereDate('expense_date', $today)->where('status', 'approved'));
        $todayExpenses = (float) (clone $todayExpensesQuery)->sum('amount');
        $todayCashExpenses = (float) (clone $todayExpensesQuery)->where('payment_method', 'cash')->sum('amount');

        // ── Derived headline metrics ──────────────────────────────────────
        $todayNetIncome = $todaySales - $todayExpenses;
        $todayCashOnHand = $todayCashSales - $todayCashExpenses;

        // ── Today's attendance ────────────────────────────────────────────
        $todayAttendance = $scope(AttendanceRecord::whereDate('work_date', $today))
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $presentToday = (int) ($todayAttendance->get('present', 0) + $todayAttendance->get('late', 0));
        $absentToday = (int) $todayAttendance->get('absent', 0);
        $lateToday = (int) $todayAttendance->get('late', 0);
        $totalClockedToday = (int) $todayAttendance->sum();

        // ── Month-to-date ─────────────────────────────────────────────────
        $mtdSales = (float) $scope(Sale::query())->whereDate('sale_datetime', '>=', $monthStart)
            ->whereDate('sale_datetime', '<=', $today)
            ->where('status', 'completed')
            ->sum('grand_total');

        $mtdExpenses = (float) $scope(Expense::query())->whereDate('expense_date', '>=', $monthStart)
            ->whereDate('expense_date', '<=', $today)
            ->where('status', 'approved')
            ->sum('amount');

        // ── Monthly overhead (rent, electricity) ──────────────────────────
        // Read from `special_expenses`, NOT `expenses`. Overhead is deliberately
        // absent from every "today" figure above — a month's rent landing on the
        // 1st would otherwise read as a catastrophic single-day loss and would
        // throw that day's drawer variance by the full amount. It belongs only
        // to the month, which is what the panel below reports.
        // A branch admin sees their branch's overhead only; company-wide overhead
        // (branch_id NULL) is the super admin's.
        $mtdOverheadQuery = $scope(SpecialExpense::query()->forMonth($monthStart), 'special_expenses.branch_id');
        $mtdOverhead = (float) (clone $mtdOverheadQuery)->sum('amount');

        // Net after overhead is the only figure on this page that is true profit:
        // MTD revenue less both daily operating expenses and monthly overhead.
        $mtdNetAfterOverhead = $mtdSales - $mtdExpenses - $mtdOverhead;

        $overheadBreakdown = $scope(SpecialExpense::query()->forMonth($monthStart), 'special_expenses.branch_id')
            ->leftJoin(
                'special_expense_categories',
                'special_expenses.special_expense_category_id',
                '=',
                'special_expense_categories.id'
            )
            ->selectRaw("COALESCE(special_expense_categories.name, 'Uncategorized') as cat_name, SUM(special_expenses.amount) as total")
            ->groupBy('cat_name')
            ->orderByDesc('total')
            // No limit: these rows sit directly under the Overhead tile and must
            // sum to it. A month has a handful of overhead categories, not many,
            // and a silent top-N would read as the tile being wrong.
            ->get()
            ->map(fn ($row) => [
                'name' => (string) $row->cat_name,
                'total' => (float) $row->total,
            ])
            ->all();

        $draftPayrolls = PayrollEntry::where('status', 'draft')
            ->when($branchId !== null, fn ($q) => $q->whereHas('payrollPeriod', fn ($p) => $p->where('branch_id', $branchId)))
            ->count();

        // ── 7-day sales trend ─────────────────────────────────────────────
        $sevenDaysAgo = now()->subDays(6)->toDateString();
        $dailySalesMap = $scope(Sale::query())->whereDate('sale_datetime', '>=', $sevenDaysAgo)
            ->where('status', 'completed')
            ->selectRaw('DATE(sale_datetime) as sale_date, SUM(grand_total) as total')
            ->groupBy('sale_date')
            ->pluck('total', 'sale_date');

        $last7Days = collect(range(6, 0))->map(function ($i) use ($dailySalesMap) {
            $date = now()->subDays($i)->toDateString();

            return [
                'date' => $date,
                'label' => now()->subDays($i)->format('D'),
                'total' => (float) ($dailySalesMap->get($date, 0)),
                'is_today' => $i === 0,
            ];
        });

        $chartMax = max((float) $last7Days->max('total'), 1.0);

        // ── Low stock alerts ──────────────────────────────────────────────
        $lowStockItems = $scope(Ingredient::where('is_active', true))
            ->whereColumn('current_stock', '<=', 'reorder_level')
            ->count();

        // ── Recent activity ───────────────────────────────────────────────
        $recentSales = $scope(Sale::where('status', 'completed'))
            ->orderByDesc('sale_datetime')
            ->limit(6)
            ->get(['order_number', 'sale_datetime', 'grand_total', 'order_type', 'payment_method']);

        $recentExpenses = $scope(Expense::where('status', 'approved'))
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->limit(6)
            ->get(['expense_date', 'description', 'amount', 'expense_category_id', 'vendor_name']);

        $expenseCategoryNames = ExpenseCategory::whereIn(
            'id',
            $recentExpenses->pluck('expense_category_id')->filter()->unique()
        )->pluck('name', 'id');

        // ── Today's closures, one row per branch ──────────────────────────
        $branchClosures = $this->branchClosures($today, $branchId);

        return view('dashboard', compact(
            'activeEmployees',
            'activeBranches',
            'todaySales',
            'todayExpenses',
            'todayNetIncome',
            'todayCashSales',
            'todayGcashSales',
            'todayCashExpenses',
            'todayCashOnHand',
            'todayOrderCount',
            'todayCashOrderCount',
            'todayGcashOrderCount',
            'todayMixedOrderCount',
            'presentToday',
            'absentToday',
            'lateToday',
            'totalClockedToday',
            'mtdSales',
            'mtdExpenses',
            'mtdOverhead',
            'mtdNetAfterOverhead',
            'overheadBreakdown',
            'draftPayrolls',
            'last7Days',
            'chartMax',
            'lowStockItems',
            'recentSales',
            'recentExpenses',
            'expenseCategoryNames',
            'branchClosures'
        ));
    }

    /**
     * Whether each branch has closed $date. Closing is per branch: one branch
     * signing off says nothing about another, so the dashboard lists every
     * active branch (plus any inactive one that still closed that day).
     *
     * @return Collection<int, array{branch_id: int, branch_name: string, closure: ?DayClosure}>
     */
    private function branchClosures(string $date, ?int $onlyBranchId = null): Collection
    {
        $closures = DayClosure::whereDate('closed_at_date', $date)
            ->when($onlyBranchId !== null, fn ($q) => $q->where('branch_id', $onlyBranchId))
            ->with('closedBy:id,name')
            ->orderByDesc('id')
            ->get()
            ->unique('branch_id')
            ->keyBy('branch_id');

        return Branch::query()
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $closures->keys()))
            ->when($onlyBranchId !== null, fn ($q) => $q->whereKey($onlyBranchId))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Branch $branch) => [
                'branch_id' => (int) $branch->id,
                'branch_name' => $branch->name,
                'closure' => $closures->get($branch->id),
            ])
            ->values();
    }
}
