<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Payroll</h2>
    </x-slot>

    <div
        class="py-8"
        x-data="payrollRulesForm({
            initialBranchId: @js(old('branch_id', optional($branches->first())->id)),
            oldValues: @js([
                'standard_daily_hours' => old('standard_daily_hours'),
                'required_clock_in_time' => old('required_clock_in_time'),
                'first_deduction_time' => old('first_deduction_time'),
                'first_deduction_amount' => old('first_deduction_amount'),
                'second_deduction_time' => old('second_deduction_time'),
                'second_deduction_amount' => old('second_deduction_amount'),
                'third_deduction_time' => old('third_deduction_time'),
                'third_deduction_percent' => old('third_deduction_percent'),
            ]),
            rulesByBranch: @js($rules->mapWithKeys(fn ($rule, $branchId) => [
                (string) $branchId => [
                    'standard_daily_hours' => (float) $rule->standard_daily_hours,
                    'required_clock_in_time' => \Illuminate\Support\Carbon::parse($rule->required_clock_in_time)->format('H:i'),
                    'first_deduction_time' => \Illuminate\Support\Carbon::parse($rule->first_deduction_time)->format('H:i'),
                    'first_deduction_amount' => (float) $rule->first_deduction_amount,
                    'second_deduction_time' => \Illuminate\Support\Carbon::parse($rule->second_deduction_time)->format('H:i'),
                    'second_deduction_amount' => (float) $rule->second_deduction_amount,
                    'third_deduction_time' => \Illuminate\Support\Carbon::parse($rule->third_deduction_time)->format('H:i'),
                    'third_deduction_percent' => (float) $rule->third_deduction_percent,
                ],
            ])),
        })"
    >
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('success'))
                <div class="bg-green-100 text-green-800 p-3 rounded">{{ session('success') }}</div>
            @endif
            @if (session('error'))
                <div class="bg-red-100 text-red-800 p-3 rounded">{{ session('error') }}</div>
            @endif
            @if ($errors->any())
                <div class="bg-red-100 text-red-800 p-3 rounded">{{ $errors->first() }}</div>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold mb-4">Attendance to Payroll Rules</h3>
                <form method="POST" action="{{ route('payroll.rules.update') }}" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    @csrf
                    <div>
                        <label class="text-sm text-gray-600">Branch</label>
                        <select name="branch_id" x-model="branchId" @change="applyBranchRules()" class="w-full border rounded px-3 py-2" required>
                            <option value="">Select branch</option>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}" @selected((string) old('branch_id') === (string) $branch->id)>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">Standard Daily Hours</label>
                        <input type="number" step="0.25" name="standard_daily_hours" x-model="fields.standard_daily_hours" min="1" class="w-full border rounded px-3 py-2" required>
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">Required Time-In</label>
                        <input type="time" name="required_clock_in_time" x-model="fields.required_clock_in_time" class="w-full border rounded px-3 py-2" required>
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">1st Hit Time</label>
                        <input type="time" name="first_deduction_time" x-model="fields.first_deduction_time" class="w-full border rounded px-3 py-2" required>
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">1st Fixed Deduction</label>
                        <input type="number" step="0.01" min="0" name="first_deduction_amount" x-model="fields.first_deduction_amount" class="w-full border rounded px-3 py-2" required>
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">2nd Hit Time</label>
                        <input type="time" name="second_deduction_time" x-model="fields.second_deduction_time" class="w-full border rounded px-3 py-2" required>
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">2nd Fixed Deduction</label>
                        <input type="number" step="0.01" min="0" name="second_deduction_amount" x-model="fields.second_deduction_amount" class="w-full border rounded px-3 py-2" required>
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">3rd Hit Time</label>
                        <input type="time" name="third_deduction_time" x-model="fields.third_deduction_time" class="w-full border rounded px-3 py-2" required>
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">3rd Deduction Percent</label>
                        <input type="number" step="0.01" min="0" max="100" name="third_deduction_percent" x-model="fields.third_deduction_percent" class="w-full border rounded px-3 py-2" required>
                    </div>
                    <div class="md:col-span-4">
                        <button class="px-4 py-2 bg-indigo-700 text-white rounded">Save Rules</button>
                    </div>
                </form>
                <p class="mt-3 text-xs text-gray-500">
                    Deduction order is required time-in -> first hit (fixed) -> second hit (fixed) -> third hit (percentage of daily rate).
                </p>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold mb-4">Generate Payroll Report</h3>
                <form method="POST" action="{{ route('payroll.generate') }}" class="grid grid-cols-1 md:grid-cols-6 gap-4">
                    @csrf
                    <div>
                        <label class="text-sm text-gray-600">Employee</label>
                        <select name="employee_id" class="w-full border rounded px-3 py-2" required>
                            <option value="">Select employee</option>
                            @foreach ($employees as $employee)
                                <option value="{{ $employee->id }}">
                                    {{ $employee->first_name }} {{ $employee->last_name }} ({{ $employee->branch?->name ?? 'No Branch' }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">Start Date</label>
                        <input type="date" name="start_date" class="w-full border rounded px-3 py-2" required>
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">End Date</label>
                        <input type="date" name="end_date" class="w-full border rounded px-3 py-2" required>
                    </div>
                    <div class="md:col-span-6">
                        <button class="w-full px-4 py-2 bg-gray-800 text-white rounded">Generate</button>
                    </div>
                </form>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold mb-4">Payroll Reports</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left border-b">
                                <th class="py-2 pr-4">Label</th>
                                <th class="py-2 pr-4">Branch Name</th>
                                <th class="py-2 pr-4">Start</th>
                                <th class="py-2 pr-4">End</th>
                                <th class="py-2 pr-4">Status</th>
                                <th class="py-2 pr-4">Processed</th>
                                <th class="py-2 pr-4">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                                @forelse ($reports as $report)
                                @php($period = $report->payrollPeriod)
                                @php($employee = $report->employee)
                                @php($branch = $period?->branch ?? $employee?->branch)
                                @php($startLabel = $period ? \Illuminate\Support\Carbon::parse($period->start_date)->format('M j') : '-')
                                @php($endLabel = $period ? \Illuminate\Support\Carbon::parse($period->end_date)->format('M j') : '-')
                                @php($reportLabel = trim(($employee?->first_name ?? '').' '.($employee?->last_name ?? '')).' ('.$startLabel.' - '.$endLabel.')')
                                <tr class="border-b">
                                    <td class="py-2 pr-4">{{ $reportLabel }}</td>
                                    <td class="py-2 pr-4">{{ $branch?->name ?? '-' }}</td>
                                    <td class="py-2 pr-4">{{ $period?->start_date ?? '-' }}</td>
                                    <td class="py-2 pr-4">{{ $period?->end_date ?? '-' }}</td>
                                    <td class="py-2 pr-4">{{ ucfirst($report->status ?? 'draft') }}</td>
                                    <td class="py-2 pr-4">{{ $period?->processed_at ?? '-' }}</td>
                                    <td class="py-2 pr-4 min-w-[160px]">
                                        <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                                            @if ($period)
                                                <a href="{{ route('payroll.show', $period) }}?employee_id={{ $report->employee_id }}" class="w-full sm:w-auto inline-flex justify-center px-3 py-2 bg-blue-700 text-white rounded text-xs font-semibold">Details</a>
                                                @if ($report->status === 'draft')
                                                    <form method="POST" action="{{ route('payroll.finalize') }}" class="w-full sm:w-auto">
                                                        @csrf
                                                        <input type="hidden" name="payroll_entry_id" value="{{ $report->id }}">
                                                        <button class="w-full sm:w-auto inline-flex justify-center px-3 py-2 bg-emerald-700 text-white rounded text-xs font-semibold">Finalize</button>
                                                    </form>
                                                    <form method="POST" action="{{ route('payroll.reports.destroy', $report) }}" class="w-full sm:w-auto" onsubmit="return confirm('Delete this payroll report? This cannot be undone.');">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button class="w-full sm:w-auto inline-flex justify-center px-3 py-2 bg-red-700 text-white rounded text-xs font-semibold">Delete Report</button>
                                                    </form>
                                                @else
                                                    <span class="text-xs text-gray-500">Locked</span>
                                                @endif
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="py-4 text-gray-500">No payroll reports generated yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">{{ $reports->links() }}</div>
            </div>
        </div>
    </div>
    <script>
        function payrollRulesForm(config) {
            const defaults = {
                standard_daily_hours: '8',
                required_clock_in_time: '09:00',
                first_deduction_time: '09:15',
                first_deduction_amount: '50.00',
                second_deduction_time: '09:30',
                second_deduction_amount: '100.00',
                third_deduction_time: '10:00',
                third_deduction_percent: '50.00',
            };

            const hasOldValues = Object.values(config.oldValues || {}).some((value) => value !== null && value !== '');
            return {
                branchId: config.initialBranchId ? String(config.initialBranchId) : '',
                fields: {...defaults},
                applyBranchRules() {
                    const branchRules = this.branchId ? config.rulesByBranch?.[this.branchId] : null;
                    if (branchRules) {
                        this.fields = {
                            standard_daily_hours: String(branchRules.standard_daily_hours ?? defaults.standard_daily_hours),
                            required_clock_in_time: String(branchRules.required_clock_in_time ?? defaults.required_clock_in_time),
                            first_deduction_time: String(branchRules.first_deduction_time ?? defaults.first_deduction_time),
                            first_deduction_amount: String(branchRules.first_deduction_amount ?? defaults.first_deduction_amount),
                            second_deduction_time: String(branchRules.second_deduction_time ?? defaults.second_deduction_time),
                            second_deduction_amount: String(branchRules.second_deduction_amount ?? defaults.second_deduction_amount),
                            third_deduction_time: String(branchRules.third_deduction_time ?? defaults.third_deduction_time),
                            third_deduction_percent: String(branchRules.third_deduction_percent ?? defaults.third_deduction_percent),
                        };
                    } else {
                        this.fields = {...defaults};
                    }
                },
                init() {
                    if (hasOldValues) {
                        this.fields = {
                            standard_daily_hours: String(config.oldValues.standard_daily_hours ?? defaults.standard_daily_hours),
                            required_clock_in_time: String(config.oldValues.required_clock_in_time ?? defaults.required_clock_in_time),
                            first_deduction_time: String(config.oldValues.first_deduction_time ?? defaults.first_deduction_time),
                            first_deduction_amount: String(config.oldValues.first_deduction_amount ?? defaults.first_deduction_amount),
                            second_deduction_time: String(config.oldValues.second_deduction_time ?? defaults.second_deduction_time),
                            second_deduction_amount: String(config.oldValues.second_deduction_amount ?? defaults.second_deduction_amount),
                            third_deduction_time: String(config.oldValues.third_deduction_time ?? defaults.third_deduction_time),
                            third_deduction_percent: String(config.oldValues.third_deduction_percent ?? defaults.third_deduction_percent),
                        };
                        return;
                    }

                    this.applyBranchRules();
                },
            };
        }
    </script>
</x-app-layout>
