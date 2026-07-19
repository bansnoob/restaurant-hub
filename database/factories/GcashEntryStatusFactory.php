<?php

namespace Database\Factories;

use App\Models\GcashEntryStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GcashEntryStatus>
 */
class GcashEntryStatusFactory extends Factory
{
    protected $model = GcashEntryStatus::class;

    public function definition(): array
    {
        return [
            'entry_type' => GcashEntryStatus::TYPE_SALE,
            'entry_id' => 1,
            'status' => GcashEntryStatus::ACCEPTED,
            'note' => null,
            'reviewed_by_user_id' => null,
            'reviewed_at' => now(),
        ];
    }

    public function declined(): static
    {
        return $this->state(fn () => ['status' => GcashEntryStatus::DECLINED]);
    }
}
