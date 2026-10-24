<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'phone',
        'email',
        'address',
        'is_active',
    ];

    /**
     * The branches $user may see: every one for the super admin, only their own
     * for a branch admin. Every branch list on the website (filters, form pickers,
     * page data) goes through this, so another branch's name never reaches a
     * branch admin's page.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        $lockedBranchId = $user?->lockedBranchId();

        return $lockedBranchId === null ? $query : $query->whereKey($lockedBranchId);
    }
}
