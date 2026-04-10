<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Payroll Report Details</h2>
            <a href="{{ route('payroll.index') }}" class="px-3 py-2 rounded bg-gray-200 text-gray-700 text-sm">Back to Payroll</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 text-sm">
                    <div>
                        <p class="text-gray-500">Branch</p>
                        <p class="font-semibold">{{ $period->branch?->name ?? 'N/A' }}</p>
                    </div>
                    <div>
                        <p class="text-gray-500">Report Label</p>
                        <p class="font-semibold">{{ $reportLabel ?? $period->cutoff_label }}</p>
                    </div>
                    <div>
                        <p class="text-gray-500">Date Range</p>
                        <p class="font-semibold">{{ $period->start_date }} to {{ $period->end_date }}</p>
                    </div>
                    <div>
                        <p class="text-gray-500">Report Status</p>
                        <p class="font-semibold">{{ ucfirst($selectedEntry->status ?? 'draft') }}</p>
                    </div>
                </div>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold mb-4">Applied Rule Snapshot</h3>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3 text-sm">
                    <p><span class="text-gray-500">Std Hours:</span> {{ number_format($rule->standard_daily_hours, 2) }}</p>
                    <p><span class="text-gray-500">Required Time-In:</span> {{ \Illuminate\Support\Carbon::parse($rule->required_clock_in_time)->format('h:i A') }}</p>
                    <p><span class="text-gray-500">1st Hit:</span> {{ \Illuminate\Support\Carbon::parse($rule->first_deduction_time)->format('h:i A') }} (PHP {{ number_format((float) $rule->first_deduction_amount, 2) }})</p>
                    <p><span class="text-gray-500">2nd Hit:</span> {{ \Illuminate\Support\Carbon::parse($rule->second_deduction_time)->format('h:i A') }} (PHP {{ number_format((float) $rule->second_deduction_amount, 2) }})</p>
                    <p><span class="text-gray-500">3rd Hit:</span> {{ \Illuminate\Support\Carbon::parse($rule->third_deduction_time)->format('h:i A') }} ({{ number_format((float) $rule->third_deduction_percent, 2) }}%)</p>
                </div>
            </div>

            @if ($selectedEntry && $selectedEmployee)
                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <h3 class="font-semibold mb-4">Employee Payroll Summary</h3>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 text-sm">
                        <p><span class="text-gray-500">Employee:</span> {{ $selectedEmployee->employee_code }} - {{ $selectedEmployee->first_name }} {{ $selectedEmployee->last_name }}</p>
                        <p><span class="text-gray-500">Daily Rate:</span> PHP {{ number_format((float) ($selectedEntry->daily_rate ?? 0), 2) }}</p>
                        <p><span class="text-gray-500">Gross:</span> PHP {{ number_format((float) $selectedEntry->gross_pay, 2) }}</p>
                        <p><span class="text-gray-500">Deductions:</span> PHP {{ number_format((float) $selectedEntry->deductions, 2) }}</p>
                        <p><span class="text-gray-500">Net:</span> PHP {{ number_format((float) $selectedEntry->net_pay, 2) }}</p>
                        <p><span class="text-gray-500">Present Days:</span> {{ (int) ($summary['present_days'] ?? 0) }}</p>
                        <p><span class="text-gray-500">Late Days:</span> {{ (int) ($summary['late_days'] ?? 0) }}</p>
                        <p><span class="text-gray-500">Late Hrs:</span> {{ number_format((float) ($summary['late_hours'] ?? 0), 2) }}</p>
                    </div>
                </div>

                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <h3 class="font-semibold mb-4">Daily Attendance Details</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="text-left border-b">
                                    <th class="py-2 pr-4">Date</th>
                                    <th class="py-2 pr-4">Clock In</th>
                                    <th class="py-2 pr-4">Clock Out</th>
                                    <th class="py-2 pr-4">Status</th>
                                    <th class="py-2 pr-4">Late (min)</th>
                                    <th class="py-2 pr-4">Tier</th>
                                    <th class="py-2 pr-4">Gross</th>
                                    <th class="py-2 pr-4">Deduction</th>
                                    <th class="py-2 pr-4">Net</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($dailyBreakdown as $day)
                                    <tr class="border-b">
                                        <td class="py-2 pr-4">{{ \Illuminate\Support\Carbon::parse($day['work_date'])->format('D, M j, Y') }}</td>
                                        <td class="py-2 pr-4">{{ $day['clock_in'] }}</td>
                                        <td class="py-2 pr-4">{{ $day['clock_out'] }}</td>
                                        <td class="py-2 pr-4">{{ ucfirst((string) $day['status']) }}</td>
                                        <td class="py-2 pr-4">{{ $day['late_minutes'] }}</td>
                                        <td class="py-2 pr-4">{{ $day['deduction_tier'] }}</td>
                                        <td class="py-2 pr-4">PHP {{ number_format((float) $day['gross'], 2) }}</td>
                                        <td class="py-2 pr-4">PHP {{ number_format((float) $day['deduction'], 2) }}</td>
                                        <td class="py-2 pr-4">PHP {{ number_format((float) $day['net'], 2) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="9" class="py-4 text-gray-500">No attendance rows found for this employee in this period.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @else
                <div class="bg-white shadow-sm sm:rounded-lg p-6 text-sm text-gray-500">
                    No payroll entries found for this period yet.
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
