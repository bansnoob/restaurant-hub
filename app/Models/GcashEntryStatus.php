<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Whether a GCash entry was actually seen on the wallet statement.
 *
 * Kept beside `sales`/`expenses` rather than on them, so reviewing money never rewrites the
 * underlying record. No row means the entry has not been reviewed — see self::PENDING.
 */
class GcashEntryStatus extends Model
{
    use HasFactory;

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    /** Not a stored value: the absence of a row. Only ever used in the UI and in queries. */
    public const PENDING = 'pending';

    public const TYPE_SALE = 'sale';

    public const TYPE_EXPENSE = 'expense';

    protected $fillable = [
        'entry_type',
        'entry_id',
        'status',
        'note',
        'reviewed_by_user_id',
        'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /**
     * Ids of entries of the given type that were declined — the entries that must not count
     * toward the wallet balance or the reported totals.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    public static function declinedIds(string $entryType): \Illuminate\Support\Collection
    {
        return static::query()
            ->where('entry_type', $entryType)
            ->where('status', self::DECLINED)
            ->pluck('entry_id');
    }
}
