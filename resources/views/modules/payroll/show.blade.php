<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Payroll Detail</h2>
    </x-slot>

    @php
        $startLabel = \Illuminate\Support\Carbon::parse($period->start_date)->format('M j');
        $endLabel = \Illuminate\Support\Carbon::parse($period->end_date)->format('M j, Y');
        $tierClassMap = [
            'None' => 'rh-pay-tier--None',
            '1st Hit' => 'rh-pay-tier--1',
            '2nd Hit' => 'rh-pay-tier--2',
            '3rd Hit' => 'rh-pay-tier--3',
        ];
        $statusClassMap = [
            'present' => 'rh-pay-day-pill--present',
            'late' => 'rh-pay-day-pill--late',
            'absent' => 'rh-pay-day-pill--absent',
            'leave' => 'rh-pay-day-pill--leave',
            'holiday' => 'rh-pay-day-pill--holiday',
        ];
    @endphp

    <div class="rh-pay-page" x-data="{ rulesOpen: false }">
        {{-- Period header --}}
        <div class="rh-pay-detail-header">
            <div class="rh-pay-detail-header-top">
                <div>
                    <h1 class="rh-pay-detail-period">{{ $startLabel }} – {{ $endLabel }}</h1>
                    <p class="rh-pay-detail-branch">{{ strtoupper($period->branch?->name ?? 'NO BRANCH') }} · {{ $totals['count'] }} {{ \Illuminate\Support\Str::plural('entry', $totals['count']) }}</p>
                </div>
                <a href="{{ route('payroll.index') }}" class="rh-pay-back">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                    Back to payroll
                </a>
            </div>
            <div class="rh-pay-detail-totals">
                <div>
                    <p class="rh-pay-detail-total-label">Net Total</p>
                    <p class="rh-pay-detail-total-value rh-pay-detail-total-value--success">₱{{ number_format($totals['net'], 2) }}</p>
                </div>
                <div>
                    <p class="rh-pay-detail-total-label">Gross</p>
                    <p class="rh-pay-detail-total-value">₱{{ number_format($totals['gross'], 2) }}</p>
                </div>
                <div>
                    <p class="rh-pay-detail-total-label">Deductions</p>
                    <p class="rh-pay-detail-total-value rh-pay-detail-total-value--warn">₱{{ number_format($totals['deductions'], 2) }}</p>
                </div>
                <div>
                    <p class="rh-pay-detail-total-label">Status</p>
                    <p class="rh-pay-detail-total-value">{{ ucfirst($selectedEntry->status ?? 'draft') }}</p>
                </div>
            </div>
        </div>

        {{-- Employee picker pills --}}
        @if ($entries->count() > 0)
            <div class="rh-pay-picker">
                @foreach ($entries as $entry)
                    @php
                        $isOn = $selectedEntry && $entry->id === $selectedEntry->id;
                        $emp = $entry->employee;
                    @endphp
                    <a
                        href="{{ route('payroll.show', $period) }}?employee_id={{ $entry->employee_id }}"
                        class="rh-pay-picker-pill {{ $isOn ? 'rh-pay-picker-pill--on' : '' }}"
                    >
                        <span>{{ trim(($emp?->first_name ?? '?').' '.($emp?->last_name ?? '')) }}</span>
                        <span class="rh-pay-picker-amount">₱{{ number_format((float) $entry->net_pay, 0) }}</span>
                    </a>
                @endforeach
            </div>
        @endif

        @if ($selectedEntry && $selectedEmployee)
            {{-- Selected employee summary --}}
            <div class="rh-pay-selected">
                <div class="rh-pay-selected-head">
                    <div>
                        <h2 class="rh-pay-selected-name">{{ $selectedEmployee->first_name }} {{ $selectedEmployee->last_name }}</h2>
                        <span class="rh-pay-selected-code">{{ $selectedEmployee->employee_code }} · ₱{{ number_format((float) ($selectedEntry->daily_rate ?? 0), 2) }}/day</span>
                    </div>
                    <div class="rh-pay-selected-net">
                        <p class="rh-pay-selected-net-label">Net Pay</p>
                        <p class="rh-pay-selected-net-value">₱{{ number_format((float) $selectedEntry->net_pay, 2) }}</p>
                    </div>
                </div>
                <div class="rh-pay-selected-grid">
                    <div>
                        <p class="rh-pay-selected-cell-label">Gross</p>
                        <p class="rh-pay-selected-cell-value">₱{{ number_format((float) $selectedEntry->gross_pay, 2) }}</p>
                    </div>
                    <div>
                        <p class="rh-pay-selected-cell-label">Deductions</p>
                        <p class="rh-pay-selected-cell-value" style="color: var(--rh-amber-text);">₱{{ number_format((float) $selectedEntry->deductions, 2) }}</p>
                    </div>
                    <div>
                        <p class="rh-pay-selected-cell-label">Present Days</p>
                        <p class="rh-pay-selected-cell-value">{{ (int) ($summary['present_days'] ?? 0) }}</p>
                    </div>
                    <div>
                        <p class="rh-pay-selected-cell-label">Late Days · Hours</p>
                        <p class="rh-pay-selected-cell-value">{{ (int) ($summary['late_days'] ?? 0) }} · {{ number_format((float) ($summary['late_hours'] ?? 0), 1) }}h</p>
                    </div>
                </div>
            </div>

            {{-- Applied rules (collapsible) --}}
            <div class="rh-pay-rules-block">
                <div class="rh-pay-rules-head" :class="rulesOpen ? 'rh-pay-rules-head--open' : ''" @click="rulesOpen = !rulesOpen">
                    <h3 class="rh-pay-rules-title">Applied Rules</h3>
                    <span class="rh-pay-rules-toggle">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>
                    </span>
                </div>
                <div class="rh-pay-rules-body" x-show="rulesOpen" x-cloak x-transition.opacity>
                    <div>
                        <p class="rh-pay-rules-cell-label">Standard Hours</p>
                        <p class="rh-pay-rules-cell-value">{{ number_format($rule->standard_daily_hours, 2) }} h</p>
                    </div>
                    <div>
                        <p class="rh-pay-rules-cell-label">Required Time-In</p>
                        <p class="rh-pay-rules-cell-value">{{ \Illuminate\Support\Carbon::parse($rule->required_clock_in_time)->format('h:i A') }}</p>
                    </div>
                    <div>
                        <p class="rh-pay-rules-cell-label">1st Hit</p>
                        <p class="rh-pay-rules-cell-value">{{ \Illuminate\Support\Carbon::parse($rule->first_deduction_time)->format('h:i A') }} · ₱{{ number_format((float) $rule->first_deduction_amount, 2) }}</p>
                    </div>
                    <div>
                        <p class="rh-pay-rules-cell-label">2nd Hit</p>
                        <p class="rh-pay-rules-cell-value">{{ \Illuminate\Support\Carbon::parse($rule->second_deduction_time)->format('h:i A') }} · ₱{{ number_format((float) $rule->second_deduction_amount, 2) }}</p>
                    </div>
                    <div>
                        <p class="rh-pay-rules-cell-label">3rd Hit</p>
                        <p class="rh-pay-rules-cell-value">{{ \Illuminate\Support\Carbon::parse($rule->third_deduction_time)->format('h:i A') }} · {{ number_format((float) $rule->third_deduction_percent, 2) }}%</p>
                    </div>
                </div>
            </div>

            {{-- Daily breakdown --}}
            <div class="rh-pay-daily">
                <div class="rh-pay-daily-head">
                    <h3 class="rh-pay-daily-title">Daily Breakdown</h3>
                </div>
                <div style="overflow-x: auto;">
                    <table class="rh-pay-daily-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Status</th>
                                <th>In</th>
                                <th>Out</th>
                                <th class="num">Late (min)</th>
                                <th class="center">Tier</th>
                                <th class="num">Gross</th>
                                <th class="num">Deduction</th>
                                <th class="num">Net</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($dailyBreakdown as $day)
                                @php
                                    $tierClass = $tierClassMap[$day['deduction_tier']] ?? 'rh-pay-tier--None';
                                    $statusClass = $statusClassMap[$day['status']] ?? 'rh-pay-day-pill--absent';
                                @endphp
                                <tr>
                                    <td>{{ \Illuminate\Support\Carbon::parse($day['work_date'])->format('D, M j') }}</td>
                                    <td><span class="rh-pay-day-pill {{ $statusClass }}">{{ $day['status'] }}</span></td>
                                    <td>{{ $day['clock_in'] }}</td>
                                    <td>{{ $day['clock_out'] }}</td>
                                    <td class="num">{{ $day['late_minutes'] }}</td>
                                    <td class="center"><span class="rh-pay-tier {{ $tierClass }}">{{ $day['deduction_tier'] }}</span></td>
                                    <td class="num">₱{{ number_format((float) $day['gross'], 2) }}</td>
                                    <td class="num" style="color: {{ (float) $day['deduction'] > 0 ? 'var(--rh-amber-text)' : 'var(--rh-text-dim)' }};">₱{{ number_format((float) $day['deduction'], 2) }}</td>
                                    <td class="num num--accent">₱{{ number_format((float) $day['net'], 2) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" style="text-align: center; padding: 2rem; color: var(--rh-text-muted);">No attendance rows for this period.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @else
            <div class="rh-pay-list">
                <div class="rh-pay-empty">
                    <p class="rh-pay-empty-title">No payroll entries</p>
                    <p style="font-size: 0.82rem;">This period doesn't have any reports yet.</p>
                </div>
            </div>
        @endif
    </div>
</x-app-layout>
