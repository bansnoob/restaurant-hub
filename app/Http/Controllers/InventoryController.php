<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InventoryController extends Controller
{
    public function index(Request $request): View
    {
        $branches = Branch::where('is_active', true)->orderBy('name')->get();

        $branchId = $request->integer('branch_id') ?: null;

        $ingredientsQuery = Ingredient::with('branch')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('name');

        $ingredients = $ingredientsQuery->get();
        $lowStock = $ingredients->filter(fn ($i) => $i->isLowStock());

        $movementsQuery = InventoryMovement::with(['ingredient', 'createdBy'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderByDesc('moved_at')
            ->orderByDesc('id');

        $movements = $movementsQuery->paginate(20)->withQueryString();

        return view('modules.inventory.index', compact(
            'branches',
            'ingredients',
            'lowStock',
            'movements',
            'branchId',
        ));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'branch_id'     => ['required', 'integer', 'exists:branches,id'],
            'name'          => ['required', 'string', 'max:120'],
            'sku'           => ['nullable', 'string', 'max:40'],
            'unit'          => ['required', 'in:g,kg,ml,l,pcs'],
            'current_stock' => ['required', 'numeric', 'min:0'],
            'reorder_level' => ['required', 'numeric', 'min:0'],
            'cost_per_unit' => ['required', 'numeric', 'min:0'],
        ]);

        Ingredient::create($validated + ['is_active' => true]);

        return back()->with('success', 'Ingredient added.');
    }

    public function update(Request $request, Ingredient $ingredient): RedirectResponse
    {
        $validated = $request->validate([
            'name'          => ['required', 'string', 'max:120'],
            'sku'           => ['nullable', 'string', 'max:40'],
            'unit'          => ['required', 'in:g,kg,ml,l,pcs'],
            'reorder_level' => ['required', 'numeric', 'min:0'],
            'cost_per_unit' => ['required', 'numeric', 'min:0'],
            'is_active'     => ['boolean'],
        ]);

        $ingredient->update($validated);

        return back()->with('success', 'Ingredient updated.');
    }

    public function destroy(Ingredient $ingredient): RedirectResponse
    {
        $ingredient->delete();

        return back()->with('success', 'Ingredient deleted.');
    }

    public function adjust(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ingredient_id' => ['required', 'integer', 'exists:ingredients,id'],
            'movement_type' => ['required', 'in:purchase,adjustment,waste,return'],
            'quantity'      => ['required', 'numeric', 'min:0.001'],
            'unit_cost'     => ['nullable', 'numeric', 'min:0'],
            'notes'         => ['nullable', 'string', 'max:500'],
        ]);

        $ingredient = Ingredient::findOrFail($validated['ingredient_id']);

        $direction = match ($validated['movement_type']) {
            'purchase', 'return', 'adjustment' => 'in',
            'waste'                             => 'out',
        };

        // For adjustment type, allow the user to pick direction via a signed quantity
        // We keep it simple: purchase/return = in, waste = out, adjustment needs direction field
        if ($validated['movement_type'] === 'adjustment') {
            $direction = $request->input('direction', 'in');
            abort_if(! in_array($direction, ['in', 'out']), 422, 'Invalid direction.');
        }

        InventoryMovement::create([
            'branch_id'           => $ingredient->branch_id,
            'ingredient_id'       => $ingredient->id,
            'direction'           => $direction,
            'movement_type'       => $validated['movement_type'],
            'quantity'            => $validated['quantity'],
            'unit_cost'           => $validated['unit_cost'] ?? null,
            'notes'               => $validated['notes'] ?? null,
            'moved_at'            => now(),
            'created_by_user_id'  => $request->user()->id,
        ]);

        $delta = $direction === 'in' ? $validated['quantity'] : -$validated['quantity'];
        $ingredient->increment('current_stock', $delta);

        return back()->with('success', 'Stock movement recorded.');
    }
}
