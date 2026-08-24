<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Inventory\DefaultIngredientCategories;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user-managed inventory category, mirroring expense_categories: a NULL
 * branch_id is a SHARED category every branch can see, and resolution is
 * always `where(branch_id = X)->orWhereNull(branch_id)`.
 *
 * `sort_order` is the PHYSICAL walk order (dry store → chiller → line →
 * packaging). Alphabetical cannot express it, which is the whole reason this
 * table exists: a stock count walks the shelves, not the dictionary.
 */
class IngredientCategory extends Model
{
    use HasFactory;

    /** New categories are spaced so one can be slotted between two others. */
    public const SORT_ORDER_STEP = 10;

    /** The unsignedSmallInteger ceiling; going past it is a driver-level error. */
    public const MAX_SORT_ORDER = 65535;

    protected $fillable = [
        'branch_id',
        'name',
        'slug',
        'sort_order',
        'is_active',
    ];

    /** `is_seeded` is deliberately NOT fillable: only the default seeder sets it. */
    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'is_seeded' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(Ingredient::class);
    }

    /** A shared category spans every branch and is not editable from one. */
    public function isShared(): bool
    {
        return $this->branch_id === null;
    }

    /**
     * The branch's own categories plus the shared ones. A null $branchId is
     * "every branch" and applies no filter at all — callers that must not span
     * branches (anything reached from the API) always pass a resolved id.
     */
    public function scopeForBranch(Builder $query, ?int $branchId): Builder
    {
        if ($branchId === null) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($branchId): void {
            $q->where('branch_id', $branchId)->orWhereNull('branch_id');
        });
    }

    /** The walk order. Total, so two categories sharing a sort_order stay stable. */
    public function scopeOrderedForWalk(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name')->orderBy('id');
    }

    /**
     * A new category lands at the END of the walk, never at position 0 —
     * silently jumping to the front of everybody's count is the one thing a
     * default must not do.
     *
     * The MAX spans the SHARED categories too: on a branch whose walk is made
     * of shared shelves at 10..50, a branch-only MAX would return 0 and put
     * the new category first, which is exactly the bug this method exists to
     * prevent. Clamped to the column ceiling so a long-lived branch cannot
     * eventually raise an out-of-range write.
     */
    public static function nextSortOrder(int $branchId): int
    {
        $max = (int) static::query()->forBranch($branchId)->max('sort_order');

        return min($max + self::SORT_ORDER_STEP, self::MAX_SORT_ORDER);
    }

    /**
     * Install the default walk on a branch that has none. Safe to call on any
     * branch at any time: it is a no-op once the branch owns a category.
     *
     * Branch creation should call this so a branch added after the categories
     * migration still gets shelves.
     */
    public static function seedDefaultsFor(int $branchId): bool
    {
        return DefaultIngredientCategories::seedBranch($branchId);
    }
}
