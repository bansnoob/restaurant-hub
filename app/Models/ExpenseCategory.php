<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class ExpenseCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'name',
        'slug',
        'is_active',
    ];

    /**
     * Categories are company-wide (branch_id NULL). A typed-in name reuses the
     * shared category with that slug, so two branches never grow two "Supplies".
     */
    public static function findOrCreateShared(string $name): self
    {
        return self::firstOrCreate(
            ['branch_id' => null, 'slug' => Str::slug($name)],
            ['name' => $name, 'is_active' => true]
        );
    }

    /**
     * Shared categories, plus any older branch-specific one belonging to $branchId.
     */
    public function scopeAvailableTo(Builder $query, int $branchId): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId));
    }

    /**
     * Validation for an expense's category: it must be shared or belong to the
     * expense's own branch, never to another branch.
     */
    public static function availableToRule(int $branchId): Exists
    {
        return Rule::exists('expense_categories', 'id')
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId));
    }
}
