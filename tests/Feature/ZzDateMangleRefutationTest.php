<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Adversarial check of the "no date-mangling on edit" claim. */
class ZzDateMangleRefutationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        $this->branch = Branch::factory()->create();
        $this->owner = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->owner->assignRole('owner');
    }

    private function makeGcashOut(string $date = '2026-09-12'): Expense
    {
        return Expense::create([
            'branch_id' => $this->branch->id,
            'recorded_by_user_id' => $this->owner->id,
            'expense_date' => $date,
            'description' => 'GCash out',
            'amount' => 500.00,
            'payment_method' => 'gcash',
            'paid_from' => 'drawer',
            'status' => 'approved',
        ]);
    }

    /** Exactly what the gcash_report edit drawer posts. */
    public function test_gcash_drawer_edit_roundtrips_the_date_byte_for_byte(): void
    {
        $e = $this->makeGcashOut();

        // Payload built the way the blade does: Carbon::parse(...)->toDateString()
        $blade = \Carbon\Carbon::parse($e->expense_date)->toDateString();
        $this->assertSame('2026-09-12', $blade);

        $this->actingAs($this->owner)->put(route('expenses.update', $e), [
            'branch_id' => $this->branch->id,
            'expense_date' => $blade,          // x-model value, unchanged
            'description' => 'GCash out (edited)',
            'amount' => '500.00',
            'payment_method' => 'gcash',
            'expense_category_id' => '',
            'vendor_name' => '',
            'reference_no' => '',
            'notes' => '',
        ])->assertRedirect();

        $e->refresh();
        $this->assertSame('2026-09-12', \Carbon\Carbon::parse($e->expense_date)->toDateString());
        $this->assertSame(1, Expense::count(), 'edit must not insert a second row');
    }

    /** Same, but with the app clock parked in the window where a UTC slice would roll back. */
    public function test_no_shift_even_at_manila_early_morning(): void
    {
        $this->assertSame('Asia/Manila', config('app.timezone'));
        $this->travelTo(\Carbon\Carbon::parse('2026-09-21 01:30:00', 'Asia/Manila'));

        $e = $this->makeGcashOut();
        $this->actingAs($this->owner)->put(route('expenses.update', $e), [
            'branch_id' => $this->branch->id,
            'expense_date' => '2026-09-12',
            'description' => 'GCash out',
            'amount' => '500.00',
            'payment_method' => 'gcash',
        ])->assertRedirect();

        $this->assertSame('2026-09-12', \Carbon\Carbon::parse($e->refresh()->expense_date)->toDateString());
        $this->travelBack();
    }

    /** The /expenses detail->edit path feeds the model's raw value, with no cast in between. */
    public function test_expenses_page_payload_is_an_uncast_ymd_string(): void
    {
        $e = $this->makeGcashOut();
        $raw = $e->refresh()->getAttributes()['expense_date'];
        $this->assertIsString($raw, 'a date cast here would serialize with a timezone');
        $this->assertStringStartsWith('2026-09-12', $raw);
        $this->assertArrayNotHasKey('expense_date', $e->getCasts());

        // show() hands the same uncast value to the JSON detail endpoint.
        $json = $this->actingAs($this->owner)
            ->getJson(route('expenses.show', $e))->json('expense.expense_date');
        $this->assertStringStartsWith('2026-09-12', (string) $json);
    }
}
