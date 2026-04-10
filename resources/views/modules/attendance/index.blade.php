<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Attendance</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('success'))
                <div class="bg-green-100 text-green-800 p-3 rounded">{{ session('success') }}</div>
            @endif
            @if (session('error'))
                <div class="bg-red-100 text-red-800 p-3 rounded">{{ session('error') }}</div>
            @endif
            @if ($errors->any())
                <div class="bg-red-100 text-red-800 p-3 rounded">
                    {{ $errors->first() }}
                </div>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
                    <div>
                        <label class="text-sm text-gray-600">Work Date</label>
                        <input type="date" name="work_date" value="{{ $workDate }}" class="w-full border rounded px-3 py-2" onchange="this.form.submit()">
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">Summary From</label>
                        <input type="date" name="date_from" value="{{ $dateFrom }}" class="w-full border rounded px-3 py-2" onchange="this.form.submit()">
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">Summary To</label>
                        <input type="date" name="date_to" value="{{ $dateTo }}" class="w-full border rounded px-3 py-2" onchange="this.form.submit()">
                    </div>
                </form>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold mb-4">Attendance Summary ({{ $dateFrom }} to {{ $dateTo }})</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left border-b">
                                <th class="py-2 pr-4">Employee</th>
                                <th class="py-2 pr-4">Days Logged</th>
                                <th class="py-2 pr-4">Late Days</th>
                                @if (auth()->user()?->hasRole('owner'))
                                    <th class="py-2 pr-4">Worked Hrs</th>
                                    <th class="py-2 pr-4">Regular Hrs</th>
                                    <th class="py-2 pr-4">Estimated Net</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($summaries as $summary)
                                <tr class="border-b">
                                    <td class="py-2 pr-4">
                                        {{ $summary['employee_name'] }}
                                        <span class="text-xs text-gray-500">({{ $summary['employee_code'] }})</span>
                                    </td>
                                    <td class="py-2 pr-4">{{ $summary['days_with_logs'] }}</td>
                                    <td class="py-2 pr-4">{{ $summary['late_days'] }}</td>
                                    @if (auth()->user()?->hasRole('owner'))
                                        <td class="py-2 pr-4">{{ number_format($summary['worked_hours'], 2) }}</td>
                                        <td class="py-2 pr-4">{{ number_format($summary['regular_hours'], 2) }}</td>
                                        <td class="py-2 pr-4">PHP {{ number_format($summary['estimated_net_pay'], 2) }}</td>
                                    @endif
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ auth()->user()?->hasRole('owner') ? 6 : 3 }}" class="py-4 text-gray-500">No employees available.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <h3 class="font-semibold mb-4">Clock In</h3>
                    <form method="POST" action="{{ route('attendance.clock-in') }}" class="space-y-3">
                        @csrf
                        <input type="hidden" name="work_date" value="{{ $workDate }}">
                        <select name="employee_id" class="w-full border rounded px-3 py-2" required>
                            <option value="">Select employee</option>
                            @foreach ($employees as $employee)
                                <option value="{{ $employee->id }}">
                                    {{ $employee->employee_code }} - {{ $employee->first_name }} {{ $employee->last_name }}
                                </option>
                            @endforeach
                        </select>
                        <input type="text" name="notes" placeholder="Notes (optional)" class="w-full border rounded px-3 py-2">
                        <button class="px-4 py-2 bg-gray-800 text-white rounded border border-gray-900 font-semibold">Time In</button>
                    </form>
                </div>

                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <h3 class="font-semibold mb-4">Open Entries (Time Out)</h3>
                    <div class="space-y-3">
                        @forelse ($records->whereNull('clock_out_at') as $record)
                            <form method="POST" action="{{ route('attendance.clock-out') }}" class="flex items-center justify-between border rounded p-3">
                                @csrf
                                <input type="hidden" name="record_id" value="{{ $record->id }}">
                                <div class="text-sm">
                                    @php($employee = $employees->firstWhere('id', $record->employee_id))
                                    <p class="font-medium">
                                        {{ $employee ? $employee->first_name.' '.$employee->last_name : 'Employee #'.$record->employee_id }}
                                    </p>
                                    <p class="text-gray-600">In: {{ \Illuminate\Support\Carbon::parse($record->clock_in_at)->format('h:i A') }}</p>
                                </div>
                                <button class="px-3 py-2 bg-red-700 text-white rounded border border-red-800 font-semibold">Time Out</button>
                            </form>
                        @empty
                            <p class="text-sm text-gray-500">No open attendance entries for this date.</p>
                        @endforelse
                    </div>
                </div>
            </div>

            @if (auth()->user()?->hasRole('owner'))
                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <h3 class="font-semibold mb-4">Owner Manual Clock Entry</h3>
                    <form method="POST" action="{{ route('attendance.manual-entry') }}" class="grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
                        @csrf
                        <div>
                            <label class="text-sm text-gray-600">Employee</label>
                            <select name="employee_id" class="w-full border rounded px-3 py-2" required>
                                <option value="">Select employee</option>
                                @foreach ($employees as $employee)
                                    <option value="{{ $employee->id }}">
                                        {{ $employee->first_name }} {{ $employee->last_name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="text-sm text-gray-600">Work Date</label>
                            <input type="date" name="work_date" value="{{ $workDate }}" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <div>
                            <label class="text-sm text-gray-600">Clock In</label>
                            <input type="time" name="clock_in_time" class="w-full border rounded px-3 py-2" required>
                        </div>
                        <div>
                            <label class="text-sm text-gray-600">Clock Out</label>
                            <input type="time" name="clock_out_time" class="w-full border rounded px-3 py-2">
                        </div>
                        <div>
                            <button class="px-4 py-2 bg-indigo-700 text-white rounded">Save Manual Entry</button>
                        </div>
                    </form>
                </div>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold mb-4">Daily Records</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left border-b">
                                <th class="py-2 pr-4">Employee</th>
                                <th class="py-2 pr-4">Clock In</th>
                                <th class="py-2 pr-4">Clock Out</th>
                                <th class="py-2 pr-4">Status</th>
                                @if (auth()->user()?->hasRole('owner'))
                                    <th class="py-2 pr-4">Edit Times</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($records as $record)
                                <tr class="border-b">
                                    <td class="py-2 pr-4">
                                        @php($employee = $employees->firstWhere('id', $record->employee_id))
                                        {{ $employee ? $employee->employee_code.' - '.$employee->first_name.' '.$employee->last_name : 'Employee #'.$record->employee_id }}
                                    </td>
                                    <td class="py-2 pr-4">{{ $record->clock_in_at ? \Illuminate\Support\Carbon::parse($record->clock_in_at)->format('h:i A') : '-' }}</td>
                                    <td class="py-2 pr-4">{{ $record->clock_out_at ? \Illuminate\Support\Carbon::parse($record->clock_out_at)->format('h:i A') : '-' }}</td>
                                    <td class="py-2 pr-4">{{ ucfirst($record->status) }}</td>
                                    @if (auth()->user()?->hasRole('owner'))
                                        <td class="py-2 pr-4 whitespace-nowrap">
                                            <div class="flex flex-nowrap items-center gap-2">
                                                <form method="POST" action="{{ route('attendance.update-times') }}" class="flex flex-nowrap items-center gap-2">
                                                    @csrf
                                                    <input type="hidden" name="record_id" value="{{ $record->id }}">
                                                    <input
                                                        type="time"
                                                        name="clock_in_time"
                                                        value="{{ $record->clock_in_at ? \Illuminate\Support\Carbon::parse($record->clock_in_at)->format('H:i') : '' }}"
                                                        class="border rounded px-2 py-1 w-24"
                                                    >
                                                    <input
                                                        type="time"
                                                        name="clock_out_time"
                                                        value="{{ $record->clock_out_at ? \Illuminate\Support\Carbon::parse($record->clock_out_at)->format('H:i') : '' }}"
                                                        class="border rounded px-2 py-1 w-24"
                                                    >
                                                    <button class="px-3 py-1 bg-emerald-700 text-white rounded border border-emerald-900 text-xs font-semibold">Update</button>
                                                </form>
                                                <form method="POST" action="{{ route('attendance.destroy', $record) }}" onsubmit="return confirm('Delete this daily attendance record? This will recalculate affected draft payroll entries.');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="px-3 py-1 bg-red-700 text-white rounded border border-red-900 text-xs font-semibold">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    @endif
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ auth()->user()?->hasRole('owner') ? 5 : 4 }}" class="py-4 text-gray-500">No attendance records found for this date.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
