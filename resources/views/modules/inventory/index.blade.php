<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Inventory</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('success'))
                <div class="bg-green-100 text-green-800 p-3 rounded">{{ session('success') }}</div>
            @endif
            @if ($errors->any())
                <div class="bg-red-100 text-red-800 p-3 rounded">
                    <ul class="list-disc list-inside text-sm">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
            @endif

            {{-- Branch filter --}}
            <form method="GET" action="{{ route('inventory.index') }}" class="flex items-center gap-3">
                <select name="branch_id" onchange="this.form.submit()" class="border rounded px-3 py-2 text-sm">
                    <option value="">All Branches</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}" {{ $branchId == $branch->id ? 'selected' : '' }}>
                            {{ $branch->name }}
                        </option>
                    @endforeach
                </select>
            </form>

            {{-- Low stock alerts --}}
            @if ($lowStock->isNotEmpty())
                <div class="bg-amber-50 border border-amber-200 rounded-lg p-4">
                    <p class="text-sm font-semibold text-amber-800 mb-2">Low Stock ({{ $lowStock->count() }} item{{ $lowStock->count() !== 1 ? 's' : '' }})</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($lowStock as $item)
                            <span class="inline-flex items-center gap-1 bg-amber-100 text-amber-800 text-xs px-2 py-1 rounded-full">
                                {{ $item->name }}
                                <span class="font-semibold">{{ rtrim(rtrim(number_format((float)$item->current_stock, 3), '0'), '.') }} {{ $item->unit }}</span>
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Add Ingredient --}}
            <div x-data="{ open: false }" class="bg-white shadow-sm sm:rounded-lg p-6">
                <div class="flex justify-between items-center">
                    <h3 class="font-semibold">Ingredients</h3>
                    <button @click="open = !open" class="px-3 py-1.5 bg-gray-800 text-white text-sm rounded">
                        + Add Ingredient
                    </button>
                </div>

                <div x-show="open" x-transition class="mt-4 border-t pt-4" style="display:none">
                    <form method="POST" action="{{ route('inventory.store') }}" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        @csrf
                        <div>
                            <label class="text-sm text-gray-600">Branch</label>
                            <select name="branch_id" class="w-full border rounded px-3 py-2" required>
                                <option value="">Select branch</option>
                                @foreach ($branches as $branch)
                                    <option value="{{ $branch->id }}" {{ $branchId == $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="text-sm text-gray-600">Name</label>
                            <input type="text" name="name" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <div>
                            <label class="text-sm text-gray-600">SKU (optional)</label>
                            <input type="text" name="sku" class="w-full border rounded px-3 py-2">
                        </div>
                        <div>
                            <label class="text-sm text-gray-600">Unit</label>
                            <select name="unit" class="w-full border rounded px-3 py-2" required>
                                <option value="pcs">pcs</option>
                                <option value="g">g</option>
                                <option value="kg">kg</option>
                                <option value="ml">ml</option>
                                <option value="l">l</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-sm text-gray-600">Current Stock</label>
                            <input type="number" step="0.001" min="0" name="current_stock" value="0" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <div>
                            <label class="text-sm text-gray-600">Reorder Level</label>
                            <input type="number" step="0.001" min="0" name="reorder_level" value="0" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <div>
                            <label class="text-sm text-gray-600">Cost per Unit (PHP)</label>
                            <input type="number" step="0.0001" min="0" name="cost_per_unit" value="0" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <div class="md:col-span-3">
                            <button class="px-4 py-2 bg-gray-800 text-white rounded">Save Ingredient</button>
                        </div>
                    </form>
                </div>

                {{-- Ingredients Table --}}
                <div class="overflow-x-auto mt-4">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left border-b text-gray-600">
                                <th class="py-2 pr-4">Name</th>
                                <th class="py-2 pr-4">Branch</th>
                                <th class="py-2 pr-4">SKU</th>
                                <th class="py-2 pr-4">Current Stock</th>
                                <th class="py-2 pr-4">Reorder Level</th>
                                <th class="py-2 pr-4">Cost / Unit</th>
                                <th class="py-2 pr-4">Status</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($ingredients as $ingredient)
                                <tr class="border-b {{ $ingredient->isLowStock() ? 'bg-amber-50' : '' }}" x-data="{ editing: false }">
                                    <td class="py-2 pr-4 font-medium">{{ $ingredient->name }}</td>
                                    <td class="py-2 pr-4 text-gray-500">{{ $ingredient->branch->name ?? '—' }}</td>
                                    <td class="py-2 pr-4 text-gray-500">{{ $ingredient->sku ?? '—' }}</td>
                                    <td class="py-2 pr-4 {{ $ingredient->isLowStock() ? 'text-amber-700 font-semibold' : '' }}">
                                        {{ rtrim(rtrim(number_format((float)$ingredient->current_stock, 3), '0'), '.') }} {{ $ingredient->unit }}
                                    </td>
                                    <td class="py-2 pr-4">
                                        {{ rtrim(rtrim(number_format((float)$ingredient->reorder_level, 3), '0'), '.') }} {{ $ingredient->unit }}
                                    </td>
                                    <td class="py-2 pr-4">₱{{ number_format((float)$ingredient->cost_per_unit, 4) }}</td>
                                    <td class="py-2 pr-4">
                                        <span class="text-xs px-2 py-0.5 rounded-full {{ $ingredient->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                                            {{ $ingredient->is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </td>
                                    <td class="py-2 text-right">
                                        <button @click="editing = !editing" class="text-xs text-blue-600 hover:underline mr-2">Edit</button>
                                        <form method="POST" action="{{ route('inventory.destroy', $ingredient) }}" class="inline"
                                              onsubmit="return confirm('Delete {{ addslashes($ingredient->name) }}?')">
                                            @csrf @method('DELETE')
                                            <button class="text-xs text-red-500 hover:underline">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                                <tr x-show="editing" style="display:none" class="bg-gray-50">
                                    <td colspan="8" class="px-4 py-3">
                                        <form method="POST" action="{{ route('inventory.update', $ingredient) }}" class="grid grid-cols-2 md:grid-cols-4 gap-3">
                                            @csrf @method('PUT')
                                            <div>
                                                <label class="text-xs text-gray-500">Name</label>
                                                <input type="text" name="name" value="{{ $ingredient->name }}" class="w-full border rounded px-2 py-1 text-sm" required>
                                            </div>
                                            <div>
                                                <label class="text-xs text-gray-500">SKU</label>
                                                <input type="text" name="sku" value="{{ $ingredient->sku }}" class="w-full border rounded px-2 py-1 text-sm">
                                            </div>
                                            <div>
                                                <label class="text-xs text-gray-500">Unit</label>
                                                <select name="unit" class="w-full border rounded px-2 py-1 text-sm" required>
                                                    @foreach (['pcs','g','kg','ml','l'] as $u)
                                                        <option value="{{ $u }}" {{ $ingredient->unit === $u ? 'selected' : '' }}>{{ $u }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div>
                                                <label class="text-xs text-gray-500">Reorder Level</label>
                                                <input type="number" step="0.001" min="0" name="reorder_level" value="{{ $ingredient->reorder_level }}" class="w-full border rounded px-2 py-1 text-sm" required>
                                            </div>
                                            <div>
                                                <label class="text-xs text-gray-500">Cost / Unit</label>
                                                <input type="number" step="0.0001" min="0" name="cost_per_unit" value="{{ $ingredient->cost_per_unit }}" class="w-full border rounded px-2 py-1 text-sm" required>
                                            </div>
                                            <div class="flex items-end gap-2">
                                                <label class="flex items-center gap-1 text-sm cursor-pointer">
                                                    <input type="hidden" name="is_active" value="0">
                                                    <input type="checkbox" name="is_active" value="1" {{ $ingredient->is_active ? 'checked' : '' }}>
                                                    Active
                                                </label>
                                            </div>
                                            <div class="flex items-end gap-2">
                                                <button class="px-3 py-1 bg-gray-800 text-white text-sm rounded">Save</button>
                                                <button type="button" @click="editing = false" class="px-3 py-1 text-sm text-gray-500 hover:underline">Cancel</button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="py-4 text-gray-400">No ingredients yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Stock Adjustment --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6" x-data="{ movType: 'purchase' }">
                <h3 class="font-semibold mb-4">Record Stock Movement</h3>
                <form method="POST" action="{{ route('inventory.adjust') }}" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    @csrf
                    <div>
                        <label class="text-sm text-gray-600">Ingredient</label>
                        <select name="ingredient_id" class="w-full border rounded px-3 py-2" required>
                            <option value="">Select ingredient</option>
                            @foreach ($ingredients as $ingredient)
                                <option value="{{ $ingredient->id }}">{{ $ingredient->name }} ({{ $ingredient->branch->name ?? '' }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">Type</label>
                        <select name="movement_type" x-model="movType" class="w-full border rounded px-3 py-2" required>
                            <option value="purchase">Purchase (stock in)</option>
                            <option value="return">Return (stock in)</option>
                            <option value="waste">Waste (stock out)</option>
                            <option value="adjustment">Adjustment</option>
                        </select>
                    </div>
                    <div x-show="movType === 'adjustment'">
                        <label class="text-sm text-gray-600">Direction</label>
                        <select name="direction" class="w-full border rounded px-3 py-2">
                            <option value="in">In (add)</option>
                            <option value="out">Out (deduct)</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">Quantity</label>
                        <input type="number" step="0.001" min="0.001" name="quantity" class="w-full border rounded px-3 py-2" required>
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">Unit Cost (optional)</label>
                        <input type="number" step="0.0001" min="0" name="unit_cost" class="w-full border rounded px-3 py-2">
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">Notes</label>
                        <input type="text" name="notes" class="w-full border rounded px-3 py-2">
                    </div>
                    <div class="md:col-span-3">
                        <button class="px-4 py-2 bg-gray-800 text-white rounded">Record Movement</button>
                    </div>
                </form>
            </div>

            {{-- Movement History --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold mb-4">Movement History</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left border-b text-gray-600">
                                <th class="py-2 pr-4">Date</th>
                                <th class="py-2 pr-4">Ingredient</th>
                                <th class="py-2 pr-4">Type</th>
                                <th class="py-2 pr-4">Direction</th>
                                <th class="py-2 pr-4">Quantity</th>
                                <th class="py-2 pr-4">Unit Cost</th>
                                <th class="py-2 pr-4">By</th>
                                <th class="py-2 pr-4">Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($movements as $movement)
                                <tr class="border-b">
                                    <td class="py-2 pr-4 text-gray-500">{{ $movement->moved_at->format('M d, Y H:i') }}</td>
                                    <td class="py-2 pr-4 font-medium">{{ $movement->ingredient->name ?? '—' }}</td>
                                    <td class="py-2 pr-4">{{ ucfirst(str_replace('_', ' ', $movement->movement_type)) }}</td>
                                    <td class="py-2 pr-4">
                                        <span class="text-xs px-2 py-0.5 rounded-full {{ $movement->direction === 'in' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-600' }}">
                                            {{ strtoupper($movement->direction) }}
                                        </span>
                                    </td>
                                    <td class="py-2 pr-4">{{ rtrim(rtrim(number_format((float)$movement->quantity, 3), '0'), '.') }}</td>
                                    <td class="py-2 pr-4">{{ $movement->unit_cost !== null ? '₱' . number_format((float)$movement->unit_cost, 4) : '—' }}</td>
                                    <td class="py-2 pr-4 text-gray-500">{{ $movement->createdBy->name ?? '—' }}</td>
                                    <td class="py-2 pr-4 text-gray-500">{{ $movement->notes ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="py-4 text-gray-400">No movements recorded yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">{{ $movements->links() }}</div>
            </div>

        </div>
    </div>
</x-app-layout>
