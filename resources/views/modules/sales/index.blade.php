<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Sales</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                    <div>
                        <label class="text-sm text-gray-600">Date From</label>
                        <input type="date" name="date_from" value="{{ $dateFrom }}" class="w-full border rounded px-3 py-2">
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">Date To</label>
                        <input type="date" name="date_to" value="{{ $dateTo }}" class="w-full border rounded px-3 py-2">
                    </div>
                    <div class="md:col-span-2">
                        <button class="px-4 py-2 bg-gray-800 text-white rounded">Apply Filter</button>
                    </div>
                </form>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="bg-white shadow-sm sm:rounded-lg p-4">
                    <p class="text-sm text-gray-500">Orders</p>
                    <p class="text-2xl font-semibold">{{ $summary['orders'] }}</p>
                </div>
                <div class="bg-white shadow-sm sm:rounded-lg p-4">
                    <p class="text-sm text-gray-500">Gross Sales</p>
                    <p class="text-2xl font-semibold">₱{{ number_format($summary['gross_sales'], 2) }}</p>
                </div>
                <div class="bg-white shadow-sm sm:rounded-lg p-4">
                    <p class="text-sm text-gray-500">Discounts</p>
                    <p class="text-2xl font-semibold">₱{{ number_format($summary['discounts'], 2) }}</p>
                </div>
                <div class="bg-white shadow-sm sm:rounded-lg p-4">
                    <p class="text-sm text-gray-500">Taxes</p>
                    <p class="text-2xl font-semibold">₱{{ number_format($summary['taxes'], 2) }}</p>
                </div>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left border-b">
                                <th class="py-2 pr-4">Order #</th>
                                <th class="py-2 pr-4">Date/Time</th>
                                <th class="py-2 pr-4">Type</th>
                                <th class="py-2 pr-4">Status</th>
                                <th class="py-2 pr-4">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($sales as $sale)
                                <tr class="border-b">
                                    <td class="py-2 pr-4">{{ $sale->order_number }}</td>
                                    <td class="py-2 pr-4">{{ \Illuminate\Support\Carbon::parse($sale->sale_datetime)->format('Y-m-d h:i A') }}</td>
                                    <td class="py-2 pr-4">{{ ucfirst(str_replace('_', ' ', $sale->order_type)) }}</td>
                                    <td class="py-2 pr-4">{{ ucfirst($sale->status) }}</td>
                                    <td class="py-2 pr-4">₱{{ number_format($sale->grand_total, 2) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="py-4 text-gray-500">No sales found in this period.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">{{ $sales->links() }}</div>
            </div>
        </div>
    </div>
</x-app-layout>
