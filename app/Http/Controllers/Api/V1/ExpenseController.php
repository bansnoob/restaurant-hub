<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ExpenseCategoryResource;
use App\Http\Resources\V1\ExpenseResource;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class ExpenseController extends Controller
{
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
            'status' => 'approved',
            'notes' => $validated['notes'] ?? null,
        ]);

        return (new ExpenseResource($expense->load('category')))
            ->response()
            ->setStatusCode(201);
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
