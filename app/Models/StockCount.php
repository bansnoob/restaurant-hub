<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockCount extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'counted_at',
        'recorded_by_user_id',
        'notes',
        'total_value',
    ];

    protected $casts = [
        'counted_at' => 'date',
        'total_value' => 'decimal:2',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(StockCountEntry::class);
    }
}
