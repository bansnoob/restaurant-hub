<?php

namespace Tests\Feature\MultiBranch;

use App\Models\Branch;
use App\Models\DayClosure;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Expense categories are one company-wide list, like Special Expense categories.
 * They used to belong to the branch they were first typed at: a new branch had
 * none on the iPad, the web forms offered every branch's, and the Edit Day dialog
 * (which only lists the expense's own branch plus shared ones) then quietly
 * cleared any other branch's category the next time a day was saved.
 */
class SharedExpenseCategoriesTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_17_120000_share_expense_categories_across_branches.php';

    private User $owner;

    private Branch $main;

    private Branch $second;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        Role::findOrCreate('cashier');

        $this->main = Branch::factory()->create();
        $this->second = Branch::factory()->create();
        $this->owner = User::factory()->create(['branch_id' => $this->main->id]);
        $this->owner->assignRole('owner');
    }

    private function category(?int $branchId, string $name): ExpenseCategory
    {
        return ExpenseCategory::create([
            'branch_id' => $branchId,
            'name' => $name,
            'slug' => Str::slug($name),
            'is_active' => true,
        ]);
    }

    private function runMigration(): void
    {
        (require base_path(self::MIGRATION))->up();
    }

    // ── The one-off migration ────────────────────────────────────────────

    public function test_the_migration_makes_every_category_shared(): void
    {
        $this->category($this->main->id, 'Supplies');
        $this->category($this->second->id, 'Ice');

        $this->runMigration();

        $this->assertSame(0, ExpenseCategory::whereNotNull('branch_id')->count());
        $this->assertSame(2, ExpenseCategory::count());
    }

    public function test_the_migration_merges_same_named_categories_and_keeps_their_expenses(): void
    {
        $mainSupplies = $this->category($this->main->id, 'Supplies');
        $secondSupplies = $this->category($this->second->id, 'Supplies');
        $kept = Expense::factory()->create(['branch_id' => $this->main->id, 'expense_category_id' => $mainSupplies->id]);
        $moved = Expense::factory()->create(['branch_id' => $this->second->id, 'expense_category_id' => $secondSupplies->id]);

        $this->runMigration();

        $this->assertSame(1, ExpenseCategory::where('slug', 'supplies')->count());
        $survivor = ExpenseCategory::where('slug', 'supplies')->firstOrFail();
        $this->assertNull($survivor->branch_id);
        $this->assertSame($survivor->id, $kept->fresh()->expense_category_id);
        $this->assertSame($survivor->id, $moved->fresh()->expense_category_id, 'an expense lost its category in the merge');
    }

    public function test_the_migration_keeps_a_merged_category_active_if_any_copy_was(): void
    {
        $this->category($this->main->id, 'Supplies')->update(['is_active' => false]);
        $this->category($this->second->id, 'Supplies');

        $this->runMigration();

        $this->assertTrue((bool) ExpenseCategory::where('slug', 'supplies')->value('is_active'));
    }

    // ── Creating ─────────────────────────────────────────────────────────

    public function test_a_category_typed_on_the_website_is_shared(): void
    {
        $this->actingAs($this->owner)->post('/expenses', [
            'branch_id' => $this->second->id,
            'expense_date' => now()->toDateString(),
            'description' => 'Ice',
            'amount' => 50,
            'payment_method' => 'cash',
            'new_category_name' => 'Ice Supply',
        ])->assertSessionHasNoErrors();

        $this->assertNull(ExpenseCategory::where('slug', 'ice-supply')->value('branch_id'));
    }

    public function test_a_category_typed_on_the_ipad_is_shared_and_reused(): void
    {
        $shared = $this->category(null, 'Ice Supply');
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/expenses', [
            'expense_date' => now()->toDateString(),
            'description' => 'Ice',
            'amount' => 50,
            'payment_method' => 'cash',
            'new_category_name' => 'Ice Supply',
        ])->assertCreated();

        $this->assertSame(1, ExpenseCategory::where('slug', 'ice-supply')->count());
        $this->assertSame($shared->id, Expense::firstOrFail()->expense_category_id);
    }

    // ── Listing ──────────────────────────────────────────────────────────

    public function test_a_new_branch_sees_the_shared_categories_on_the_ipad(): void
    {
        $this->category(null, 'Supplies');
        $cashier = User::factory()->create(['branch_id' => $this->second->id]);
        $cashier->assignRole('cashier');
        Sanctum::actingAs($cashier);

        $names = collect($this->getJson('/api/v1/expense-categories')->assertOk()->json('data'))->pluck('name');

        $this->assertContains('Supplies', $names);
    }

    // ── Saving ───────────────────────────────────────────────────────────

    public function test_another_branchs_own_category_is_refused_on_the_website(): void
    {
        $mainOnly = $this->category($this->main->id, 'Main Only');

        $this->actingAs($this->owner)->post('/expenses', [
            'branch_id' => $this->second->id,
            'expense_category_id' => $mainOnly->id,
            'expense_date' => now()->toDateString(),
            'description' => 'Ice',
            'amount' => 50,
            'payment_method' => 'cash',
        ])->assertSessionHasErrors('expense_category_id');
    }

    public function test_another_branchs_own_category_is_refused_on_the_ipad(): void
    {
        $mainOnly = $this->category($this->main->id, 'Main Only');
        $cashier = User::factory()->create(['branch_id' => $this->second->id]);
        $cashier->assignRole('cashier');
        Sanctum::actingAs($cashier);

        $this->postJson('/api/v1/expenses', [
            'expense_category_id' => $mainOnly->id,
            'expense_date' => now()->toDateString(),
            'description' => 'Ice',
            'amount' => 50,
            'payment_method' => 'cash',
        ])->assertJsonValidationErrors('expense_category_id');
    }

    public function test_edit_day_keeps_a_shared_category(): void
    {
        $shared = $this->category(null, 'Supplies');
        $date = now()->toDateString();
        $expense = Expense::factory()->create([
            'branch_id' => $this->second->id,
            'expense_category_id' => $shared->id,
            'expense_date' => $date,
        ]);
        $closure = DayClosure::create([
            'branch_id' => $this->second->id, 'closed_at_date' => $date, 'closed_by_user_id' => $this->owner->id,
            'closed_at' => now(), 'opening_float' => 0, 'cash_sales_total' => 0, 'mixed_cash_total' => 0,
            'gcash_sales_total' => 0, 'cash_expenses_total' => 0, 'expected_cash' => 0, 'counted_cash' => 0,
            'variance' => 0, 'order_count' => 0, 'expense_count' => 1,
        ]);

        $ids = collect($this->actingAs($this->owner)->getJson("/day-close/{$closure->id}/edit")->json('categories'))->pluck('id');

        $this->assertContains($shared->id, $ids);
        $this->assertSame($shared->id, $expense->fresh()->expense_category_id);
    }
}
