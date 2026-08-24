<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesBranch;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\RestockResultResource;
use App\Models\Ingredient;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Quick restock — log a received delivery against one ingredient without
 * walking the whole count list.
 */
class RestockController extends Controller
{
    use ResolvesBranch;

    public function __construct(
        private readonly InventoryService $inventory,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ingredient_id' => ['required', 'integer', 'exists:ingredients,id'],
            'quantity' => [
                'required', 'numeric', 'min:0.001',
                'max:'.InventoryService::MAX_QUANTITY,
                'decimal:0,'.InventoryService::QUANTITY_SCALE,
            ],
            'unit_cost' => [
                'nullable', 'numeric', 'min:0',
                'max:'.InventoryService::MAX_UNIT_COST,
                'decimal:0,'.InventoryService::COST_SCALE,
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $ingredient = Ingredient::findOrFail($validated['ingredient_id']);

        // branch_id comes from the INGREDIENT, never from the body.
        $this->authorizeBranchId(
            $request,
            (int) $ingredient->branch_id,
            'You cannot restock an ingredient from another branch.'
        );

        abort_if(! $ingredient->is_active, 422, 'This ingredient is inactive.');

        $movement = $this->inventory->recordRestock(
            $ingredient,
            (float) $validated['quantity'],
            isset($validated['unit_cost']) ? (float) $validated['unit_cost'] : null,
            $validated['notes'] ?? null,
            $request->user(),
        );

        return (new RestockResultResource(
            $movement,
            $this->inventory->viewIngredient($ingredient->fresh()),
        ))->response()->setStatusCode(201);
    }
}
