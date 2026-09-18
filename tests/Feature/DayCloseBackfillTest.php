<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DayClosure;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Close Day drawer was pinned to today: loadPreview() never sent a date, so the
 * server defaulted to now() and the form posted that straight back. A day missed at
 * the time could never be closed afterwards. The backend already accepted a date —
 * these tests hold that contract in place now that the UI exercises it.
 */
class DayCloseBackfillTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        Role::findOrCreate('cashier');

        $this->branch = Branch::factory()->create();
        $this->owner = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->owner->assignRole('owner');
    }

    private function sale(string $date, array $attributes = []): Sale
    {
        return Sale::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'status' => 'completed',
            'payment_method' => 'cash',
            'sale_datetime' => $date.' 12:00:00',
            'closed_at' => $date.' 12:00:00',
            'grand_total' => 100,
        ], $attributes));
    }

    public function test_preview_honours_a_past_date(): void
    {
        $date = now()->subDays(3)->toDateString();
        $this->sale($date, ['grand_total' => 640]);
        $this->sale(now()->toDateString(), ['grand_total' => 15]);

        $payload = $this->actingAs($this->owner)
            ->getJson(route('day-close.preview', ['branch_id' => $this->branch->id, 'date' => $date]))
            ->assertOk()
            ->json();

        $this->assertSame($date, $payload['date']);
        // Today's 15 must not leak into the backfilled day.
        $this->assertSame(640.0, round((float) $payload['totals']['cash_sales_total'], 2));
    }

    public function test_a_past_day_can_be_closed(): void
    {
        $date = now()->subDays(3)->toDateString();
        $this->sale($date, ['grand_total' => 640]);

        $this->actingAs($this->owner)
            ->post(route('day-close.store'), [
                'branch_id' => $this->branch->id,
                'closed_at_date' => $date,
                'counted_cash' => 635,
            ])
            ->assertRedirect();

        $closure = DayClosure::where('branch_id', $this->branch->id)
            ->whereDate('closed_at_date', $date)
            ->first();

        $this->assertNotNull($closure);
        $this->assertSame(640.0, round((float) $closure->expected_cash, 2));
        $this->assertSame(-5.0, round((float) $closure->variance, 2));
    }

    /** Once backfilled, the day stops being reported as a gap. */
    public function test_backfilling_removes_the_day_from_the_unclosed_list(): void
    {
        $date = now()->subDays(3)->toDateString();
        $this->sale($date, ['grand_total' => 640]);

        $this->actingAs($this->owner)
            ->get(route('day-closures.index'))
            ->assertSee('Not closed');

        $this->actingAs($this->owner)->post(route('day-close.store'), [
            'branch_id' => $this->branch->id,
            'closed_at_date' => $date,
            'counted_cash' => 640,
        ]);

        $this->actingAs($this->owner)
            ->get(route('day-closures.index'))
            ->assertOk()
            ->assertDontSee('Not closed');
    }

    /** Backfill must not become a way to double-close a day. */
    public function test_a_past_day_cannot_be_closed_twice(): void
    {
        $date = now()->subDays(3)->toDateString();
        $this->sale($date, ['grand_total' => 640]);

        $body = ['branch_id' => $this->branch->id, 'closed_at_date' => $date, 'counted_cash' => 640];

        $this->actingAs($this->owner)->post(route('day-close.store'), $body);
        $this->actingAs($this->owner)->post(route('day-close.store'), $body);

        $this->assertSame(1, DayClosure::where('branch_id', $this->branch->id)
            ->whereDate('closed_at_date', $date)->count());
    }

    /** A day that hasn't happened yet has no cash to count. */
    public function test_a_future_date_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->post(route('day-close.store'), [
                'branch_id' => $this->branch->id,
                'closed_at_date' => now()->addDay()->toDateString(),
                'counted_cash' => 100,
            ])
            ->assertSessionHasErrors('closed_at_date');

        $this->assertSame(0, DayClosure::count());
    }
}
