{{-- New Stock Count drawer.

     The rows arrive from the server ALREADY in walk order and are grouped, not
     re-sorted, by the component's countGroups(). Two invariants the markup below
     depends on:

       · one <tbody> per group — Alpine's x-for needs a SINGLE root element per
         iteration, so a header <tr> plus N item <tr>s cannot both be roots of
         the outer loop. Multiple <tbody> elements in one <table> are valid, and
         <template> is legal as a direct child of <table>.
       · entries[group.startIndex + i] — the POST index has to stay unique across
         the WHOLE draft. Restarting i per group would silently collapse two
         shelves onto the same entry. --}}
<template x-if="countOpen">
    <div class="rm-overlay" @click.self="closeCount()">
        <form method="POST" :action="storeCountUrl" class="rm-drawer rm-drawer--xl" @submit="onCountSubmit($event)">
            @csrf
            <div class="rm-drawer-head">
                <div>
                    <h2 class="rm-drawer-title">New Stock Count</h2>
                    <p class="rm-page-sub" x-show="countDraft.ingredients.length" x-text="countDraft.ingredients.length + ' items · ' + countGroups().length + ' sections · count them shelf by shelf'"></p>
                </div>
                <button type="button" class="rm-drawer-close" @click="closeCount()">×</button>
            </div>
            <div class="rm-drawer-body">
                <template x-if="countLoading">
                    <div class="rh-inv-detail-loading">Loading…</div>
                </template>
                <template x-if="!countLoading">
                    <div>
                        <div x-show="countError" class="rh-inv-detail-loading" style="padding: 0.75rem 1rem; color: var(--rh-danger-solid);" x-text="countError"></div>

                        <div class="rm-field-row" style="grid-template-columns: 1fr 1fr;">
                            <div class="rm-field">
                                <label class="rm-field-label">Branch</label>
                                {{-- A count belongs to ONE branch, so switching branch REFETCHES
                                     the session. x-model is deliberately not used: the change has
                                     to be intercepted so a dirty draft can be confirmed first and
                                     the picker put back when the owner declines. --}}
                                <select name="branch_id" class="rm-input" :value="countDraft.branch_id" @change="onCountBranchChange($event)" required>
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
                                <template x-for="group in countGroups()" :key="group.key">
                                    <tbody>
                                        <tr class="rh-inv-count-group">
                                            <th colspan="5" style="text-align: left; background: var(--rh-surface-2); padding: 0.45rem 0.75rem; font-family: var(--rh-font-mono); font-size: 0.62rem; letter-spacing: 0.08em; text-transform: uppercase; color: var(--rh-text-muted);">
                                                <span x-text="group.title"></span>
                                                <span style="float: right;" x-text="group.rows.length"></span>
                                            </th>
                                        </tr>
                                        <template x-for="(row, i) in group.rows" :key="row.ingredient_id">
                                            <tr>
                                                <td>
                                                    <input type="hidden" :name="'entries[' + (group.startIndex + i) + '][ingredient_id]'" :value="row.ingredient_id">
                                                    <span class="rh-inv-count-name" x-text="row.name"></span>
                                                    <span class="rh-inv-count-name-meta" x-text="(row.sku || '—') + ' · ' + row.unit + ' · ' + (row.branch_name || '—')"></span>
                                                </td>
                                                <td class="num">
                                                    <span x-text="formatStock(row.previous_quantity) + ' ' + row.unit"></span>
                                                    {{-- Deliveries logged since the last count come from
                                                         inventory_movements. They are folded into the count
                                                         server-side, so they are shown read-only here and are
                                                         already part of the Consumed column below. --}}
                                                    <span
                                                        class="rh-inv-count-name-meta"
                                                        x-show="Number(row.pending_restock || 0) !== 0"
                                                        x-text="(Number(row.pending_restock || 0) > 0 ? '+' : '-') + formatStock(Math.abs(Number(row.pending_restock || 0))) + ' logged'"
                                                    ></span>
                                                </td>
                                                <td class="num">
                                                    <input type="number" step="0.001" min="0" class="rh-inv-count-input" :name="'entries[' + (group.startIndex + i) + '][restocked_quantity]'" x-model.number="row.restocked_quantity" placeholder="0">
                                                </td>
                                                <td class="num">
                                                    <input type="number" step="0.001" min="0" class="rh-inv-count-input" :name="'entries[' + (group.startIndex + i) + '][counted_quantity]'" x-model.number="row.counted_quantity" required>
                                                </td>
                                                <td class="num">
                                                    <span class="rh-inv-count-consume" :class="rowConsumeClass(row)" x-text="rowConsumeLabel(row)"></span>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </template>
                                <template x-if="countDraft.ingredients.length === 0">
                                    <tbody>
                                        <tr>
                                            <td colspan="5" style="text-align: center; padding: 2rem; color: var(--rh-text-muted);" x-text="countEmptyStateMessage()"></td>
                                        </tr>
                                    </tbody>
                                </template>
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
                    <button type="submit" class="rm-btn rm-btn--primary" :disabled="submitting || countLoading || ! countDraft.branch_id || countDraft.ingredients.length === 0">Save count</button>
                </div>
            </div>
        </form>
    </div>
</template>
