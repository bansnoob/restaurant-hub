<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ExpenseController extends Controller
{
    public function index(Request $request): View
    {
        $dateFrom = $request->string('date_from')->toString() ?: now()->startOfMonth()->toDateString();
        $dateTo = $request->string('date_to')->toString() ?: now()->toDateString();

        $categories = ExpenseCategory::where('is_active', true)->orderBy('name')->get();
        $branches = Branch::where('is_active', true)->orderBy('name')->get();

        $expensesQuery = Expense::whereDate('expense_date', '>=', $dateFrom)
            ->whereDate('expense_date', '<=', $dateTo)
            ->orderByDesc('expense_date')
            ->orderByDesc('id');

        $expenses = (clone $expensesQuery)->paginate(20)->withQueryString();
        $totalExpenses = (float) (clone $expensesQuery)->sum('amount');

        return view('modules.expenses.index', compact(
            'expenses',
            'categories',
            'branches',
            'dateFrom',
            'dateTo',
            'totalExpenses'
        ));
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
            'payment_method' => ['required', 'in:cash,bank_transfer,gcash,other'],
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
}
