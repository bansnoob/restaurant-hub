<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\DayClosure;
use App\Models\Expense;
use App\Models\Sale;
use App\Services\CashReportService;
use App\Services\DayClosureRecalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DayClosureController extends Controller
{
    public function preview(Request $request): JsonResponse
    {
        $branchId = (int) ($request->query('branch_id') ?: $this->resolveDefaultBranchId($request));
        $date = (string) ($request->query('date') ?: now()->toDateString());

        $branch = Branch::find($branchId);
        if (! $branch) {
            return response()->json(['error' => 'Branch not found.'], 404);
        }

        $existing = DayClosure::where('branch_id', $branchId)
            ->whereDate('closed_at_date', $date)
            ->with('closedBy:id,name')
            ->first();

        $totals = $this->computeTotals($branchId, $date);
        $defaultOpeningFloat = $this->defaultOpeningFloat($branchId, $date);
        $expectedCash = $defaultOpeningFloat + $totals['cash_sales_total'] + $totals['mixed_cash_total'] - $totals['cash_expenses_total'];

        $stillClockedIn = AttendanceRecord::with('employee:id,first_name,last_name,employee_code')
            ->where('branch_id', $branchId)
            ->whereDate('work_date', $date)
            ->whereNotNull('clock_in_at')
            ->whereNull('clock_out_at')
            ->orderBy('clock_in_at')
            ->get(['id', 'employee_id', 'clock_in_at']);

        return response()->json([
            'branch' => ['id' => $branch->id, 'name' => $branch->name],
            'date' => $date,
            'date_label' => Carbon::parse($date)->format('l, M j, Y'),
            'already_closed' => $existing ? [
                'id' => $existing->id,
                'closed_at' => $existing->closed_at?->toIso8601String(),
                'closed_at_label' => $existing->closed_at?->format('h:i A'),
                'closed_by' => $existing->closedBy?->name,
                'counted_cash' => (float) $existing->counted_cash,
                'expected_cash' => (float) $existing->expected_cash,
                'variance' => (float) $existing->variance,
                'opening_float' => (float) $existing->opening_float,
            ] : null,
            'opening_float_default' => round($defaultOpeningFloat, 2),
            'totals' => [
                'cash_sales_total' => round($totals['cash_sales_total'], 2),
                'mixed_cash_total' => round($totals['mixed_cash_total'], 2),
                'gcash_sales_total' => round($totals['gcash_sales_total'], 2),
                'cash_expenses_total' => round($totals['cash_expenses_total'], 2),
                'order_count' => $totals['order_count'],
                'expense_count' => $totals['expense_count'],
                'expected_cash' => round($expectedCash, 2),
            ],
            'still_clocked_in' => $stillClockedIn->map(fn ($r) => [
                'attendance_id' => $r->id,
                'employee_id' => $r->employee_id,
                'name' => trim(($r->employee?->first_name ?? '').' '.($r->employee?->last_name ?? '')),
                'employee_code' => $r->employee?->employee_code,
                'clock_in_at' => $r->clock_in_at?->toIso8601String(),
                'clock_in_label' => $r->clock_in_at?->format('h:i A'),
            ]),
            'available_branches' => Branch::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'closed_at_date' => ['required', 'date', 'before_or_equal:today'],
            'opening_float' => ['nullable', 'numeric', 'min:0'],
            'counted_cash' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'auto_clockout_attendance_ids' => ['nullable', 'array'],
            'auto_clockout_attendance_ids.*' => ['integer', 'exists:attendance_records,id'],
        ]);

        $existing = DayClosure::where('branch_id', $validated['branch_id'])
            ->whereDate('closed_at_date', $validated['closed_at_date'])
            ->first();
        if ($existing) {
            $message = 'Day already closed for this branch.';
            return $request->wantsJson()
                ? response()->json(['error' => $message, 'closure' => $existing], 409)
                : back()->with('error', $message);
        }

        $closure = DB::transaction(function () use ($validated, $request) {
            $branchId = (int) $validated['branch_id'];
            $date = (string) $validated['closed_at_date'];

            $totals = $this->computeTotals($branchId, $date);
            $openingFloat = (float) ($validated['opening_float'] ?? 0);
            $expected = $openingFloat + $totals['cash_sales_total'] + $totals['mixed_cash_total'] - $totals['cash_expenses_total'];
            $counted = (float) $validated['counted_cash'];
            $variance = $counted - $expected;

            $forcedIds = $validated['auto_clockout_attendance_ids'] ?? [];
            $forcedCount = 0;
            if (! empty($forcedIds)) {
                $records = AttendanceRecord::where('branch_id', $branchId)
                    ->whereDate('work_date', $date)
                    ->whereIn('id', $forcedIds)
                    ->whereNotNull('clock_in_at')
                    ->whereNull('clock_out_at')
                    ->get();

                foreach ($records as $record) {
                    $record->update([
                        'clock_out_at' => now(),
                        'captured_by_user_id' => $request->user()->id,
                    ]);
                    $forcedCount++;
                }
            }

            return DayClosure::create([
                'branch_id' => $branchId,
                'closed_at_date' => $date,
                'closed_by_user_id' => $request->user()->id,
                'closed_at' => now(),
                'opening_float' => round($openingFloat, 2),
                'cash_sales_total' => round($totals['cash_sales_total'], 2),
                'mixed_cash_total' => round($totals['mixed_cash_total'], 2),
                'gcash_sales_total' => round($totals['gcash_sales_total'], 2),
                'cash_expenses_total' => round($totals['cash_expenses_total'], 2),
                'expected_cash' => round($expected, 2),
                'counted_cash' => round($counted, 2),
                'variance' => round($variance, 2),
                'order_count' => $totals['order_count'],
                'expense_count' => $totals['expense_count'],
                'auto_clocked_out_count' => $forcedCount,
                'notes' => $validated['notes'] ?? null,
            ]);
        });

        $message = 'Day closed. '.($closure->auto_clocked_out_count > 0
            ? $closure->auto_clocked_out_count.' employee'.($closure->auto_clocked_out_count === 1 ? '' : 's').' clocked out.'
            : '');
        $message = trim($message);

        if ($request->wantsJson()) {
            return response()->json(['closure' => $closure, 'message' => $message]);
        }

        return back()->with('success', $message);
    }

    public function index(Request $request, CashReportService $cashReport): View
    {
        $branchFilter = $request->query('branch_id');
        $dateFrom = $request->string('date_from')->toString() ?: now()->subDays(29)->toDateString();
        $dateTo = $request->string('date_to')->toString() ?: now()->toDateString();
        $branchId = (! empty($branchFilter) && is_numeric($branchFilter)) ? (int) $branchFilter : null;

        $branches = Branch::where('is_active', true)->orderBy('name')->get();

        // Unclosed days have no row to paginate over, so the union is built in memory and
        // sliced here. The range bounds it: 30 days x branches, not the whole table.
        $rows = $cashReport->dayRows($dateFrom, $dateTo, $branchId);
        $totals = $cashReport->totals($rows, $dateFrom, $dateTo, $branchId);

        $perPage = 30;
        $page = LengthAwarePaginator::resolveCurrentPage();
        $closures = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('modules.day_closures.index', [
            'branches' => $branches,
            'closures' => $closures,
            'totals' => $totals,
            'filters' => [
                'branch_id' => $branchFilter,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
        ]);
    }

    /**
     * The day's current state for the Edit Day dialog.
     *
     * Sales are reported but not editable: they come from the POS and are the audit
     * trail this figure is checked against. Cash expenses are what actually gets
     * corrected after the fact, so those come back as rows.
     */
    public function edit(DayClosure $dayClosure): JsonResponse
    {
        $date = $dayClosure->closed_at_date->format('Y-m-d');
        $branchId = (int) $dayClosure->branch_id;

        $expenses = Expense::query()
            ->where('branch_id', $branchId)
            ->where('status', 'approved')
            ->where('payment_method', 'cash')
            ->whereDate('expense_date', $date)
            ->orderBy('id')
            ->get(['id', 'description', 'vendor_name', 'amount']);

        $totals = $this->recalculator()->totalsFor($branchId, $date);

        return response()->json([
            'id' => $dayClosure->id,
            'date' => $date,
            'date_label' => $dayClosure->closed_at_date->format('l, M j, Y'),
            'branch' => ['id' => $branchId, 'name' => $dayClosure->branch?->name],
            'opening_float' => (float) $dayClosure->opening_float,
            'counted_cash' => (float) $dayClosure->counted_cash,
            'notes' => $dayClosure->notes,
            'cash_sales_total' => round($totals['cash_sales_total'] + $totals['mixed_cash_total'], 2),
            'order_count' => $totals['order_count'],
            'cash_expenses_total' => round($totals['cash_expenses_total'], 2),
            'expenses' => $expenses->map(fn (Expense $e) => [
                'id' => $e->id,
                'description' => $e->description,
                'vendor_name' => $e->vendor_name,
                'amount' => (float) $e->amount,
            ]),
        ]);
    }

    /**
     * Apply an edit to a closed day.
     *
     * Counted cash, notes and the day's cash expenses all move together in one
     * transaction, then the closure is recomputed from the rows that now exist. Doing
     * the arithmetic here instead of trusting the client is what keeps expected_cash
     * honest — it is always a function of the rows, never of what a form posted.
     */
    public function update(Request $request, DayClosure $dayClosure, DayClosureRecalculator $recalculator): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'counted_cash' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'expenses' => ['nullable', 'array'],
            'expenses.*.id' => ['nullable', 'integer', 'exists:expenses,id'],
            'expenses.*.description' => ['nullable', 'string', 'max:255'],
            'expenses.*.vendor_name' => ['nullable', 'string', 'max:140'],
            'expenses.*.amount' => ['required_with:expenses', 'numeric', 'min:0'],
            'deleted_expense_ids' => ['nullable', 'array'],
            'deleted_expense_ids.*' => ['integer', 'exists:expenses,id'],
        ]);

        $date = $dayClosure->closed_at_date->format('Y-m-d');
        $branchId = (int) $dayClosure->branch_id;

        DB::transaction(function () use ($validated, $dayClosure, $branchId, $date, $request) {
            // Scoped to this branch and date so an id from another day cannot be
            // smuggled in through the form and silently deleted.
            $ownExpenses = Expense::query()
                ->where('branch_id', $branchId)
                ->whereDate('expense_date', $date)
                ->pluck('id')
                ->all();

            foreach ($validated['deleted_expense_ids'] ?? [] as $deleteId) {
                if (in_array((int) $deleteId, $ownExpenses, true)) {
                    Expense::where('id', $deleteId)->delete();
                }
            }

            foreach ($validated['expenses'] ?? [] as $row) {
                $payload = [
                    'description' => $row['description'] ?? null,
                    'vendor_name' => $row['vendor_name'] ?? null,
                    'amount' => round((float) $row['amount'], 2),
                ];

                if (! empty($row['id']) && in_array((int) $row['id'], $ownExpenses, true)) {
                    Expense::where('id', $row['id'])->update($payload);

                    continue;
                }

                Expense::create($payload + [
                    'branch_id' => $branchId,
                    'expense_date' => $date,
                    'payment_method' => 'cash',
                    'status' => 'approved',
                    'recorded_by_user_id' => $request->user()->id,
                ]);
            }

            $dayClosure->update([
                'counted_cash' => round((float) $validated['counted_cash'], 2),
                'notes' => $validated['notes'] ?? null,
            ]);
        });

        $recalculator->recalculate($dayClosure->refresh());

        if ($request->wantsJson()) {
            return response()->json(['closure' => $dayClosure->fresh()]);
        }

        return back()->with('success', 'Day updated.');
    }

    private function recalculator(): DayClosureRecalculator
    {
        return app(DayClosureRecalculator::class);
    }

    /**
     * @return array{cash_sales_total: float, mixed_cash_total: float, gcash_sales_total: float, cash_expenses_total: float, order_count: int, expense_count: int}
     */
    private function computeTotals(int $branchId, string $date): array
    {
        // Delegated, not duplicated: this arithmetic also has to be right in the
        // recalculator, and two copies of it drifting apart is precisely how a closure
        // comes to disagree with its own rows.
        return $this->recalculator()->totalsFor($branchId, $date);
    }

    private function defaultOpeningFloat(int $branchId, string $date): float
    {
        $previous = DayClosure::where('branch_id', $branchId)
            ->whereDate('closed_at_date', '<', $date)
            ->orderByDesc('closed_at_date')
            ->orderByDesc('id')
            ->first();

        return $previous ? (float) $previous->opening_float : 0.0;
    }

    private function resolveDefaultBranchId(Request $request): int
    {
        $userBranch = $request->user()?->branch_id;
        if ($userBranch) {
            return (int) $userBranch;
        }
        return (int) (Branch::where('is_active', true)->orderBy('id')->value('id') ?? 0);
    }
}
