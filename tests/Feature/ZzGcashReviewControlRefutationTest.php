<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Probes the claim: "the review control cannot render twice BECAUSE gcash_entry_statuses
 * has a UNIQUE KEY on (entry_type, entry_id)".
 */
class ZzGcashReviewControlRefutationTest extends TestCase
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

    private function gcashExpense(): Expense
    {
        return Expense::factory()->gcash()->create([
            'branch_id' => $this->branch->id,
            'description' => 'GCash out',
            'amount' => 500,
            'expense_date' => now()->toDateString(),
            'status' => 'approved',
        ]);
    }

    public function test_one_control_per_expense_row(): void
    {
        $this->gcashExpense();

        $html = $this->actingAs($this->owner)->get(route('gcash-report.index'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'class="rh-gcash-review"'), 'controls rendered');
        $this->assertSame(1, substr_count($html, '>GCash out<'), 'expense description rows');
    }

    /**
     * The decisive test: remove the UNIQUE KEY the claim leans on, plant genuine duplicate
     * verdict rows, and see whether the control doubles.
     */
    public function test_duplicate_status_rows_do_not_double_the_control(): void
    {
        $expense = $this->gcashExpense();

        Schema::table('gcash_entry_statuses', function ($table) {
            $table->dropUnique(['entry_type', 'entry_id']);
        });

        foreach (['accepted', 'declined'] as $status) {
            DB::table('gcash_entry_statuses')->insert([
                'entry_type' => 'expense',
                'entry_id' => $expense->id,
                'status' => $status,
                'note' => null,
                'reviewed_by_user_id' => null,
                'reviewed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertSame(2, DB::table('gcash_entry_statuses')
            ->where('entry_id', $expense->id)->count(), 'duplicates really are present');

        $html = $this->actingAs($this->owner)->get(route('gcash-report.index'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'class="rh-gcash-review"'), 'controls rendered with duplicate verdicts');
        $this->assertSame(1, substr_count($html, '>GCash out<'), 'expense rows with duplicate verdicts');
    }
}
