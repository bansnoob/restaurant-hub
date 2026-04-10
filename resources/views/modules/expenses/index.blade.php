<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Expenses</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('success'))
                <div class="bg-green-100 text-green-800 p-3 rounded">{{ session('success') }}</div>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold mb-4">Record Expense</h3>
                <form method="POST" action="{{ route('expenses.store') }}" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @csrf
                    <div>
                        <label class="text-sm text-gray-600">Branch</label>
                        <select name="branch_id" class="w-full border rounded px-3 py-2" required>
                            <option value="">Select branch</option>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">Expense Date</label>
                        <input type="date" name="expense_date" value="{{ now()->toDateString() }}" class="w-full border rounded px-3 py-2" required>
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">Category</label>
                        <select name="expense_category_id" class="w-full border rounded px-3 py-2">
                            <option value="">Uncategorized</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">New Category (optional)</label>
                        <input type="text" name="new_category_name" class="w-full border rounded px-3 py-2">
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">Description</label>
                        <input type="text" name="description" class="w-full border rounded px-3 py-2" required>
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">Amount</label>
                        <input type="number" step="0.01" min="0.01" name="amount" class="w-full border rounded px-3 py-2" required>
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">Payment Method</label>
                        <select name="payment_method" class="w-full border rounded px-3 py-2" required>
                            <option value="cash">Cash</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="card">Card</option>
                            <option value="e_wallet">E-Wallet</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">Reference #</label>
                        <input type="text" name="reference_no" class="w-full border rounded px-3 py-2">
                    </div>
                    <div class="md:col-span-2">
                        <button class="px-4 py-2 bg-gray-800 text-white rounded">Save Expense</button>
                    </div>
                </form>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="font-semibold">Expense List</h3>
                    <p class="text-sm text-gray-600">Total: PHP {{ number_format($totalExpenses, 2) }}</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left border-b">
                                <th class="py-2 pr-4">Date</th>
                                <th class="py-2 pr-4">Description</th>
                                <th class="py-2 pr-4">Amount</th>
                                <th class="py-2 pr-4">Method</th>
                                <th class="py-2 pr-4">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($expenses as $expense)
                                <tr class="border-b">
                                    <td class="py-2 pr-4">{{ $expense->expense_date }}</td>
                                    <td class="py-2 pr-4">{{ $expense->description }}</td>
                                    <td class="py-2 pr-4">PHP {{ number_format($expense->amount, 2) }}</td>
                                    <td class="py-2 pr-4">{{ ucfirst(str_replace('_', ' ', $expense->payment_method)) }}</td>
                                    <td class="py-2 pr-4">{{ ucfirst($expense->status) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="py-4 text-gray-500">No expenses recorded yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">{{ $expenses->links() }}</div>
            </div>
        </div>
    </div>
</x-app-layout>
