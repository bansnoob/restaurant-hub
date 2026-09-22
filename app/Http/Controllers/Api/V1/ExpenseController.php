<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ExpenseCategoryResource;
use App\Http\Resources\V1\ExpenseResource;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\DayClosureRecalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    public function __construct(
        private readonly DayClosureRecalculator $recalculator,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $branchId = $this->resolveBranchId($request);

        $date = $request->string('date')->toString() ?: now()->toDateString();

        $expenses = Expense::where('branch_id', $branchId)
            ->whereDate('expense_date', $date)
            ->with('category')
            ->orderByDesc('id')
            ->get();

        return ExpenseResource::collection($expenses);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['sometimes', 'integer', 'exists:branches,id'],
            'expense_category_id' => ['nullable', 'integer', 'exists:expense_categories,id'],
            'expense_date' => ['required', 'date'],
            'reference_no' => ['nullable', 'string', 'max:60'],
            'vendor_name' => ['nullable', 'string', 'max:140'],
            'description' => ['required', 'string', 'max:200'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required', 'in:cash,bank_transfer,gcash,other'],
            'paid_from' => ['nullable', Rule::in(Expense::PAID_FROM)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'new_category_name' => ['nullable', 'string', 'max:100'],
        ]);

        $branchId = $validated['branch_id'] ?? $this->resolveBranchId($request);

        if (! empty($validated['new_category_name'])) {
            $category = ExpenseCategory::firstOrCreate(
                [
                    'branch_id' => $branchId,
                    'slug' => Str::slug($validated['new_category_name']),
                ],
                [
                    'name' => $validated['new_category_name'],
                    'is_active' => true,
                ]
            );
            $validated['expense_category_id'] = $category->id;
        }

        $expense = Expense::create([
            'branch_id' => $branchId,
            'expense_category_id' => $validated['expense_category_id'] ?? null,
            'recorded_by_user_id' => $request->user()->id,
            'expense_date' => $validated['expense_date'],
            'reference_no' => $validated['reference_no'] ?? null,
            'vendor_name' => $validated['vendor_name'] ?? null,
            'description' => $validated['description'],
            'amount' => $validated['amount'],
            'payment_method' => $validated['payment_method'],
            'paid_from' => $this->resolvePaidFrom(
                $validated['payment_method'],
                $validated['paid_from'] ?? null
            ),
            'status' => 'approved',
            'notes' => $validated['notes'] ?? null,
        ]);

        // A closed day's figures are derived from these rows, so they have to follow.
        // No-op when the day is not closed.
        $this->recalculator->recalculateFor((int) $expense->branch_id, $expense->expense_date);

        return (new ExpenseResource($expense->load('category')))
            ->additional(['meta' => [
                'day_closed' => $this->dayIsClosed((int) $expense->branch_id, $expense->expense_date),
            ]])
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Expense $expense): JsonResponse
    {
        $this->authorizeBranch($request, $expense);

        abort_if($expense->status === 'voided', 422, 'Voided expenses cannot be edited.');

        // Partial update: only the keys actually present in the request are
        // changed, so a client editing a subset of fields (e.g. just the
        // description/amount/payment method) does not wipe the others.
        $validated = $request->validate([
            'expense_category_id' => ['sometimes', 'nullable', 'integer', 'exists:expense_categories,id'],
            'expense_date' => ['sometimes', 'required', 'date'],
            'reference_no' => ['sometimes', 'nullable', 'string', 'max:60'],
            'vendor_name' => ['sometimes', 'nullable', 'string', 'max:140'],
            'description' => ['sometimes', 'required', 'string', 'max:200'],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'payment_method' => ['sometimes', 'required', 'in:cash,bank_transfer,gcash,other'],
            'paid_from' => ['sometimes', 'nullable', Rule::in(Expense::PAID_FROM)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $originalBranchId = (int) $expense->branch_id;
        $originalDate = $expense->expense_date;

        // Resolved against the merged state rather than the request alone, so a client
        // that sends only one half of the pair still lands on a coherent one.
        if (array_key_exists('paid_from', $validated) || array_key_exists('payment_method', $validated)) {
            $validated['paid_from'] = $this->resolvePaidFrom(
                $validated['payment_method'] ?? $expense->payment_method,
                $validated['paid_from'] ?? null,
                $expense->paid_from
            );
        }

        $expense->update($validated);

        // Both ends: an expense moved off a day leaves that day overstated, and the day
        // it landed on understated. Recomputing only the destination fixes half of it.
        $this->recalculator->recalculateForMove(
            $originalBranchId,
            $originalDate,
            (int) $expense->branch_id,
            $expense->expense_date
        );

        return (new ExpenseResource($expense->load('category')))
            ->additional(['meta' => [
                'day_closed' => $this->dayIsClosed((int) $expense->branch_id, $expense->expense_date),
            ]])
            ->response()
            ->setStatusCode(200);
    }

    public function destroy(Request $request, Expense $expense): JsonResponse
    {
        $this->authorizeBranch($request, $expense);

        abort_if($expense->status === 'voided', 422, 'Voided expenses cannot be deleted.');

        $branchId = (int) $expense->branch_id;
        $date = $expense->expense_date;

        $expense->delete();

        $this->recalculator->recalculateFor($branchId, $date);

        return response()->json([
            'message' => 'Expense deleted.',
            'meta' => ['day_closed' => $this->dayIsClosed($branchId, $date)],
        ]);
    }

    public function categories(Request $request): AnonymousResourceCollection
    {
        $branchId = $this->resolveBranchId($request);

        $categories = ExpenseCategory::where(function ($q) use ($branchId) {
            $q->where('branch_id', $branchId)->orWhereNull('branch_id');
        })
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return ExpenseCategoryResource::collection($categories);
    }

    /**
     * Non-cash has no drawer to come from, so it is never 'outside'.
     */
    private function resolvePaidFrom(string $paymentMethod, ?string $requested, ?string $current = null): string
    {
        if ($paymentMethod !== 'cash') {
            return 'drawer';
        }

        return $requested ?? $current ?? 'drawer';
    }

    /**
     * Whether this branch/date is already closed. The write still goes through — a
     * forgotten expense is a legitimate correction and the closure is recomputed to
     * match — but the caller is told, because it changes a day someone already signed off.
     */
    private function dayIsClosed(int $branchId, string|\DateTimeInterface|null $date): bool
    {
        return $this->recalculator->closureFor($branchId, $date) !== null;
    }

    /**
     * Ensure the caller may mutate this expense. Owners may touch any branch;
     * everyone else is restricted to their own branch's expenses.
     */
    private function authorizeBranch(Request $request, Expense $expense): void
    {
        $user = $request->user();

        if ($user->hasRole('owner')) {
            return;
        }

        $branchId = $user->resolveBranchId();
        abort_unless($branchId, 403, 'User is not linked to any branch.');
        abort_unless(
            $expense->branch_id === $branchId,
            403,
            'You cannot modify an expense from another branch.'
        );
    }

    private function resolveBranchId(Request $request): int
    {
        $user = $request->user();

        if ($user->hasRole('owner') && $request->filled('branch_id')) {
            return $request->integer('branch_id');
        }

        $branchId = $user->resolveBranchId();
        abort_unless($branchId, 403, 'User is not linked to any branch.');

        return $branchId;
    }
}
