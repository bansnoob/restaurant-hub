<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Second half of the probe into: "the GCash review control cannot render twice BECAUSE
 * gcash_entry_statuses has a UNIQUE KEY on (entry_type, entry_id) and no duplicates exist."
 *
 * The sibling test shows duplicate verdict rows do NOT double the control. This one shows
 * the reverse: the control doubles with the UNIQUE KEY intact and the verdict table empty.
 */
class ZzGcashControlCountRefutationTest extends TestCase
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
        $this->owner = User::factory()->create();
        $this->owner->assignRole('owner');
    }

    private function controlCount(string $html): int
    {
        return substr_count($html, 'class="rh-gcash-review"');
    }

    public function test_control_doubles_with_the_unique_key_intact_and_no_verdict_rows(): void
    {
        $expense = Expense::factory()->gcash()->create([
            'branch_id' => $this->branch->id,
            'description' => 'GCash out',
            'amount' => 500,
            'expense_date' => now()->toDateString(),
            'status' => 'approved',
        ]);

        $html = $this->actingAs($this->owner)->get(route('gcash-report.index'))->getContent();
        $this->assertSame(1, $this->controlCount($html), 'baseline control count');

        // The edit dialog's payload, posted to the CREATE action -- what the Alpine form
        // falls back to whenever expense.mode is not exactly 'edit'.
        $this->actingAs($this->owner)->post(route('expenses.store'), [
            'branch_id' => $this->branch->id,
            'expense_date' => $expense->expense_date instanceof \DateTimeInterface
                ? $expense->expense_date->format('Y-m-d')
                : (string) $expense->expense_date,
            'description' => 'GCash out',
            'amount' => '500.00',
            'payment_method' => 'gcash',
        ])->assertRedirect();

        // The claim's two pieces of evidence still hold, exactly.
        $this->assertSame(0, DB::table('gcash_entry_statuses')->count(), 'verdict rows written');
        $this->assertSame(0, DB::table('gcash_entry_statuses')
            ->select('entry_type', 'entry_id')
            ->groupBy('entry_type', 'entry_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count(), 'duplicate verdict groups');

        $html = $this->actingAs($this->owner)->get(route('gcash-report.index'))->getContent();

        $this->assertSame(2, Expense::where('payment_method', 'gcash')->count(), 'gcash expense rows');
        $this->assertSame(2, $this->controlCount($html), 'controls after the edit landed as a create');
    }
}
