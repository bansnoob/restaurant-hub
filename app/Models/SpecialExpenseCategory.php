<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An overhead type — Rent, Electricity, Water, and whatever else the owner adds.
 *
 * Unlike ExpenseCategory these are global rather than per-branch: "Electricity"
 * means the same thing at every location, and a special expense may belong to no
 * branch at all, which leaves a per-branch taxonomy with nothing to hang on.
 */
class SpecialExpenseCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function specialExpenses(): HasMany
    {
        return $this->hasMany(SpecialExpense::class);
    }
}
