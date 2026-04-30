<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Inventory</h2>
    </x-slot>

    @php
        $hasActiveFilters = $filters['search'] !== '' || ! empty($filters['branch_id']) || $filters['unit'] !== '' || $filters['low_only'];
        $unitOptions = ['pcs', 'kg', 'g', 'l', 'ml'];

        $allIngredientsPayload = $allIngredients->map(fn ($i) => [
            'id' => $i->id,
            'name' => $i->name,
            'unit' => $i->unit,
            'current_stock' => (float) $i->current_stock,
            'branch_name' => $i->branch?->name,
        ])->values();
    @endphp

    <div
        class="rh-inv-page"
        x-data="inventoryPage({
            startCountUrl: @js(route('inventory.counts.start')),
            storeCountUrl: @js(route('inventory.counts.store')),
            countShowUrlTemplate: @js(route('inventory.counts.show', ['stockCount' => '__COUNT__'])),
            countDestroyUrlTemplate: @js(route('inventory.counts.destroy', ['stockCount' => '__COUNT__'])),
            ingredientShowUrlTemplate: @js(route('inventory.show', ['ingredient' => '__INGREDIENT__'])),
            updateUrlTemplate: @js(route('inventory.update', ['ingredient' => '__INGREDIENT__'])),
            csrfToken: @js(csrf_token()),
            allIngredients: @js($allIngredientsPayload),
        })"
        @keydown.escape.window="closeAll()"
    >
        @if (session('success'))
            <div class="rm-toast rm-toast--ok" x-data="{ shown: true }" x-show="shown" x-init="setTimeout(() => shown = false, 2800)"><span>{{ session('success') }}</span></div>
        @endif
        @if (session('error'))
            <div class="rm-toast rm-toast--err" x-data="{ shown: true }" x-show="shown" x-init="setTimeout(() => shown = false, 4000)"><span>{{ session('error') }}</span></div>
        @endif
        @if ($errors->any())
            <div class="rm-toast rm-toast--err" x-data="{ shown: true }" x-show="shown" x-init="setTimeout(() => shown = false, 5000)"><span>{{ $errors->first() }}</span></div>
        @endif

        {{-- Top bar --}}
        <div class="rh-inv-topbar">
            <div>
                <h1 class="rh-inv-title">Inventory</h1>
                <p class="rh-inv-sub">
                    @if ($stats['days_since_last_count'] !== null)
                        LAST COUNTED {{ $stats['days_since_last_count'] === 0 ? 'TODAY' : $stats['days_since_last_count'].'D AGO' }}
                    @else
                        NO COUNTS YET
                    @endif
                    · {{ $stats['total_items'] }} ITEMS
                </p>
            </div>
            <div class="rh-inv-topbar-actions">
                <button type="button" class="rm-btn rm-btn--ghost" @click="openCreate()">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14"/>
                    </svg>
                    Add Ingredient
                </button>
                <button type="button" class="rm-btn rm-btn--primary" @click="openStartCount()">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <rect x="3" y="4" width="18" height="16" rx="2"/>
                        <path stroke-linecap="round" d="M8 2v4M16 2v4M3 10h18M9 14h.01M15 14h.01M9 18h.01M15 18h.01"/>
                    </svg>
                    Start New Count
                </button>
            </div>
        </div>

        {{-- Stats strip --}}
        <div class="rh-inv-stats">
            <div class="rh-inv-stat" style="--i:1;">
                <p class="rh-inv-stat-label">Last Counted</p>
                <p class="rh-inv-stat-value">
                    @if ($stats['days_since_last_count'] === null)
                        —
                    @elseif ($stats['days_since_last_count'] === 0)
                        Today
                    @else
                        {{ $stats['days_since_last_count'] }}<span style="font-size: 0.6em; opacity: 0.6; margin-left: 0.15rem;">d ago</span>
                    @endif
                </p>
            </div>
            <div class="rh-inv-stat" style="--i:2;">
                <p class="rh-inv-stat-label">Total Items</p>
                <p class="rh-inv-stat-value">{{ $stats['total_items'] }}</p>
            </div>
            <div class="rh-inv-stat" style="--i:3;">
                <p class="rh-inv-stat-label">Low Stock</p>
                <p class="rh-inv-stat-value {{ $stats['low_stock_count'] > 0 ? 'rh-inv-stat-value--warn' : '' }}">{{ $stats['low_stock_count'] }}</p>
            </div>
            <div class="rh-inv-stat" style="--i:4;">
                <p class="rh-inv-stat-label">Counts · {{ now()->format('M') }}</p>
                <p class="rh-inv-stat-value rh-inv-stat-value--accent">{{ $stats['counts_this_month'] }}</p>
            </div>
        </div>

        {{-- Low stock banner --}}
        @if ($lowStock->isNotEmpty() && ! $filters['low_only'])
            <div class="rh-inv-low-banner">
                <span class="rh-inv-low-banner-msg">
                    {{ $lowStock->count() }} {{ \Illuminate\Support\Str::plural('item', $lowStock->count()) }} below reorder level
                </span>
                <a href="{{ route('inventory.index', array_merge(request()->except(['low_only', 'page']), ['low_only' => 1])) }}" class="rh-inv-low-banner-action">
                    View low stock →
                </a>
            </div>
        @endif

        {{-- Toolbar --}}
        <form method="GET" action="{{ route('inventory.index') }}" class="rh-inv-toolbar" x-ref="filterForm">
            <div class="rh-inv-toolbar-row">
                <label class="rh-inv-search">
                    <svg class="rh-inv-search-icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <circle cx="11" cy="11" r="7"/>
                        <path stroke-linecap="round" d="m20 20-3.5-3.5"/>
                    </svg>
                    <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Search by name or SKU…" x-on:input.debounce.350ms="$refs.filterForm.requestSubmit()">
                </label>
                <select name="branch_id" class="rh-inv-select" @change="$refs.filterForm.requestSubmit()">
                    <option value="">All branches</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}" {{ (string) $filters['branch_id'] === (string) $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                    @endforeach
                </select>
                <div class="rh-inv-chips">
                    @foreach ($unitOptions as $unit)
                        @php $on = $filters['unit'] === $unit; @endphp
                        <a
                            href="{{ route('inventory.index', array_merge(request()->except(['unit', 'page']), ['unit' => $on ? '' : $unit])) }}"
                            class="rh-inv-chip {{ $on ? 'rh-inv-chip--on' : '' }}"
                        >{{ $unit }}</a>
                    @endforeach
                </div>
                <a
                    href="{{ route('inventory.index', array_merge(request()->except(['low_only', 'page']), $filters['low_only'] ? [] : ['low_only' => 1])) }}"
                    class="rh-inv-chip rh-inv-chip--low {{ $filters['low_only'] ? 'rh-inv-chip--on' : '' }}"
                >Low stock</a>
                @if ($hasActiveFilters)
                    <a href="{{ route('inventory.index') }}" class="rh-emp-clear">Clear</a>
                @endif
            </div>
        </form>

        {{-- Ingredient list --}}
        @if ($ingredients->isEmpty())
            <div class="rh-inv-list">
                <div class="rh-inv-empty">
                    <svg class="rh-inv-empty-icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path d="M5 8h14l-1 12H6L5 8z"/>
                        <path d="M9 8V6a3 3 0 0 1 6 0v2"/>
                    </svg>
                    <p class="rh-inv-empty-title">No ingredients found</p>
                    <p style="font-size: 0.82rem;">@if ($hasActiveFilters) Try clearing filters. @else Click <strong>Add Ingredient</strong> to start. @endif</p>
                </div>
            </div>
        @else
            <div class="rh-inv-list">
                @foreach ($ingredients as $ingredient)
                    @php
                        $current = (float) $ingredient->current_stock;
                        $reorder = (float) $ingredient->reorder_level;
                        $isLow = $ingredient->isLowStock();
                        $isZero = $current <= 0;
                        $maxFor = max($reorder * 2, 1);
                        $pct = max(2, min(100, ($current / $maxFor) * 100));
                        $stockClass = $isZero ? 'rh-inv-stock-current--zero' : ($isLow ? 'rh-inv-stock-current--low' : '');
                        $fillClass = $isZero ? 'rh-inv-stock-fill--zero' : ($isLow ? 'rh-inv-stock-fill--low' : '');
                        $iconClass = 'rh-inv-icon--unit-'.$ingredient->unit;
                        $initial = strtoupper(substr($ingredient->name, 0, 1));
                        $payload = [
                            'id' => $ingredient->id,
                            'branch_id' => $ingredient->branch_id,
                            'name' => $ingredient->name,
                            'sku' => $ingredient->sku,
                            'unit' => $ingredient->unit,
                            'current_stock' => $current,
                            'reorder_level' => $reorder,
                            'is_active' => (bool) $ingredient->is_active,
                            'branch_name' => $ingredient->branch?->name,
                        ];
                        $stockFmt = rtrim(rtrim(number_format($current, 3), '0'), '.');
                        $reorderFmt = rtrim(rtrim(number_format($reorder, 3), '0'), '.');

                        $daily = (float) ($ingredient->daily_consumption ?? 0);
                        $daysLeft = $ingredient->days_remaining;
                        if ($daysLeft === null) {
                            $daysLeftLabel = '—';
                            $daysLeftClass = 'rh-inv-rate-days--muted';
                        } elseif ($daysLeft <= 3) {
                            $daysLeftLabel = number_format($daysLeft, 1).'d';
                            $daysLeftClass = 'rh-inv-rate-days--danger';
                        } elseif ($daysLeft <= 7) {
                            $daysLeftLabel = number_format($daysLeft, 1).'d';
                            $daysLeftClass = 'rh-inv-rate-days--warn';
                        } else {
                            $daysLeftLabel = number_format($daysLeft, 0).'d';
                            $daysLeftClass = '';
                        }
                        $dailyLabel = $daily > 0 ? rtrim(rtrim(number_format($daily, 2), '0'), '.').'/d' : 'No data';
                    @endphp
                    <div
                        class="rh-inv-row"
                        role="button"
                        tabindex="0"
                        @click="openIngredient({{ $ingredient->id }})"
                        @keydown.enter="openIngredient({{ $ingredient->id }})"
                        @keydown.space.prevent="openIngredient({{ $ingredient->id }})"
                    >
                        <span class="rh-inv-icon {{ $iconClass }}">{{ $initial }}</span>
                        <div class="rh-inv-name-block">
                            <div class="rh-inv-name">{{ $ingredient->name }}</div>
                            <span class="rh-inv-name-meta">{{ $ingredient->sku ?: '—' }} · {{ $ingredient->unit }}</span>
                        </div>
                        <span class="rh-inv-branch">{{ $ingredient->branch?->name ?? '—' }}</span>
                        <div class="rh-inv-stock">
                            <div class="rh-inv-stock-line">
                                <span class="rh-inv-stock-current {{ $stockClass }}">{{ $stockFmt }}<span style="font-size: 0.72em; color: var(--rh-text-muted); margin-left: 0.2rem;">{{ $ingredient->unit }}</span></span>
                                <span>reorder {{ $reorderFmt }}</span>
                            </div>
                            <span class="rh-inv-stock-track">
                                <span class="rh-inv-stock-fill {{ $fillClass }}" style="width: {{ $pct }}%;"></span>
                            </span>
                        </div>
                        <span class="rh-inv-rate">
                            <span class="rh-inv-rate-days {{ $daysLeftClass }}">{{ $daysLeftLabel }}</span>
                            {{ $dailyLabel }}
                        </span>
                        <span class="rh-inv-status {{ $ingredient->is_active ? 'rh-inv-status--active' : 'rh-inv-status--inactive' }}">
                            <span class="rh-inv-status-dot"></span>
                            {{ $ingredient->is_active ? 'Active' : 'Inactive' }}
                        </span>
                        <span class="rh-inv-actions" @click.stop x-data="{ open: false }" @click.outside="open = false">
                            <button type="button" class="rh-inv-kebab" @click.stop="open = !open" aria-label="Actions">
                                <svg fill="currentColor" viewBox="0 0 24 24">
                                    <circle cx="5" cy="12" r="1.6"/>
                                    <circle cx="12" cy="12" r="1.6"/>
                                    <circle cx="19" cy="12" r="1.6"/>
                                </svg>
                            </button>
                            <div class="rh-inv-menu" x-show="open" x-cloak x-transition.opacity.duration.150ms>
                                <button type="button" @click="open = false; openEdit(@js($payload))">
                                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-5m-9 1 8.5-8.5a1.41 1.41 0 0 1 2 2L13 15l-3 .5.5-3Z"/></svg>
                                    Edit
                                </button>
                                <hr>
                                <form method="POST" action="{{ route('inventory.destroy', $ingredient) }}" onsubmit="return confirm('Delete {{ addslashes($ingredient->name) }}?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="is-danger">
                                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </span>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Recent stock counts --}}
        <section class="rh-inv-counts">
            <div class="rh-inv-counts-head">
                <h3 class="rh-inv-counts-title">Recent Stock Counts</h3>
                <span style="font-family: var(--rh-font-mono); font-size: 0.62rem; letter-spacing: 0.06em; color: var(--rh-text-muted);">{{ $recentCounts->count() }} {{ \Illuminate\Support\Str::plural('count', $recentCounts->count()) }}</span>
            </div>
            <div class="rh-inv-counts-list">
                @if ($recentCounts->isEmpty())
                    <div class="rh-inv-count-empty">
                        <p style="margin: 0;">No counts yet. Periodic counting tracks consumption rates and helps you forecast restocks.</p>
                        <button type="button" class="rh-inv-count-empty-cta" @click="openStartCount()">Start First Count</button>
                    </div>
                @else
                    @foreach ($recentCounts as $count)
                        <div
                            class="rh-inv-count-row"
                            role="button"
                            tabindex="0"
                            @click="openCountDetail({{ $count->id }})"
                            @keydown.enter="openCountDetail({{ $count->id }})"
                            @keydown.space.prevent="openCountDetail({{ $count->id }})"
                        >
                            <span class="rh-inv-count-date">
                                <strong>{{ \Carbon\Carbon::parse($count->counted_at)->format('M j') }}</strong>
                                {{ \Carbon\Carbon::parse($count->counted_at)->format('Y') }}
                            </span>
                            <span class="rh-inv-count-branch">
                                {{ $count->branch?->name ?? '—' }}
                                @if ($count->recordedBy)
                                    <span class="rh-inv-count-by" style="display: block; margin-top: 0.15rem;">by {{ $count->recordedBy->name }}</span>
                                @endif
                            </span>
                            <span class="rh-inv-count-by" style="text-align: left;">
                                @if ($count->notes)
                                    {{ \Illuminate\Support\Str::limit($count->notes, 60) }}
                                @endif
                            </span>
                            <span class="rh-inv-count-items">{{ $count->entries_count }} items</span>
                            <span class="rh-inv-actions" @click.stop x-data="{ open: false }" @click.outside="open = false">
                                <button type="button" class="rh-inv-kebab" @click.stop="open = !open" aria-label="Actions">
                                    <svg fill="currentColor" viewBox="0 0 24 24">
                                        <circle cx="5" cy="12" r="1.6"/>
                                        <circle cx="12" cy="12" r="1.6"/>
                                        <circle cx="19" cy="12" r="1.6"/>
                                    </svg>
                                </button>
                                <div class="rh-inv-menu" x-show="open" x-cloak x-transition.opacity.duration.150ms>
                                    <button type="button" @click="open = false; openCountDetail({{ $count->id }})">
                                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
                                        View
                                    </button>
                                    @if ($loop->first)
                                        <hr>
                                        <form method="POST" action="{{ route('inventory.counts.destroy', $count) }}" onsubmit="return confirm('Delete this count? Stock levels will revert to the previous count.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="is-danger">
                                                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                                                Delete
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </span>
                        </div>
                    @endforeach
                @endif
            </div>
        </section>

        {{-- Start Count drawer --}}
        <template x-if="countOpen">
            <div class="rm-overlay" @click.self="closeCount()">
                <form method="POST" :action="storeCountUrl" class="rm-drawer rm-drawer--xl" @submit="submitting = true">
                    @csrf
                    <div class="rm-drawer-head">
                        <div>
                            <h2 class="rm-drawer-title">New Stock Count</h2>
                            <p class="rm-page-sub" x-show="countDraft.ingredients.length" x-text="countDraft.ingredients.length + ' items · enter the actual quantity for each'"></p>
                        </div>
                        <button type="button" class="rm-drawer-close" @click="closeCount()">×</button>
                    </div>
                    <div class="rm-drawer-body">
                        <template x-if="countLoading">
                            <div class="rh-inv-detail-loading">Loading…</div>
                        </template>
                        <template x-if="!countLoading">
                            <div>
                                <div class="rm-field-row" style="grid-template-columns: 1fr 1fr;">
                                    <div class="rm-field">
                                        <label class="rm-field-label">Branch</label>
                                        <select name="branch_id" class="rm-input" x-model="countDraft.branch_id" required>
                                            <option value="">Select branch</option>
                                            @foreach ($branches as $branch)
                                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="rm-field">
                                        <label class="rm-field-label">Count Date</label>
                                        <input type="date" name="counted_at" class="rm-input" x-model="countDraft.counted_at" required>
                                    </div>
                                </div>

                                <div class="rh-inv-count-summary">
                                    <span><strong x-text="countDraft.ingredients.length"></strong> items</span>
                                    <span>Total consumed <strong x-text="totalConsumptionLabel()"></strong></span>
                                </div>

                                <div style="overflow-x: auto;">
                                    <table class="rh-inv-count-table">
                                        <thead>
                                            <tr>
                                                <th>Ingredient</th>
                                                <th class="num">Previous</th>
                                                <th class="num">Restocked</th>
                                                <th class="num">Counted</th>
                                                <th class="num">Consumed</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <template x-for="(row, idx) in countDraft.ingredients" :key="row.ingredient_id">
                                                <tr>
                                                    <td>
                                                        <input type="hidden" :name="'entries[' + idx + '][ingredient_id]'" :value="row.ingredient_id">
                                                        <input type="hidden" :name="'entries[' + idx + '][previous_quantity]'" :value="row.previous_quantity">
                                                        <span class="rh-inv-count-name" x-text="row.name"></span>
                                                        <span class="rh-inv-count-name-meta" x-text="(row.sku || '—') + ' · ' + row.unit + ' · ' + (row.branch_name || '—')"></span>
                                                    </td>
                                                    <td class="num" x-text="formatStock(row.previous_quantity) + ' ' + row.unit"></td>
                                                    <td class="num">
                                                        <input
                                                            type="number"
                                                            step="0.001"
                                                            min="0"
                                                            class="rh-inv-count-input"
                                                            :name="'entries[' + idx + '][restocked_quantity]'"
                                                            x-model.number="row.restocked_quantity"
                                                            placeholder="0"
                                                        >
                                                    </td>
                                                    <td class="num">
                                                        <input
                                                            type="number"
                                                            step="0.001"
                                                            min="0"
                                                            class="rh-inv-count-input"
                                                            :name="'entries[' + idx + '][counted_quantity]'"
                                                            x-model.number="row.counted_quantity"
                                                            required
                                                        >
                                                    </td>
                                                    <td class="num">
                                                        <span class="rh-inv-count-consume" :class="rowConsumeClass(row)" x-text="rowConsumeLabel(row)"></span>
                                                    </td>
                                                </tr>
                                            </template>
                                            <template x-if="countDraft.ingredients.length === 0">
                                                <tr>
                                                    <td colspan="5" style="text-align: center; padding: 2rem; color: var(--rh-text-muted);">No ingredients in this branch.</td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="rm-field" style="margin-top: 1rem;">
                                    <label class="rm-field-label">Notes <span class="rm-field-opt">(optional)</span></label>
                                    <textarea name="notes" class="rm-input rm-textarea" x-model="countDraft.notes" rows="2" placeholder="Anything unusual about this count?"></textarea>
                                </div>
                            </div>
                        </template>
                    </div>
                    <div class="rm-drawer-foot">
                        <div></div>
                        <div class="rm-drawer-foot-right">
                            <button type="button" class="rm-btn rm-btn--ghost" @click="closeCount()">Cancel</button>
                            <button type="submit" class="rm-btn rm-btn--primary" :disabled="submitting || countDraft.ingredients.length === 0">Save count</button>
                        </div>
                    </div>
                </form>
            </div>
        </template>

        {{-- Count Detail drawer --}}
        <template x-if="countDetailOpen">
            <div class="rm-overlay" @click.self="closeCountDetail()">
                <div class="rm-drawer rm-drawer--xl">
                    <div class="rm-drawer-head">
                        <div>
                            <h2 class="rm-drawer-title" x-text="countDetail ? countDetail.count.counted_at_label : 'Loading…'"></h2>
                            <p class="rm-page-sub" x-show="countDetail" x-text="countDetail ? ((countDetail.count.branch ? countDetail.count.branch.name : '—') + ' · by ' + (countDetail.count.recorded_by || '—')) : ''"></p>
                        </div>
                        <button type="button" class="rm-drawer-close" @click="closeCountDetail()">×</button>
                    </div>
                    <div class="rm-drawer-body">
                        <template x-if="!countDetail">
                            <div class="rh-inv-detail-loading">Loading…</div>
                        </template>
                        <template x-if="countDetail">
                            <div>
                                <div class="rh-inv-count-summary" style="margin-bottom: 1rem;">
                                    <span><strong x-text="countDetail.entries.length"></strong> items</span>
                                    <span x-show="countDetail.count.days_since_previous"><strong x-text="countDetail.count.days_since_previous + ' days'"></strong> since previous count</span>
                                </div>

                                <div x-show="countDetail.count.notes" style="margin-bottom: 1rem; padding: 0.75rem 1rem; background: var(--rh-surface-2); border-radius: 8px; border: 1px solid var(--rh-border); font-family: var(--rh-font-sans); font-size: 0.82rem; color: var(--rh-text-muted); white-space: pre-wrap;" x-text="countDetail.count.notes"></div>

                                <div style="overflow-x: auto;">
                                    <table class="rh-inv-count-table">
                                        <thead>
                                            <tr>
                                                <th>Ingredient</th>
                                                <th class="num">Previous</th>
                                                <th class="num">Restocked</th>
                                                <th class="num">Counted</th>
                                                <th class="num">Consumed</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <template x-for="entry in countDetail.entries" :key="entry.id">
                                                <tr>
                                                    <td>
                                                        <span class="rh-inv-count-name" x-text="entry.name"></span>
                                                        <span class="rh-inv-count-name-meta" x-text="(entry.sku || '—') + ' · ' + entry.unit"></span>
                                                    </td>
                                                    <td class="num" x-text="formatStock(entry.previous_quantity) + ' ' + entry.unit"></td>
                                                    <td class="num" x-text="formatStock(entry.restocked_quantity) + ' ' + entry.unit"></td>
                                                    <td class="num" x-text="formatStock(entry.counted_quantity) + ' ' + entry.unit"></td>
                                                    <td class="num" :class="entry.consumption > 0 ? '' : 'rh-inv-count-consume--zero'" x-text="formatStock(entry.consumption) + ' ' + entry.unit"></td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </template>
                    </div>
                    <div class="rm-drawer-foot">
                        <div></div>
                        <div class="rm-drawer-foot-right">
                            <button type="button" class="rm-btn rm-btn--ghost" @click="closeCountDetail()">Close</button>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        {{-- Ingredient Detail drawer --}}
        <template x-if="ingredientOpen">
            <div class="rm-overlay" @click.self="closeIngredient()">
                <div class="rm-drawer rm-drawer--wide">
                    <div class="rm-drawer-head">
                        <div>
                            <h2 class="rm-drawer-title" x-text="ingredientData ? ingredientData.ingredient.name : 'Loading…'"></h2>
                            <p class="rm-page-sub" x-show="ingredientData" x-text="ingredientData ? ((ingredientData.ingredient.sku || '—') + ' · ' + (ingredientData.ingredient.branch_name || '—')) : ''"></p>
                        </div>
                        <button type="button" class="rm-drawer-close" @click="closeIngredient()">×</button>
                    </div>
                    <div class="rm-drawer-body">
                        <template x-if="!ingredientData">
                            <div class="rh-inv-detail-loading">Loading…</div>
                        </template>
                        <template x-if="ingredientData">
                            <div>
                                <div class="rh-inv-detail-section">
                                    <div class="rh-inv-detail-stock">
                                        <p class="rh-inv-detail-stock-value" :class="ingredientData.ingredient.current_stock <= 0 ? 'rh-inv-detail-stock-value--zero' : (ingredientData.ingredient.is_low_stock ? 'rh-inv-detail-stock-value--low' : '')">
                                            <span x-text="formatStock(ingredientData.ingredient.current_stock)"></span>
                                            <span class="rh-inv-detail-stock-unit" x-text="ingredientData.ingredient.unit"></span>
                                        </p>
                                        <p class="rh-inv-detail-stock-sub">
                                            Reorder at <span x-text="formatStock(ingredientData.ingredient.reorder_level) + ' ' + ingredientData.ingredient.unit"></span>
                                            <template x-if="ingredientData.ingredient.is_low_stock"><span style="color: var(--rh-amber-text); margin-left: 0.5rem;">· LOW</span></template>
                                        </p>
                                    </div>
                                    <span class="rh-inv-stock-track" style="display: block;">
                                        <span class="rh-inv-stock-fill" :class="ingredientData.ingredient.current_stock <= 0 ? 'rh-inv-stock-fill--zero' : (ingredientData.ingredient.is_low_stock ? 'rh-inv-stock-fill--low' : '')" :style="'width: ' + stockBarPct(ingredientData.ingredient) + '%;'"></span>
                                    </span>
                                </div>

                                <div class="rh-inv-detail-section">
                                    <p class="rh-inv-detail-label">Consumption</p>
                                    <div class="rh-inv-detail-grid">
                                        <div>
                                            <span class="rh-inv-detail-cell-label">Daily Rate</span>
                                            <span class="rh-inv-detail-cell-value" x-text="ingredientData.ingredient.daily_consumption > 0 ? formatStock(ingredientData.ingredient.daily_consumption) + ' ' + ingredientData.ingredient.unit + '/day' : 'No data yet'"></span>
                                        </div>
                                        <div>
                                            <span class="rh-inv-detail-cell-label">Days Remaining</span>
                                            <span class="rh-inv-detail-cell-value" x-text="ingredientData.ingredient.days_remaining !== null ? Number(ingredientData.ingredient.days_remaining).toFixed(1) + ' days' : '—'"></span>
                                        </div>
                                        <div>
                                            <span class="rh-inv-detail-cell-label">Branch</span>
                                            <span class="rh-inv-detail-cell-value" x-text="ingredientData.ingredient.branch_name || '—'"></span>
                                        </div>
                                        <div>
                                            <span class="rh-inv-detail-cell-label">Status</span>
                                            <span class="rh-inv-detail-cell-value" x-text="ingredientData.ingredient.is_active ? 'Active' : 'Inactive'"></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="rh-inv-detail-section">
                                    <p class="rh-inv-detail-label">Count History <span x-text="'· ' + ingredientData.history.length"></span></p>
                                    <template x-if="ingredientData.history.length === 0">
                                        <p style="font-size: 0.82rem; color: var(--rh-text-muted); text-align: center; padding: 1rem 0;">No counts yet.</p>
                                    </template>
                                    <template x-if="ingredientData.history.length > 0">
                                        <table class="rh-inv-count-table">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th class="num">Counted</th>
                                                    <th class="num">Restocked</th>
                                                    <th class="num">Consumed</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <template x-for="row in ingredientData.history" :key="row.id">
                                                    <tr>
                                                        <td x-text="row.counted_at_label"></td>
                                                        <td class="num" x-text="formatStock(row.counted_quantity) + ' ' + ingredientData.ingredient.unit"></td>
                                                        <td class="num" x-text="formatStock(row.restocked_quantity) + ' ' + ingredientData.ingredient.unit"></td>
                                                        <td class="num" :class="row.consumption > 0 ? '' : 'rh-inv-count-consume--zero'" x-text="formatStock(row.consumption) + ' ' + ingredientData.ingredient.unit"></td>
                                                    </tr>
                                                </template>
                                            </tbody>
                                        </table>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                    <div class="rm-drawer-foot">
                        <div></div>
                        <div class="rm-drawer-foot-right">
                            <button type="button" class="rm-btn rm-btn--ghost" @click="closeIngredient()">Close</button>
                            <button type="button" class="rm-btn rm-btn--primary" x-show="ingredientData" @click="editFromDetail()">Edit</button>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        {{-- Add/Edit Ingredient drawer --}}
        <template x-if="formOpen">
            <div class="rm-overlay" @click.self="closeForm()">
                <form
                    method="POST"
                    :action="form.mode === 'edit' ? form.action : '{{ route('inventory.store') }}'"
                    class="rm-drawer rm-drawer--wide"
                    @submit="submitting = true"
                >
                    @csrf
                    <template x-if="form.mode === 'edit'"><input type="hidden" name="_method" value="PUT"></template>
                    <div class="rm-drawer-head">
                        <h2 class="rm-drawer-title" x-text="form.mode === 'edit' ? 'Edit Ingredient' : 'Add Ingredient'"></h2>
                        <button type="button" class="rm-drawer-close" @click="closeForm()">×</button>
                    </div>
                    <div class="rm-drawer-body">
                        <template x-if="form.mode === 'create'">
                            <div class="rm-field">
                                <label class="rm-field-label">Branch</label>
                                <select name="branch_id" class="rm-input" x-model="form.branch_id" required>
                                    <option value="">Select branch</option>
                                    @foreach ($branches as $branch)
                                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </template>
                        <div class="rm-field">
                            <label class="rm-field-label">Name</label>
                            <input type="text" name="name" class="rm-input" x-model="form.name" required maxlength="120">
                        </div>
                        <div class="rm-field-row" style="grid-template-columns: 1fr 1fr;">
                            <div class="rm-field">
                                <label class="rm-field-label">SKU <span class="rm-field-opt">(optional)</span></label>
                                <input type="text" name="sku" class="rm-input" x-model="form.sku" maxlength="40">
                            </div>
                            <div class="rm-field">
                                <label class="rm-field-label">Unit</label>
                                <select name="unit" class="rm-input" x-model="form.unit" required>
                                    @foreach ($unitOptions as $u)
                                        <option value="{{ $u }}">{{ $u }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <template x-if="form.mode === 'create'">
                            <div class="rm-field-row" style="grid-template-columns: 1fr 1fr;">
                                <div class="rm-field">
                                    <label class="rm-field-label">Initial Stock</label>
                                    <input type="number" step="0.001" min="0" name="current_stock" class="rm-input" x-model="form.current_stock" required>
                                </div>
                                <div class="rm-field">
                                    <label class="rm-field-label">Reorder Level</label>
                                    <input type="number" step="0.001" min="0" name="reorder_level" class="rm-input" x-model="form.reorder_level" required>
                                </div>
                            </div>
                        </template>
                        <template x-if="form.mode === 'edit'">
                            <div class="rm-field-row" style="grid-template-columns: 1fr 1fr;">
                                <div class="rm-field">
                                    <label class="rm-field-label">Reorder Level</label>
                                    <input type="number" step="0.001" min="0" name="reorder_level" class="rm-input" x-model="form.reorder_level" required>
                                </div>
                                <div class="rm-field">
                                    <label class="rm-field-label">Status</label>
                                    <label class="rm-toggle-wrap" style="margin-top: 0.25rem;">
                                        <input type="hidden" name="is_active" value="0">
                                        <input type="checkbox" name="is_active" value="1" class="rm-toggle" x-model="form.is_active">
                                        <span class="rm-toggle-label" x-text="form.is_active ? 'Active' : 'Inactive'"></span>
                                    </label>
                                </div>
                            </div>
                        </template>
                    </div>
                    <div class="rm-drawer-foot">
                        <div></div>
                        <div class="rm-drawer-foot-right">
                            <button type="button" class="rm-btn rm-btn--ghost" @click="closeForm()">Cancel</button>
                            <button type="submit" class="rm-btn rm-btn--primary" :disabled="submitting">
                                <span x-text="form.mode === 'edit' ? 'Save changes' : 'Add ingredient'"></span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </template>
    </div>

    <script>
        function inventoryPage(config) {
            return {
                startCountUrl: config.startCountUrl,
                storeCountUrl: config.storeCountUrl,
                countShowUrlTemplate: config.countShowUrlTemplate,
                countDestroyUrlTemplate: config.countDestroyUrlTemplate,
                ingredientShowUrlTemplate: config.ingredientShowUrlTemplate,
                updateUrlTemplate: config.updateUrlTemplate,
                csrfToken: config.csrfToken,
                allIngredients: config.allIngredients,
                countOpen: false,
                countLoading: false,
                countDraft: { branch_id: '', counted_at: '', notes: '', ingredients: [] },
                countDetailOpen: false,
                countDetail: null,
                ingredientOpen: false,
                ingredientData: null,
                ingredientController: null,
                formOpen: false,
                submitting: false,
                form: {
                    mode: 'create',
                    action: '',
                    branch_id: '',
                    name: '',
                    sku: '',
                    unit: 'pcs',
                    current_stock: '0',
                    reorder_level: '0',
                    is_active: true,
                },
                async openStartCount() {
                    this.countOpen = true;
                    this.countLoading = true;
                    this.submitting = false;
                    try {
                        const res = await fetch(this.startCountUrl, {
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        });
                        if (!res.ok) throw new Error('Failed to load');
                        const data = await res.json();
                        this.countDraft = {
                            branch_id: '',
                            counted_at: data.today,
                            notes: '',
                            ingredients: data.ingredients,
                        };
                    } catch (err) {
                        this.countOpen = false;
                        console.error(err);
                    } finally {
                        this.countLoading = false;
                    }
                },
                closeCount() {
                    this.countOpen = false;
                    this.submitting = false;
                },
                rowConsumeLabel(row) {
                    const prev = Number(row.previous_quantity || 0);
                    const restock = Number(row.restocked_quantity || 0);
                    const counted = Number(row.counted_quantity || 0);
                    const consumed = prev + restock - counted;
                    if (consumed === 0) return '0';
                    if (consumed < 0) return '+' + this.formatStock(Math.abs(consumed));
                    return this.formatStock(consumed);
                },
                rowConsumeClass(row) {
                    const prev = Number(row.previous_quantity || 0);
                    const restock = Number(row.restocked_quantity || 0);
                    const counted = Number(row.counted_quantity || 0);
                    const consumed = prev + restock - counted;
                    if (consumed === 0) return 'rh-inv-count-consume--zero';
                    if (consumed < 0) return 'rh-inv-count-consume--neg';
                    return '';
                },
                totalConsumptionLabel() {
                    let positive = 0;
                    let count = 0;
                    for (const row of this.countDraft.ingredients) {
                        const c = Number(row.previous_quantity || 0) + Number(row.restocked_quantity || 0) - Number(row.counted_quantity || 0);
                        if (c > 0) {
                            positive += c;
                            count++;
                        }
                    }
                    if (count === 0) return 'none';
                    return count + ' item' + (count === 1 ? '' : 's');
                },
                async openCountDetail(countId) {
                    this.countDetail = null;
                    this.countDetailOpen = true;
                    try {
                        const url = this.countShowUrlTemplate.replace('__COUNT__', countId);
                        const res = await fetch(url, {
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        });
                        if (!res.ok) throw new Error('Failed to load');
                        this.countDetail = await res.json();
                    } catch (err) {
                        this.countDetailOpen = false;
                        console.error(err);
                    }
                },
                closeCountDetail() {
                    this.countDetailOpen = false;
                    this.countDetail = null;
                },
                async openIngredient(ingredientId) {
                    if (this.ingredientController) this.ingredientController.abort();
                    this.ingredientData = null;
                    this.ingredientOpen = true;
                    this.ingredientController = new AbortController();
                    try {
                        const url = this.ingredientShowUrlTemplate.replace('__INGREDIENT__', ingredientId);
                        const res = await fetch(url, {
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            signal: this.ingredientController.signal,
                        });
                        if (!res.ok) throw new Error('Failed to load');
                        this.ingredientData = await res.json();
                    } catch (err) {
                        if (err.name !== 'AbortError') {
                            this.ingredientOpen = false;
                            console.error(err);
                        }
                    }
                },
                closeIngredient() {
                    this.ingredientOpen = false;
                    this.ingredientData = null;
                    if (this.ingredientController) this.ingredientController.abort();
                },
                openCreate() {
                    this.form = {
                        mode: 'create',
                        action: '',
                        branch_id: '',
                        name: '',
                        sku: '',
                        unit: 'pcs',
                        current_stock: '0',
                        reorder_level: '0',
                        is_active: true,
                    };
                    this.formOpen = true;
                    this.submitting = false;
                },
                openEdit(item) {
                    this.form = {
                        mode: 'edit',
                        action: this.updateUrlTemplate.replace('__INGREDIENT__', item.id),
                        branch_id: String(item.branch_id ?? ''),
                        name: item.name ?? '',
                        sku: item.sku ?? '',
                        unit: item.unit ?? 'pcs',
                        current_stock: String(item.current_stock ?? '0'),
                        reorder_level: String(item.reorder_level ?? '0'),
                        is_active: Boolean(item.is_active),
                    };
                    this.formOpen = true;
                    this.ingredientOpen = false;
                    this.submitting = false;
                },
                editFromDetail() {
                    if (!this.ingredientData) return;
                    this.openEdit(this.ingredientData.ingredient);
                },
                closeForm() {
                    this.formOpen = false;
                    this.submitting = false;
                },
                closeAll() {
                    this.countOpen = false;
                    this.countDetailOpen = false;
                    this.ingredientOpen = false;
                    this.formOpen = false;
                },
                formatStock(n) {
                    if (n === null || n === undefined) return '0';
                    const num = Number(n);
                    let s = num.toFixed(3);
                    return s.replace(/\.?0+$/, '');
                },
                stockBarPct(ing) {
                    const max = Math.max((ing.reorder_level || 0) * 2, 1);
                    return Math.max(2, Math.min(100, (ing.current_stock / max) * 100));
                },
            };
        }
    </script>
</x-app-layout>
