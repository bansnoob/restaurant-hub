<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ingredient extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'ingredient_category_id',
        'name',
        'sku',
        'unit',
        'current_stock',
        'reorder_level',
        'cost_per_unit',
        'is_active',
    ];

    protected $casts = [
        'current_stock' => 'decimal:3',
        'reorder_level' => 'decimal:3',
        'cost_per_unit' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(IngredientCategory::class, 'ingredient_category_id');
    }

    public function recipeItems(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function isLowStock(): bool
    {
        return $this->reorder_level > 0 && $this->current_stock <= $this->reorder_level;
    }

    /**
     * THE walk order — one definition, used by both InventoryService::listIngredients()
     * and buildCountSession(), so a browse list and the count of that same list
     * can never disagree about where an ingredient is.
     *
     * Uncategorised sorts LAST, and the leading CASE is what puts it there: a
     * bare `ORDER BY sort_order` sorts NULL FIRST on both MySQL and SQLite,
     * which would open every count with the loose ends nobody has filed yet.
     * Last is the right place — an uncategorised ingredient is an exception
     * (brand new, or its category was deleted) and the walk of known shelves
     * must not be interrupted by one. It is a trailing "anything else?"
     * section the counter clears before submitting.
     *
     * It is NEVER filtered out: the ordering is expressed with correlated
     * subqueries, not a join, so a missing category cannot drop a row from a
     * count. The subqueries are also what keep every existing where() in the
     * callers unambiguous (branch_id, is_active, unit, name, sku all exist on
     * ingredient_categories too or would need qualifying), with no
     * select('ingredients.*') to remember.
     */
    public function scopeOrderedForWalk(Builder $query): Builder
    {
        $categorySortOrder = IngredientCategory::query()
            ->select('sort_order')
            ->whereColumn('ingredient_categories.id', 'ingredients.ingredient_category_id');

        $categoryName = IngredientCategory::query()
            ->select('name')
            ->whereColumn('ingredient_categories.id', 'ingredients.ingredient_category_id');

        return $query
            ->orderByRaw('CASE WHEN ingredients.ingredient_category_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy($categorySortOrder)
            ->orderBy($categoryName)
            ->orderBy('ingredients.ingredient_category_id')
            ->orderBy('ingredients.name')
            ->orderBy('ingredients.id');
    }
}
