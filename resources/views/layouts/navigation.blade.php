<div x-data="{ open: false }">

    {{-- Mobile backdrop --}}
    <div
        x-show="open"
        x-transition:enter="transition-opacity ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition-opacity ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        @click="open = false"
        class="rh-sidebar-backdrop"
        style="display: none;"
    ></div>

    {{-- Sidebar --}}
    <aside :class="{ 'rh-sidebar--open': open }" class="rh-sidebar">

        {{-- Logo --}}
        <div class="rh-sidebar-logo-wrap">
            <a href="{{ route('dashboard') }}" class="rh-sidebar-logo">
                Restaurant Hub
            </a>
        </div>

        {{-- Navigation --}}
        <nav class="rh-sidebar-nav">

            <a href="{{ route('dashboard') }}"
               class="rh-nav-link {{ request()->routeIs('dashboard') ? 'rh-nav-link--active' : '' }}">
                <svg class="rh-nav-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 9.5L10 3l7 6.5V17a1 1 0 01-1 1H4a1 1 0 01-1-1V9.5z"/>
                </svg>
                <span class="rh-nav-label">Dashboard</span>
            </a>

            <a href="{{ route('employees.index') }}"
               class="rh-nav-link {{ request()->routeIs('employees.*') ? 'rh-nav-link--active' : '' }}">
                <svg class="rh-nav-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 14c0-3.314-3.582-6-8-6s-8 2.686-8 6"/>
                </svg>
                <span class="rh-nav-label">Employees</span>
            </a>

            <a href="{{ route('attendance.index') }}"
               class="rh-nav-link {{ request()->routeIs('attendance.*') ? 'rh-nav-link--active' : '' }}">
                <svg class="rh-nav-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="10" cy="10" r="8"/>
                    <path d="M10 6v4l3 3"/>
                </svg>
                <span class="rh-nav-label">Attendance</span>
            </a>

            <a href="{{ route('sales.index') }}"
               class="rh-nav-link {{ request()->routeIs('sales.*') ? 'rh-nav-link--active' : '' }}">
                <svg class="rh-nav-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 17v-4a2 2 0 012-2h2a2 2 0 012 2v4M9 17V9a2 2 0 012-2h2a2 2 0 012 2v8M17 17V5a2 2 0 00-2-2h-1"/>
                </svg>
                <span class="rh-nav-label">Sales</span>
            </a>

            <a href="{{ route('expenses.index') }}"
               class="rh-nav-link {{ request()->routeIs('expenses.*') ? 'rh-nav-link--active' : '' }}">
                <svg class="rh-nav-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="4" width="16" height="12" rx="2"/>
                    <path d="M2 8h16"/>
                    <path d="M6 12h2M10 12h2"/>
                </svg>
                <span class="rh-nav-label">Expenses</span>
            </a>

            <a href="{{ route('payroll.index') }}"
               class="rh-nav-link {{ request()->routeIs('payroll.*') ? 'rh-nav-link--active' : '' }}">
                <svg class="rh-nav-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="10" cy="10" r="8"/>
                    <path d="M10 6v1m0 6v1M7.5 8.5C7.5 7.4 8.6 7 10 7s2.5.4 2.5 1.5S11.4 10 10 10s-2.5.6-2.5 1.5S8.6 13 10 13s2.5-.4 2.5-1.5"/>
                </svg>
                <span class="rh-nav-label">Payroll</span>
            </a>

            <a href="{{ route('inventory.index') }}"
               class="rh-nav-link {{ request()->routeIs('inventory.*') ? 'rh-nav-link--active' : '' }}">
                <svg class="rh-nav-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="3" width="16" height="4" rx="1"/>
                    <rect x="2" y="9" width="16" height="4" rx="1"/>
                    <rect x="2" y="15" width="16" height="2" rx="1"/>
                </svg>
                <span class="rh-nav-label">Inventory</span>
            </a>


        </nav>

        {{-- Theme toggle --}}
        <div class="rh-sidebar-theme"
             x-data="{ light: document.documentElement.classList.contains('light-mode') }"
             @click="
                light = !light;
                document.documentElement.classList.toggle('light-mode', light);
                localStorage.setItem('rh-theme', light ? 'light' : 'dark');
             ">
            <button type="button" class="rh-theme-btn">
                <svg x-show="!light" class="rh-theme-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="10" cy="10" r="3.5"/>
                    <path d="M10 2v1.5M10 16.5V18M2 10h1.5M16.5 10H18M4.22 4.22l1.06 1.06M14.72 14.72l1.06 1.06M4.22 15.78l1.06-1.06M14.72 5.28l1.06-1.06"/>
                </svg>
                <svg x-show="light" class="rh-theme-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="display:none">
                    <path d="M17.5 11.5A7.5 7.5 0 0 1 8.5 2.5a7.5 7.5 0 1 0 9 9z"/>
                </svg>
                <span x-text="light ? 'Dark Mode' : 'Light Mode'" class="rh-theme-label"></span>
            </button>
        </div>

        {{-- User --}}
        <div class="rh-sidebar-user">
            <div class="rh-sidebar-user-info">
                <p class="rh-user-name">{{ Auth::user()->name }}</p>
                <p class="rh-user-email">{{ Auth::user()->email }}</p>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="rh-signout-btn">
                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="width:13px;height:13px;flex-shrink:0;">
                        <path d="M7 3H4a1 1 0 00-1 1v12a1 1 0 001 1h3M13 7l4 3-4 3M8 10h9"/>
                    </svg>
                    Sign out
                </button>
            </form>
        </div>

    </aside>

    {{-- Mobile hamburger (hidden on desktop) --}}
    <button @click="open = !open" class="rh-hamburger" aria-label="Toggle navigation">
        <span :class="open ? 'opacity-0 scale-0' : ''" class="rh-ham-line" style="transition: all 0.2s;"></span>
        <span class="rh-ham-line"></span>
        <span :class="open ? 'opacity-0 scale-0' : ''" class="rh-ham-line" style="transition: all 0.2s;"></span>
    </button>

</div>
