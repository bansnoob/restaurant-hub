{{-- Ingredient list, cut into the sections the stockroom is arranged in.

     $groups arrives ALREADY in walk order from InventoryController::groupByCategory
     (category sort_order, then ingredient name, uncategorized last). Nothing here
     sorts: the browse list, the count modal and the phone must all name and place
     the same shelf identically.

     Rendered inside the page's x-data scope, so it calls the component directly. --}}
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
        @foreach ($groups as $group)
            @php
                $groupItems = collect($group['items']);
                $groupLow = $groupItems->filter(fn ($i) => $i->isLowStock())->count();
            @endphp
            <div class="rh-inv-group-head">
                <span>{{ $group['name'] }}</span>
                <span>
                    {{ $groupItems->count() }}
                    @if ($groupLow > 0)
                        <span class="rh-inv-group-low">{{ $groupLow }} low</span>
                    @endif
                </span>
            </div>
            @foreach ($group['items'] as $ingredient)
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
                        // Seeds the form's picker on Edit. Without it every save
                        // from this menu would post a blank and clear the shelf.
                        'ingredient_category_id' => $ingredient->ingredient_category_id,
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
                        <span class="rh-inv-name-meta">{{ $ingredient->sku ?: '—' }} · {{ $ingredient->unit }} · {{ $group['name'] }}</span>
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
        @endforeach
    </div>
@endif
