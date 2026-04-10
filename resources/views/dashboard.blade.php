<x-app-layout>

    <div class="rh-dashboard">

        {{-- Greeting --}}
        @php
            $hour = now()->hour;
            $greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
        @endphp

        <div class="rh-greeting-section">
            <p class="rh-greeting-salutation">{{ $greeting }},</p>
            <h1 class="rh-greeting-name">{{ Auth::user()->name }}</h1>
            <p class="rh-greeting-date">{{ strtoupper(now()->format('D · d F Y')) }}</p>
        </div>

        <div class="rh-section-rule"></div>

        {{-- Module Grid --}}
        <div class="rh-modules-grid">

            <a href="{{ route('employees.index') }}" class="rh-module-card" style="--i:1">
                <span class="rh-card-index">01</span>
                <h2 class="rh-card-name">Employees</h2>
                <p class="rh-card-desc">Create and maintain employee records for attendance and payroll.</p>
                <span class="rh-card-arrow">&#8594;</span>
                <span class="rh-card-accent"></span>
            </a>

            <a href="{{ route('attendance.index') }}" class="rh-module-card" style="--i:2">
                <span class="rh-card-index">02</span>
                <h2 class="rh-card-name">Attendance</h2>
                <p class="rh-card-desc">Time-in / time-out tracking and daily attendance summaries.</p>
                <span class="rh-card-arrow">&#8594;</span>
                <span class="rh-card-accent"></span>
            </a>

            <a href="{{ route('sales.index') }}" class="rh-module-card" style="--i:3">
                <span class="rh-card-index">03</span>
                <h2 class="rh-card-name">Sales</h2>
                <p class="rh-card-desc">POS sales reporting, daily summaries, and revenue tracking.</p>
                <span class="rh-card-arrow">&#8594;</span>
                <span class="rh-card-accent"></span>
            </a>

            <a href="{{ route('expenses.index') }}" class="rh-module-card" style="--i:4">
                <span class="rh-card-index">04</span>
                <h2 class="rh-card-name">Expenses</h2>
                <p class="rh-card-desc">Log and monitor operational expenses across all branches.</p>
                <span class="rh-card-arrow">&#8594;</span>
                <span class="rh-card-accent"></span>
            </a>

            <a href="{{ route('payroll.index') }}" class="rh-module-card" style="--i:5">
                <span class="rh-card-index">05</span>
                <h2 class="rh-card-name">Payroll</h2>
                <p class="rh-card-desc">Generate payroll periods and review computed pay for all staff.</p>
                <span class="rh-card-arrow">&#8594;</span>
                <span class="rh-card-accent"></span>
            </a>

        </div>

    </div>

</x-app-layout>
