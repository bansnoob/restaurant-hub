<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Ingredient;
use App\Models\PayrollPeriod;
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

        // ── Today's snapshot ──────────────────────────────────────────────
        $todaySales = Sale::whereDate('sale_datetime', $today)
            ->where('status', 'completed')
            ->sum('grand_total');

        $todayExpenses = Expense::whereDate('expense_date', $today)
            ->where('status', 'approved')
            ->sum('amount');

        $todayAttendance = AttendanceRecord::whereDate('work_date', $today)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $presentToday = (int) ($todayAttendance->get('present', 0) + $todayAttendance->get('late', 0));
        $absentToday = (int) $todayAttendance->get('absent', 0);
        $lateToday = (int) $todayAttendance->get('late', 0);
        $totalClockedToday = (int) $todayAttendance->sum();

        // ── Month-to-date ─────────────────────────────────────────────────
        $mtdSales = Sale::whereDate('sale_datetime', '>=', $monthStart)
            ->whereDate('sale_datetime', '<=', $today)
            ->where('status', 'completed')
            ->sum('grand_total');

        $mtdExpenses = Expense::whereDate('expense_date', '>=', $monthStart)
            ->whereDate('expense_date', '<=', $today)
            ->where('status', 'approved')
            ->sum('amount');

        $draftPayrolls = PayrollPeriod::where('status', 'draft')->count();

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

        $chartMax = max($last7Days->max('total'), 1);

        // ── Low stock alerts ──────────────────────────────────────────────
        $lowStockItems = Ingredient::where('is_active', true)
            ->whereColumn('current_stock', '<=', 'reorder_level')
            ->count();

        // ── Recent sales ──────────────────────────────────────────────────
        $recentSales = Sale::where('status', 'completed')
            ->orderByDesc('sale_datetime')
            ->limit(6)
            ->get(['order_number', 'sale_datetime', 'grand_total', 'order_type', 'payment_method']);

        // ── Recent expenses ───────────────────────────────────────────────
        $recentExpenses = Expense::where('status', 'approved')
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->limit(6)
            ->get(['expense_date', 'description', 'amount', 'expense_category_id', 'vendor_name']);

        $expenseCategoryNames = ExpenseCategory::whereIn(
            'id',
            $recentExpenses->pluck('expense_category_id')->filter()->unique()
        )->pluck('name', 'id');

        return view('dashboard', compact(
            'activeEmployees',
            'activeBranches',
            'todaySales',
            'todayExpenses',
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
            'expenseCategoryNames'
        ));
    }
}
