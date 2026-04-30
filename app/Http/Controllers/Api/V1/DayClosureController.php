<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\DayClosure;
use App\Models\Expense;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DayClosureController extends Controller
{
    public function preview(Request $request): JsonResponse
    {
        $branchId = (int) ($request->query('branch_id') ?: $request->user()->resolveBranchId());
        $date = (string) ($request->query('date') ?: now()->toDateString());

        $branch = Branch::find($branchId);
        if (! $branch) {
            return response()->json(['message' => 'Branch not found.'], 404);
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
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'closed_at_date' => ['required', 'date'],
            'opening_float' => ['nullable', 'numeric', 'min:0'],
            'counted_cash' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'auto_clockout_attendance_ids' => ['nullable', 'array'],
            'auto_clockout_attendance_ids.*' => ['integer', 'exists:attendance_records,id'],
        ]);

        $branchId = (int) ($validated['branch_id'] ?? $request->user()->resolveBranchId());
        if (! $branchId) {
            return response()->json(['message' => 'No branch resolved.'], 422);
        }

        $existing = DayClosure::where('branch_id', $branchId)
            ->whereDate('closed_at_date', $validated['closed_at_date'])
            ->first();
        if ($existing) {
            return response()->json([
                'message' => 'Day already closed for this branch.',
                'closure' => $existing,
            ], 409);
        }

        $closure = DB::transaction(function () use ($validated, $branchId, $request) {
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

        return response()->json([
            'closure' => $closure,
            'message' => 'Day closed.',
        ], 201);
    }

    public function history(Request $request): JsonResponse
    {
        $branchId = (int) ($request->query('branch_id') ?: $request->user()->resolveBranchId());
        $limit = min((int) $request->query('limit', 30), 100);

        $closures = DayClosure::where('branch_id', $branchId)
            ->orderByDesc('closed_at_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $closures->map(fn ($c) => [
                'id' => $c->id,
                'closed_at_date' => $c->closed_at_date?->toDateString(),
                'closed_at' => $c->closed_at?->toIso8601String(),
                'opening_float' => (float) $c->opening_float,
                'cash_sales_total' => (float) $c->cash_sales_total,
                'mixed_cash_total' => (float) $c->mixed_cash_total,
                'gcash_sales_total' => (float) $c->gcash_sales_total,
                'cash_expenses_total' => (float) $c->cash_expenses_total,
                'expected_cash' => (float) $c->expected_cash,
                'counted_cash' => (float) $c->counted_cash,
                'variance' => (float) $c->variance,
                'order_count' => $c->order_count,
                'expense_count' => $c->expense_count,
                'auto_clocked_out_count' => $c->auto_clocked_out_count,
                'notes' => $c->notes,
            ]),
        ]);
    }

    private function computeTotals(int $branchId, string $date): array
    {
        $salesQuery = Sale::query()
            ->where('branch_id', $branchId)
            ->where('status', 'completed')
            ->whereDate('sale_datetime', $date);

        $cashSales = (float) (clone $salesQuery)->where('payment_method', 'cash')->sum('grand_total');
        $gcashSales = (float) (clone $salesQuery)->where('payment_method', 'gcash')->sum('grand_total');
        $mixedCash = (float) (clone $salesQuery)->where('payment_method', 'mixed')->sum('cash_amount');
        $mixedGcash = (float) (clone $salesQuery)->where('payment_method', 'mixed')->sum('gcash_amount');
        $orderCount = (clone $salesQuery)->count();

        $expensesQuery = Expense::query()
            ->where('branch_id', $branchId)
            ->where('status', 'approved')
            ->whereDate('expense_date', $date);

        $cashExpenses = (float) (clone $expensesQuery)->where('payment_method', 'cash')->sum('amount');
        $expenseCount = (clone $expensesQuery)->count();

        return [
            'cash_sales_total' => $cashSales,
            'mixed_cash_total' => $mixedCash,
            'gcash_sales_total' => $gcashSales + $mixedGcash,
            'cash_expenses_total' => $cashExpenses,
            'order_count' => $orderCount,
            'expense_count' => $expenseCount,
        ];
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
}
