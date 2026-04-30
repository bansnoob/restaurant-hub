<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ExpenseController extends Controller
{
    private const PAYMENT_METHODS = ['cash', 'bank_transfer', 'gcash', 'other'];

    public function index(Request $request): View
    {
        $preset = (string) $request->query('preset', '');
        [$dateFrom, $dateTo] = $this->resolveRange($request, $preset);

        $branchFilter = $request->query('branch_id');
        $categoryFilter = $request->query('expense_category_id');
        $paymentFilter = (string) $request->query('payment_method', '');
        $search = trim((string) $request->query('search', ''));

        $branches = Branch::where('is_active', true)->orderBy('name')->get();
        $categories = ExpenseCategory::where('is_active', true)->orderBy('name')->get();

        $base = Expense::query()
            ->whereDate('expense_date', '>=', $dateFrom)
            ->whereDate('expense_date', '<=', $dateTo)
            ->where('status', 'approved');

        if (! empty($branchFilter) && is_numeric($branchFilter)) {
            $base->where('branch_id', (int) $branchFilter);
        }

        $listQuery = (clone $base);

        if (! empty($categoryFilter) && is_numeric($categoryFilter)) {
            $listQuery->where('expense_category_id', (int) $categoryFilter);
        }
        if (in_array($paymentFilter, self::PAYMENT_METHODS, true)) {
            $listQuery->where('payment_method', $paymentFilter);
        }
        if ($search !== '') {
            $needle = '%'.$search.'%';
            $listQuery->where(function ($q) use ($needle) {
                $q->where('description', 'like', $needle)
                  ->orWhere('vendor_name', 'like', $needle)
                  ->orWhere('reference_no', 'like', $needle);
            });
        }

        $expenses = (clone $listQuery)
            ->with(['branch:id,name', 'category:id,name'])
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $total = (float) (clone $base)->sum('amount');
        $count = (clone $base)->count();

        $paymentBreakdown = $this->paymentBreakdown(clone $base);
        $categoryBreakdown = $this->categoryBreakdown(clone $base);
        $dailySeries = $this->dailySeries(clone $base, $dateFrom, $dateTo);

        $summary = [
            'total' => $total,
            'count' => $count,
            'cash' => $paymentBreakdown['cash']['total'],
            'gcash' => $paymentBreakdown['gcash']['total'],
            'bank_transfer' => $paymentBreakdown['bank_transfer']['total'],
            'other' => $paymentBreakdown['other']['total'],
        ];

        $filters = [
            'preset' => $preset,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'branch_id' => $branchFilter,
            'expense_category_id' => $categoryFilter,
            'payment_method' => $paymentFilter,
            'search' => $search,
        ];

        return view('modules.expenses.index', compact(
            'expenses',
            'categories',
            'branches',
            'summary',
            'paymentBreakdown',
            'categoryBreakdown',
            'dailySeries',
            'filters',
        ));
    }

    public function show(Expense $expense): JsonResponse
    {
        $expense->load(['branch:id,name', 'category:id,name', 'recordedBy:id,name']);

        return response()->json([
            'expense' => [
                'id' => $expense->id,
                'branch_id' => $expense->branch_id,
                'expense_category_id' => $expense->expense_category_id,
                'expense_date' => $expense->expense_date,
                'expense_date_label' => Carbon::parse($expense->expense_date)->format('M j, Y'),
                'reference_no' => $expense->reference_no,
                'vendor_name' => $expense->vendor_name,
                'description' => $expense->description,
                'amount' => (float) $expense->amount,
                'payment_method' => $expense->payment_method,
                'status' => $expense->status,
                'notes' => $expense->notes,
                'branch' => $expense->branch ? ['id' => $expense->branch->id, 'name' => $expense->branch->name] : null,
                'category' => $expense->category ? ['id' => $expense->category->id, 'name' => $expense->category->name] : null,
                'recorded_by' => $expense->recordedBy?->name,
                'created_at' => $expense->created_at?->toIso8601String(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'expense_category_id' => ['nullable', 'integer', 'exists:expense_categories,id'],
            'expense_date' => ['required', 'date'],
            'reference_no' => ['nullable', 'string', 'max:60'],
            'vendor_name' => ['nullable', 'string', 'max:140'],
            'description' => ['required', 'string', 'max:200'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required', Rule::in(self::PAYMENT_METHODS)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'new_category_name' => ['nullable', 'string', 'max:100'],
        ]);

        if (! empty($validated['new_category_name'])) {
            $category = ExpenseCategory::firstOrCreate(
                [
                    'branch_id' => $validated['branch_id'],
                    'slug' => Str::slug($validated['new_category_name']),
                ],
                [
                    'name' => $validated['new_category_name'],
                    'is_active' => true,
                ]
            );
            $validated['expense_category_id'] = $category->id;
        }

        Expense::create([
            'branch_id' => $validated['branch_id'],
            'expense_category_id' => $validated['expense_category_id'] ?? null,
            'recorded_by_user_id' => $request->user()->id,
            'expense_date' => $validated['expense_date'],
            'reference_no' => $validated['reference_no'] ?? null,
            'vendor_name' => $validated['vendor_name'] ?? null,
            'description' => $validated['description'],
            'amount' => $validated['amount'],
            'payment_method' => $validated['payment_method'],
            'status' => 'approved',
            'notes' => $validated['notes'] ?? null,
        ]);

        return back()->with('success', 'Expense recorded successfully.');
    }

    public function update(Request $request, Expense $expense): RedirectResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'expense_category_id' => ['nullable', 'integer', 'exists:expense_categories,id'],
            'expense_date' => ['required', 'date'],
            'reference_no' => ['nullable', 'string', 'max:60'],
            'vendor_name' => ['nullable', 'string', 'max:140'],
            'description' => ['required', 'string', 'max:200'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required', Rule::in(self::PAYMENT_METHODS)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'new_category_name' => ['nullable', 'string', 'max:100'],
        ]);

        if (! empty($validated['new_category_name'])) {
            $category = ExpenseCategory::firstOrCreate(
                [
                    'branch_id' => $validated['branch_id'],
                    'slug' => Str::slug($validated['new_category_name']),
                ],
                [
                    'name' => $validated['new_category_name'],
                    'is_active' => true,
                ]
            );
            $validated['expense_category_id'] = $category->id;
        }

        $expense->update([
            'branch_id' => $validated['branch_id'],
            'expense_category_id' => $validated['expense_category_id'] ?? null,
            'expense_date' => $validated['expense_date'],
            'reference_no' => $validated['reference_no'] ?? null,
            'vendor_name' => $validated['vendor_name'] ?? null,
            'description' => $validated['description'],
            'amount' => $validated['amount'],
            'payment_method' => $validated['payment_method'],
            'notes' => $validated['notes'] ?? null,
        ]);

        return back()->with('success', 'Expense updated successfully.');
    }

    public function destroy(Expense $expense): RedirectResponse
    {
        try {
            $expense->delete();
        } catch (QueryException) {
            return back()->with('error', 'Unable to delete expense.');
        }

        return back()->with('success', 'Expense deleted successfully.');
    }

    /**
     * @return array<int, string>
     */
    private function resolveRange(Request $request, string $preset): array
    {
        $today = now()->toDateString();

        switch ($preset) {
            case 'today':
                return [$today, $today];
            case 'yesterday':
                $y = now()->subDay()->toDateString();
                return [$y, $y];
            case '7d':
                return [now()->subDays(6)->toDateString(), $today];
            case '30d':
                return [now()->subDays(29)->toDateString(), $today];
            case 'month':
                return [now()->startOfMonth()->toDateString(), $today];
            default:
                $from = $request->string('date_from')->toString() ?: now()->startOfMonth()->toDateString();
                $to = $request->string('date_to')->toString() ?: $today;
                if (Carbon::parse($from)->gt(Carbon::parse($to))) {
                    [$from, $to] = [$to, $from];
                }
                return [$from, $to];
        }
    }

    /**
     * @return array<string, array{count: int, total: float}>
     */
    private function paymentBreakdown($query): array
    {
        $rows = $query
            ->selectRaw('payment_method, COUNT(*) as cnt, SUM(amount) as total')
            ->groupBy('payment_method')
            ->get()
            ->keyBy('payment_method');

        $breakdown = [];
        foreach (self::PAYMENT_METHODS as $method) {
            $row = $rows->get($method);
            $breakdown[$method] = [
                'count' => $row ? (int) $row->cnt : 0,
                'total' => $row ? (float) $row->total : 0.0,
            ];
        }
        return $breakdown;
    }

    /**
     * @return array<int, array{name: string, count: int, total: float}>
     */
    private function categoryBreakdown($query): array
    {
        $rows = $query
            ->leftJoin('expense_categories', 'expenses.expense_category_id', '=', 'expense_categories.id')
            ->selectRaw('COALESCE(expense_categories.name, "Uncategorized") as cat_name, COUNT(*) as cnt, SUM(expenses.amount) as total')
            ->groupBy('cat_name')
            ->orderByDesc('total')
            ->get();

        return $rows->map(fn ($row) => [
            'name' => (string) $row->cat_name,
            'count' => (int) $row->cnt,
            'total' => (float) $row->total,
        ])->all();
    }

    /**
     * @return array<int, array{date: string, label: string, short: string, total: float, count: int, is_today: bool}>
     */
    private function dailySeries($query, string $dateFrom, string $dateTo): array
    {
        $start = Carbon::parse($dateFrom);
        $end = Carbon::parse($dateTo);
        $days = (int) $start->diffInDays($end) + 1;
        if ($days > 31) {
            $start = $end->copy()->subDays(13);
        }

        $rows = $query
            ->selectRaw('expense_date as day, COUNT(*) as cnt, SUM(amount) as total')
            ->whereDate('expense_date', '>=', $start->toDateString())
            ->groupBy('day')
            ->get()
            ->keyBy(fn ($row) => (string) $row->day);

        $today = now()->toDateString();
        $series = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $date = $cursor->toDateString();
            $row = $rows->get($date);
            $series[] = [
                'date' => $date,
                'label' => $cursor->format('M j'),
                'short' => $cursor->format('j'),
                'total' => $row ? (float) $row->total : 0.0,
                'count' => $row ? (int) $row->cnt : 0,
                'is_today' => $date === $today,
            ];
            $cursor->addDay();
        }
        return $series;
    }
}
