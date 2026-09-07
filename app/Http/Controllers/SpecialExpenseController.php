<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\SpecialExpense;
use App\Models\SpecialExpenseCategory;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Monthly overhead — rent, electricity, permits.
 *
 * This module reads and writes `special_expenses` ONLY. It must never touch the
 * `expenses` table: that table feeds the day-closure drawer count, today's net
 * income and today's cash on hand, and overhead in any of those is a defect.
 *
 * Deliberately leaner than ExpenseController — no search, no payment breakdown,
 * no daily chart. Overhead is entered a handful of times a month and read as a
 * per-month total, so the page is a month picker plus a list.
 */
class SpecialExpenseController extends Controller
{
    private const PAYMENT_METHODS = SpecialExpense::PAYMENT_METHODS;

    /** How many months back the month picker offers. */
    private const MONTH_OPTIONS = 18;

    public function index(Request $request): View
    {
        $month = $this->resolveMonth($request);
        $monthStart = Carbon::parse($month)->startOfMonth();

        $branchFilter = $request->query('branch_id');
        $categoryFilter = $request->query('special_expense_category_id');

        $base = SpecialExpense::query()->forMonth($monthStart);

        if (! empty($branchFilter) && is_numeric($branchFilter)) {
            $base->where('branch_id', (int) $branchFilter);
        }

        $listQuery = clone $base;

        if (! empty($categoryFilter) && is_numeric($categoryFilter)) {
            $listQuery->where('special_expense_category_id', (int) $categoryFilter);
        }

        $specialExpenses = (clone $listQuery)
            ->with(['branch:id,name', 'category:id,name'])
            ->orderByDesc('period_month')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $total = (float) (clone $base)->sum('amount');
        $count = (clone $base)->count();

        // Previous month, same filters — so the owner can see electricity moving.
        $previousStart = $monthStart->copy()->subMonth();
        $previousQuery = SpecialExpense::query()->forMonth($previousStart);
        if (! empty($branchFilter) && is_numeric($branchFilter)) {
            $previousQuery->where('branch_id', (int) $branchFilter);
        }
        $previousTotal = (float) $previousQuery->sum('amount');

        $summary = [
            'total' => $total,
            'count' => $count,
            'previous_total' => $previousTotal,
            'change' => $total - $previousTotal,
        ];

        $categoryBreakdown = $this->categoryBreakdown(clone $base);
        $monthOptions = $this->monthOptions($monthStart->toDateString());

        // Include any branch/category a listed row still points at, even if it
        // has since been deactivated. Otherwise the edit drawer offers no option
        // matching the row's value, the browser selects the first option instead
        // ('All branches' / 'No category'), and saving an unrelated field
        // silently reassigns the cost.
        $usedBranchIds = SpecialExpense::query()->forMonth($monthStart)
            ->whereNotNull('branch_id')->distinct()->pluck('branch_id');
        $usedCategoryIds = SpecialExpense::query()->forMonth($monthStart)
            ->whereNotNull('special_expense_category_id')->distinct()->pluck('special_expense_category_id');

        $branches = Branch::where('is_active', true)
            ->orWhereIn('id', $usedBranchIds)
            ->orderBy('name')->get();
        $categories = SpecialExpenseCategory::where('is_active', true)
            ->orWhereIn('id', $usedCategoryIds)
            ->orderBy('name')->get();

        $filters = [
            'month' => $monthStart->toDateString(),
            'month_label' => $monthStart->format('F Y'),
            'branch_id' => $branchFilter,
            'special_expense_category_id' => $categoryFilter,
        ];

        return view('modules.expenses.special', compact(
            'specialExpenses',
            'categories',
            'branches',
            'summary',
            'categoryBreakdown',
            'monthOptions',
            'filters',
        ));
    }

    public function show(SpecialExpense $specialExpense): JsonResponse
    {
        $specialExpense->load(['branch:id,name', 'category:id,name', 'recordedBy:id,name']);

        return response()->json([
            'special_expense' => [
                'id' => $specialExpense->id,
                'branch_id' => $specialExpense->branch_id,
                'special_expense_category_id' => $specialExpense->special_expense_category_id,
                'period_month' => $specialExpense->period_month?->toDateString(),
                'period_month_label' => $specialExpense->period_month?->format('F Y'),
                'paid_date' => $specialExpense->paid_date?->toDateString(),
                'paid_date_label' => $specialExpense->paid_date?->format('M j, Y'),
                'description' => $specialExpense->description,
                'vendor_name' => $specialExpense->vendor_name,
                'reference_no' => $specialExpense->reference_no,
                'amount' => (float) $specialExpense->amount,
                'payment_method' => $specialExpense->payment_method,
                'notes' => $specialExpense->notes,
                'branch_name' => $specialExpense->branch?->name,
                'category_name' => $specialExpense->category?->name,
                'recorded_by' => $specialExpense->recordedBy?->name,
                'created_at' => $specialExpense->created_at?->toIso8601String(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePayload($request);
        $categoryId = $this->resolveCategoryId($validated);

        SpecialExpense::create([
            'branch_id' => $validated['branch_id'] ?? null,
            'special_expense_category_id' => $categoryId,
            'recorded_by_user_id' => $request->user()->id,
            'period_month' => Carbon::parse($validated['period_month'])->startOfMonth()->toDateString(),
            'paid_date' => $validated['paid_date'] ?? null,
            'description' => $validated['description'] ?? null,
            'vendor_name' => $validated['vendor_name'] ?? null,
            'reference_no' => $validated['reference_no'] ?? null,
            'amount' => $validated['amount'],
            'payment_method' => $validated['payment_method'],
            'notes' => $validated['notes'] ?? null,
        ]);

        return back()->with('success', 'Special expense recorded successfully.');
    }

    public function update(Request $request, SpecialExpense $specialExpense): RedirectResponse
    {
        $validated = $this->validatePayload($request);
        $categoryId = $this->resolveCategoryId($validated);

        $specialExpense->update([
            'branch_id' => $validated['branch_id'] ?? null,
            'special_expense_category_id' => $categoryId,
            'period_month' => Carbon::parse($validated['period_month'])->startOfMonth()->toDateString(),
            'paid_date' => $validated['paid_date'] ?? null,
            'description' => $validated['description'] ?? null,
            'vendor_name' => $validated['vendor_name'] ?? null,
            'reference_no' => $validated['reference_no'] ?? null,
            'amount' => $validated['amount'],
            'payment_method' => $validated['payment_method'],
            'notes' => $validated['notes'] ?? null,
        ]);

        return back()->with('success', 'Special expense updated successfully.');
    }

    public function destroy(SpecialExpense $specialExpense): RedirectResponse
    {
        try {
            $specialExpense->delete();
        } catch (QueryException) {
            return back()->with('error', 'Unable to delete special expense.');
        }

        return back()->with('success', 'Special expense deleted successfully.');
    }

    /**
     * Both store and update write every optional field as `?? null`, so the two
     * rule sets must stay identical — a field validated in one and not the other
     * is silently wiped on save. Sharing the array is what keeps them in step.
     *
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            // Nullable: null means the cost covers the whole business.
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'special_expense_category_id' => ['nullable', 'integer', 'exists:special_expense_categories,id'],
            'period_month' => ['required', 'date'],
            'paid_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:200'],
            'vendor_name' => ['nullable', 'string', 'max:140'],
            'reference_no' => ['nullable', 'string', 'max:60'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required', Rule::in(self::PAYMENT_METHODS)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'new_category_name' => ['nullable', 'string', 'max:100'],
        ]);
    }

    /**
     * A typed-in category name wins over the picker, mirroring ExpenseController.
     * The form disables the free-text input whenever the picker has a value, so
     * the two cannot arrive together from the UI.
     *
     * @param  array<string, mixed>  $validated
     */
    private function resolveCategoryId(array $validated): ?int
    {
        $typed = trim((string) ($validated['new_category_name'] ?? ''));

        if ($typed !== '') {
            // Str::slug('###') is the empty string, and firstOrCreate keyed on ''
            // would file every such name under one shared row — three unrelated
            // bills reported under whichever name happened to be saved first.
            $slug = Str::slug($typed);
            if ($slug === '') {
                $slug = 'cat-'.substr(md5($typed), 0, 12);
            }

            $category = SpecialExpenseCategory::firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => $typed,
                    'is_active' => true,
                ]
            );

            return (int) $category->id;
        }

        return isset($validated['special_expense_category_id'])
            ? (int) $validated['special_expense_category_id']
            : null;
    }

    private function resolveMonth(Request $request): string
    {
        // Type-guard BEFORE casting: `?month[]=x` hands us an array, and
        // (string) on an array throws outside the try/catch below — a mistyped
        // link or a crawler would take the owner to a 500 instead of this month.
        $input = $request->query('month');
        $raw = is_string($input) ? trim($input) : '';

        if ($raw === '') {
            return now()->startOfMonth()->toDateString();
        }

        try {
            // Accepts both "2026-09" from a month input and a full date.
            return Carbon::parse(strlen($raw) === 7 ? $raw.'-01' : $raw)
                ->startOfMonth()
                ->toDateString();
        } catch (\Throwable) {
            return now()->startOfMonth()->toDateString();
        }
    }

    /**
     * The month picker's options.
     *
     * Every month that holds data must appear, and so must the month currently
     * selected. A <select> whose bound value matches no option does not stay
     * unset — the browser falls back to the FIRST option, so a short window
     * would show one month's rows above a picker naming a different month, and
     * the edit drawer would silently re-file an old row into the current month.
     * The same trap is documented on the daily expenses form.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function monthOptions(string $selected): array
    {
        $newest = now()->startOfMonth();
        $oldest = $newest->copy()->subMonths(self::MONTH_OPTIONS - 1);

        $earliestData = SpecialExpense::min('period_month');
        if ($earliestData) {
            $earliest = Carbon::parse($earliestData)->startOfMonth();
            if ($earliest->lt($oldest)) {
                $oldest = $earliest;
            }
        }

        $selectedStart = Carbon::parse($selected)->startOfMonth();
        if ($selectedStart->lt($oldest)) {
            $oldest = $selectedStart;
        }
        if ($selectedStart->gt($newest)) {
            $newest = $selectedStart;
        }

        $options = [];
        $cursor = $newest->copy();
        while ($cursor->gte($oldest)) {
            $options[] = [
                'value' => $cursor->toDateString(),
                'label' => $cursor->format('F Y'),
            ];
            $cursor = $cursor->copy()->subMonth();
        }

        return $options;
    }

    /**
     * @return array<int, array{name: string, count: int, total: float}>
     */
    private function categoryBreakdown($query): array
    {
        $rows = $query
            ->leftJoin(
                'special_expense_categories',
                'special_expenses.special_expense_category_id',
                '=',
                'special_expense_categories.id'
            )
            ->selectRaw("COALESCE(special_expense_categories.name, 'Uncategorized') as cat_name, COUNT(*) as cnt, SUM(special_expenses.amount) as total")
            ->groupBy('cat_name')
            ->orderByDesc('total')
            ->get();

        return $rows->map(fn ($row) => [
            'name' => (string) $row->cat_name,
            'count' => (int) $row->cnt,
            'total' => (float) $row->total,
        ])->all();
    }
}
