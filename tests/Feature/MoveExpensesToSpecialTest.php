<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DayClosure;
use App\Models\Expense;
use App\Models\Sale;
use App\Models\SpecialExpense;
use App\Models\SpecialExpenseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Re-filing a wage payment as a special expense is what takes it out of a day's drawer
 * for good: special_expenses is structurally invisible to every daily figure, so the
 * row cannot leak back the way a paid_from flag could be flipped by mistake.
 */
class MoveExpensesToSpecialTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $owner;

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        $this->branch = Branch::factory()->create();
        $this->owner = User::factory()->create();
        $this->owner->assignRole('owner');
        $this->date = now()->subDay()->toDateString();

        Sale::factory()->create([
            'branch_id' => $this->branch->id, 'status' => 'completed', 'payment_method' => 'cash',
            'sale_datetime' => $this->date.' 12:00:00', 'closed_at' => $this->date.' 12:00:00',
            'grand_total' => 4000,
        ]);
    }

    private function salary(float $amount, string $description): Expense
    {
        return Expense::factory()->create([
            'branch_id' => $this->branch->id, 'status' => 'approved', 'payment_method' => 'cash',
            'paid_from' => 'drawer', 'expense_date' => $this->date,
            'description' => $description, 'amount' => $amount,
            'recorded_by_user_id' => $this->owner->id,
        ]);
    }

    /** A closure that already absorbed the salaries — the production situation. */
    private function distortedClosure(float $salaries): DayClosure
    {
        return DayClosure::create([
            'branch_id' => $this->branch->id, 'closed_at_date' => $this->date,
            'closed_by_user_id' => $this->owner->id, 'closed_at' => $this->date.' 20:00:00',
            'opening_float' => 0, 'cash_sales_total' => 4000, 'mixed_cash_total' => 0,
            'gcash_sales_total' => 0, 'cash_expenses_total' => $salaries,
            'expected_cash' => 4000 - $salaries, 'counted_cash' => 4000,
            'variance' => $salaries, 'order_count' => 1, 'expense_count' => 2,
        ]);
    }

    public function test_a_dry_run_moves_nothing(): void
    {
        $a = $this->salary(2520, 'Mica');
        $this->distortedClosure(2520);

        $this->artisan('expenses:move-to-special --id='.$a->id.' --category="Weekly Salary"')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertDatabaseHas('expenses', ['id' => $a->id]);
        $this->assertSame(0, SpecialExpense::count());
    }

    public function test_apply_moves_the_rows_and_restores_the_day(): void
    {
        $a = $this->salary(2520, 'Mica (Sep.07-13, 2026)');
        $b = $this->salary(2500, 'Kein (Sep.07-13, 2026)');
        $closure = $this->distortedClosure(5020);

        $this->assertSame(-1020.0, round((float) $closure->expected_cash, 2));

        $this->artisan('expenses:move-to-special --id='.$a->id.' --id='.$b->id.' --category="Weekly Salary"'.' --apply')
            ->assertSuccessful();

        $this->assertDatabaseMissing('expenses', ['id' => $a->id]);
        $this->assertDatabaseMissing('expenses', ['id' => $b->id]);
        $this->assertSame(2, SpecialExpense::count());

        // The drawer no longer wears them.
        $fresh = $closure->fresh();
        $this->assertSame(0.0, round((float) $fresh->cash_expenses_total, 2));
        $this->assertSame(4000.0, round((float) $fresh->expected_cash, 2));
        $this->assertSame(0.0, round((float) $fresh->variance, 2));
    }

    public function test_the_moved_row_keeps_its_details(): void
    {
        $a = $this->salary(2520, 'Mica (Sep.07-13, 2026)');
        $this->distortedClosure(2520);

        $this->artisan('expenses:move-to-special --id='.$a->id.' --category="Weekly Salary" --apply')
            ->assertSuccessful();

        $moved = SpecialExpense::firstOrFail();
        $this->assertSame('Mica (Sep.07-13, 2026)', $moved->description);
        $this->assertSame(2520.0, round((float) $moved->amount, 2));
        $this->assertSame('cash', $moved->payment_method);
        $this->assertSame($this->branch->id, $moved->branch_id);
        $this->assertSame($this->date, $moved->paid_date?->toDateString());
        $this->assertSame($this->owner->id, $moved->recorded_by_user_id);
        $this->assertSame('Weekly Salary', $moved->category?->name);
    }

    /** period_month is stored as the 1st; paid_date carries the real day. */
    public function test_the_period_month_is_normalised_to_the_first(): void
    {
        $a = $this->salary(2520, 'Mica');
        $this->distortedClosure(2520);

        $this->artisan('expenses:move-to-special --id='.$a->id.' --apply')->assertSuccessful();

        $this->assertSame(
            now()->subDay()->startOfMonth()->toDateString(),
            SpecialExpense::firstOrFail()->period_month?->toDateString()
        );
    }

    public function test_an_existing_category_is_reused_not_duplicated(): void
    {
        SpecialExpenseCategory::create(['name' => 'Weekly Salary', 'slug' => 'weekly-salary', 'is_active' => true]);
        $a = $this->salary(2520, 'Mica');
        $this->distortedClosure(2520);

        $this->artisan('expenses:move-to-special --id='.$a->id.' --category="Weekly Salary" --apply')
            ->assertSuccessful();

        $this->assertSame(1, SpecialExpenseCategory::where('slug', 'weekly-salary')->count());
    }

    /** A wrong id means the caller's list is wrong — refuse rather than move a subset. */
    public function test_a_missing_id_aborts_the_whole_move(): void
    {
        $a = $this->salary(2520, 'Mica');
        $this->distortedClosure(2520);

        $this->artisan('expenses:move-to-special --id='.$a->id.' --id=999999 --apply')
            ->expectsOutputToContain('do not exist')
            ->assertFailed();

        $this->assertDatabaseHas('expenses', ['id' => $a->id]);
        $this->assertSame(0, SpecialExpense::count());
    }

    public function test_no_ids_is_refused(): void
    {
        $this->artisan('expenses:move-to-special --apply')
            ->expectsOutputToContain('No --id given')
            ->assertFailed();
    }

    /** Moving a row off an open day must not invent a closure. */
    public function test_an_open_day_is_left_alone(): void
    {
        $a = $this->salary(2520, 'Mica');

        $this->artisan('expenses:move-to-special --id='.$a->id.' --apply')->assertSuccessful();

        $this->assertSame(0, DayClosure::count());
        $this->assertSame(1, SpecialExpense::count());
    }

    /** Once moved, it is invisible to the drawer for good — a flag cannot be flipped back. */
    public function test_the_moved_row_never_returns_to_a_day_total(): void
    {
        $a = $this->salary(2520, 'Mica');
        $closure = $this->distortedClosure(2520);

        $this->artisan('expenses:move-to-special --id='.$a->id.' --category="Weekly Salary" --apply')
            ->assertSuccessful();

        // Recomputing again from scratch still sees nothing.
        $this->artisan('closures:recalculate --apply')->assertSuccessful();

        $this->assertSame(4000.0, round((float) $closure->fresh()->expected_cash, 2));
        $this->assertSame(0.0, round((float) $closure->fresh()->cash_expenses_total, 2));
    }
}
