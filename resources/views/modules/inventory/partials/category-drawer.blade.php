{{-- Category manager: the physical walk a stock count follows.

     Categories belong to a branch, so this edits ONE branch's walk — the
     filtered branch, or the only branch there is. Reordering is staged locally
     (▲▼) and committed by ONE post: a connection dropped between N separate
     saves would leave the walk half-renumbered for whoever counts next.

     Inactive categories are listed here and nowhere else; the pickers hide them. --}}
<template x-if="categoriesOpen">
    <div class="rm-overlay" @click.self="closeCategories()">
        <div class="rm-drawer rm-drawer--wide">
            <div class="rm-drawer-head">
                <div>
                    <h2 class="rm-drawer-title">Count Order</h2>
                    <p class="rm-page-sub">Arrange the sections in the order you physically walk them.</p>
                </div>
                <button type="button" class="rm-drawer-close" @click="closeCategories()">×</button>
            </div>
            <div class="rm-drawer-body">
                @if ($manageBranchId === '')
                    <p style="font-size: 0.82rem; color: var(--rh-text-muted); padding: 1.5rem 0; text-align: center;">
                        Pick a branch in the toolbar first — each branch keeps its own count order.
                    </p>
                @else
                    {{-- Every write below reloads the page and re-stages the walk from the
                         server, so a staged-but-unsaved reorder would vanish silently.
                         confirmDiscardStagedOrder() asks first, and is a no-op when
                         nothing is pending. --}}
                    <form method="POST" action="{{ route('inventory.categories.store') }}" class="rm-field-row" style="grid-template-columns: 1fr auto; align-items: end; margin-bottom: 1.25rem;" @submit="confirmDiscardStagedOrder($event)">
                        @csrf
                        <input type="hidden" name="branch_id" value="{{ $manageBranchId }}">
                        <div class="rm-field" style="margin: 0;">
                            <label class="rm-field-label">Add a section</label>
                            <input type="text" name="name" class="rm-input" x-model="newCategoryName" maxlength="100" placeholder="e.g. Walk-in chiller">
                        </div>
                        <button type="submit" class="rm-btn rm-btn--primary" :disabled="! newCategoryName.trim()">Add</button>
                    </form>

                    <template x-if="categoryOrder.length === 0">
                        <p style="font-size: 0.82rem; color: var(--rh-text-muted); text-align: center; padding: 1rem 0;">
                            No sections yet. Everything is counted as one “{{ \App\Http\Controllers\InventoryController::UNCATEGORIZED_LABEL }}” list.
                        </p>
                    </template>

                    <template x-for="(cat, index) in categoryOrder" :key="cat.id">
                        <div class="rh-inv-cat-row" style="display: flex; align-items: center; gap: 0.6rem; padding: 0.55rem 0; border-bottom: 1px solid var(--rh-border);">
                            <span style="display: flex; flex-direction: column; gap: 0.15rem;">
                                <button type="button" class="rm-btn rm-btn--ghost rh-inv-cat-move" style="padding: 0 0.35rem; line-height: 1.2;" x-show="index > 0" @click="moveCategoryRow(cat.id, 'up')" aria-label="Move up">▲</button>
                                <button type="button" class="rm-btn rm-btn--ghost rh-inv-cat-move" style="padding: 0 0.35rem; line-height: 1.2;" x-show="index < categoryOrder.length - 1" @click="moveCategoryRow(cat.id, 'down')" aria-label="Move down">▼</button>
                            </span>
                            <form method="POST" :action="categoryUpdateUrl(cat.id)" style="display: flex; align-items: center; gap: 0.5rem; flex: 1;" @submit="confirmDiscardStagedOrder($event)">
                                @csrf
                                @method('PUT')
                                <input type="text" name="name" class="rm-input" style="flex: 1;" x-model="cat.name" maxlength="100" required>
                                <button type="submit" class="rm-btn rm-btn--ghost">Rename</button>
                            </form>
                            <span class="rh-inv-cat-count" style="font-family: var(--rh-font-mono); font-size: 0.62rem; color: var(--rh-text-muted); min-width: 5.5rem; text-align: right;" x-text="(cat.ingredient_count || 0) + ' items'"></span>
                            <form method="POST" :action="categoryUpdateUrl(cat.id)" @submit="confirmDiscardStagedOrder($event)">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="is_active" :value="cat.is_active ? '0' : '1'">
                                <button type="submit" class="rm-btn rm-btn--ghost" x-text="cat.is_active ? 'Active' : 'Inactive'"></button>
                            </form>
                            <form method="POST" :action="categoryDestroyUrl(cat.id)" @submit="confirmCategoryDelete($event, cat)">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="rm-btn rm-btn--ghost" style="color: var(--rh-danger-solid);">Delete</button>
                            </form>
                        </div>
                    </template>

                    {{-- Shared sections span every branch, so the server refuses to
                         rename, reorder or delete one from here. They are shown
                         read-only rather than offered as controls that can only fail. --}}
                    <template x-if="sharedCategories().length > 0">
                        <div style="margin-top: 1.25rem;">
                            <p class="rh-inv-detail-label">Shared sections</p>
                            <template x-for="cat in sharedCategories()" :key="cat.id">
                                <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.6rem; padding: 0.45rem 0; font-size: 0.82rem; color: var(--rh-text-muted);">
                                    <span x-text="cat.name"></span>
                                    <span x-text="(cat.ingredient_count || 0) + ' items · managed centrally'"></span>
                                </div>
                            </template>
                        </div>
                    </template>
                @endif
            </div>
            <div class="rm-drawer-foot">
                <div></div>
                <div class="rm-drawer-foot-right">
                    <button type="button" class="rm-btn rm-btn--ghost" @click="closeCategories()">Close</button>
                    @if ($manageBranchId !== '')
                        <form method="POST" action="{{ route('inventory.categories.reorder') }}">
                            @csrf
                            <input type="hidden" name="branch_id" value="{{ $manageBranchId }}">
                            <template x-for="(cat, i) in categoryOrder" :key="cat.id">
                                <span>
                                    <input type="hidden" :name="'categories[' + i + '][id]'" :value="cat.id">
                                    <input type="hidden" :name="'categories[' + i + '][sort_order]'" :value="cat.sort_order">
                                </span>
                            </template>
                            <button type="submit" class="rm-btn rm-btn--primary" :disabled="! categoryOrderDirty || categoryOrder.length === 0">Save order</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
</template>
