<?php

declare(strict_types=1);

namespace App\Support\Inventory;

use App\Models\Ingredient;
use App\Services\InventoryService;
use Illuminate\Validation\Rule;

/**
 * The ONE definition of what a posted stock-count entry may contain.
 *
 * Both count surfaces validate through this object — the owner-facing web
 * module (App\Http\Controllers\InventoryController) and the mobile API
 * (App\Http\Controllers\Api\V1\StockCountController) — because the rule that
 * matters here is a security boundary, not a formatting preference: an entry
 * whose ingredient belongs to another branch would be written under this
 * branch's count, claim that branch's movements and overwrite its stock.
 * Two hand-maintained copies of that rule drift; this one cannot.
 */
final class CountEntryRules
{
    /**
     * @param  list<int>  $allowedIngredientIds  the counted branch's ingredients
     */
    private function __construct(
        private readonly array $allowedIngredientIds,
    ) {}

    /**
     * Build the allow-list for one branch.
     *
     * One query for the whole list instead of an `exists` round trip per row.
     * `is_active` is deliberately NOT part of the constraint: an ingredient
     * deactivated mid-count must still record rather than 422 an hour of
     * counting.
     */
    public static function for(int $branchId): self
    {
        return new self(
            Ingredient::where('branch_id', $branchId)->pluck('id')->all()
        );
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'entries' => ['required', 'array', 'min:1', 'max:'.InventoryService::MAX_COUNT_ENTRIES],
            'entries.*.ingredient_id' => ['required', 'integer', 'distinct', Rule::in($this->allowedIngredientIds)],
            'entries.*.counted_quantity' => [
                'required', 'numeric', 'min:0',
                'max:'.InventoryService::MAX_QUANTITY,
                'decimal:0,'.InventoryService::QUANTITY_SCALE,
            ],
            // The operator's own delivery figure. The mobile client logs
            // deliveries as inventory_movements instead and never sends it.
            'entries.*.restocked_quantity' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:'.InventoryService::MAX_QUANTITY],
            // Accepted for wire compatibility with the web blade, then ignored:
            // InventoryService recomputes the baseline inside the transaction.
            'entries.*.previous_quantity' => ['sometimes', 'nullable', 'numeric'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'entries.*.ingredient_id.in' => 'One of the counted ingredients does not belong to the selected branch. Reopen the count for the right branch.',
            'entries.*.counted_quantity.decimal' => 'A counted quantity may have at most '.InventoryService::QUANTITY_SCALE.' decimal places.',
        ];
    }
}
