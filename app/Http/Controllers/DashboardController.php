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

class DashboardController extends Controller
{
    public function index()
    {
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();

        // ── Active headcount ──────────────────────────────────────────────
        $activeEmployees = Employee::where('is_active', true)->count();
        $activeBranches = Branch::where('is_active', true)->count();

        // ── Today's sales (split by payment method) ───────────────────────
        $todaySalesQuery = Sale::whereDate('sale_datetime', $today)->where('status', 'completed');
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
        $todayExpensesQuery = Expense::whereDate('expense_date', $today)->where('status', 'approved');
        $todayExpenses = (float) (clone $todayExpensesQuery)->sum('amount');
        $todayCashExpenses = (float) (clone $todayExpensesQuery)->where('payment_method', 'cash')->sum('amount');

        // ── Derived headline metrics ──────────────────────────────────────
        $todayNetIncome = $todaySales - $todayExpenses;
        $todayCashOnHand = $todayCashSales - $todayCashExpenses;

        // ── Today's attendance ────────────────────────────────────────────
        $todayAttendance = AttendanceRecord::whereDate('work_date', $today)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $presentToday = (int) ($todayAttendance->get('present', 0) + $todayAttendance->get('late', 0));
        $absentToday = (int) $todayAttendance->get('absent', 0);
        $lateToday = (int) $todayAttendance->get('late', 0);
        $totalClockedToday = (int) $todayAttendance->sum();

        // ── Month-to-date ─────────────────────────────────────────────────
        $mtdSales = (float) Sale::whereDate('sale_datetime', '>=', $monthStart)
            ->whereDate('sale_datetime', '<=', $today)
            ->where('status', 'completed')
            ->sum('grand_total');

        $mtdExpenses = (float) Expense::whereDate('expense_date', '>=', $monthStart)
            ->whereDate('expense_date', '<=', $today)
            ->where('status', 'approved')
            ->sum('amount');

        $draftPayrolls = PayrollEntry::where('status', 'draft')->count();

        // ── 7-day sales trend ─────────────────────────────────────────────
        $sevenDaysAgo = now()->subDays(6)->toDateString();
        $dailySalesMap = Sale::whereDate('sale_datetime', '>=', $sevenDaysAgo)
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
        $lowStockItems = Ingredient::where('is_active', true)
            ->whereColumn('current_stock', '<=', 'reorder_level')
            ->count();

        // ── Recent activity ───────────────────────────────────────────────
        $recentSales = Sale::where('status', 'completed')
            ->orderByDesc('sale_datetime')
            ->limit(6)
            ->get(['order_number', 'sale_datetime', 'grand_total', 'order_type', 'payment_method']);

        $recentExpenses = Expense::where('status', 'approved')
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->limit(6)
            ->get(['expense_date', 'description', 'amount', 'expense_category_id', 'vendor_name']);

        $expenseCategoryNames = ExpenseCategory::whereIn(
            'id',
            $recentExpenses->pluck('expense_category_id')->filter()->unique()
        )->pluck('name', 'id');

        // ── Today's closure ───────────────────────────────────────────────
        $todayClosure = DayClosure::whereDate('closed_at_date', $today)
            ->with('closedBy:id,name')
            ->orderByDesc('id')
            ->first();

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
            'draftPayrolls',
            'last7Days',
            'chartMax',
            'lowStockItems',
            'recentSales',
            'recentExpenses',
            'expenseCategoryNames',
            'todayClosure'
        ));
    }
}
