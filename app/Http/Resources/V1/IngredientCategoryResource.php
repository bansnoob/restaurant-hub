<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\IngredientCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One inventory category.
 *
 * Every key is always present — nullable, never omitted — so the mobile cache
 * row mapping stays total. `ingredient_count` is BRANCH-SCOPED by the query
 * that loaded it (see CategoryReader::listCategories); it falls back to 0 when
 * the caller did not ask for the count, never to a cross-branch total.
 *
 * `is_editable` is false for a SHARED (branch_id NULL) category: those are
 * managed centrally and every branch-scoped write against one is refused, so
 * the manager UI must render them read-only rather than offering three buttons
 * that can only fail.
 *
 * @property-read IngredientCategory $resource
 */
class IngredientCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $category = $this->resource;

        return [
            'id' => (int) $category->id,
            'branch_id' => $category->branch_id === null ? null : (int) $category->branch_id,
            'name' => $category->name,
            'slug' => $category->slug,
            'sort_order' => (int) $category->sort_order,
            'is_active' => (bool) $category->is_active,
            'ingredient_count' => (int) ($category->ingredients_count ?? 0),
            'is_editable' => ! $category->isShared(),
        ];
    }
}
