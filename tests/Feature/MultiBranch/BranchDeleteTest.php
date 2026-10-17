<?php

namespace Tests\Feature\MultiBranch;

use App\Models\Branch;
use App\Models\DayClosure;
use App\Models\Expense;
use App\Models\GcashWallet;
use App\Models\Ingredient;
use App\Models\IngredientCategory;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\PayrollRule;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Deleting a branch only checked employees, users and special expenses. Anything
 * else it had (sales, expenses, closures, counts...) is restricted at the database,
 * so the delete was a raw 500. And a freshly created branch's seeded inventory
 * categories are null-on-delete, so deleting it turned them into SHARED categories
 * that appeared in every other branch's count walk and could not be removed.
 *
 * Now: a branch with any real records is refused with a message naming them (and
 * pointing at deactivation), and an empty branch takes its own setup rows with it.
 */
class BranchDeleteTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Branch $keep;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        $this->owner = User::factory()->create();
        $this->owner->assignRole('owner');
        $this->keep = Branch::factory()->create(['name' => 'Main Branch']);
    }

    private function createBranch(): Branch
    {
        $this->actingAs($this->owner)->post('/branches', [
            'code' => 'b2',
            'name' => 'Second Branch',
            'is_active' => '1',
        ])->assertSessionHasNoErrors();

        return Branch::where('code', 'b2')->firstOrFail();
    }

    private function deleteBranch(Branch $branch)
    {
        return $this->actingAs($this->owner)->delete("/branches/{$branch->id}");
    }

    public function test_a_fresh_branch_is_deleted_with_its_seeded_categories(): void
    {
        $branch = $this->createBranch();
        $this->assertGreaterThan(0, IngredientCategory::where('branch_id', $branch->id)->count(), 'precondition: defaults seeded');
        $sharedBefore = IngredientCategory::whereNull('branch_id')->count();

        $this->deleteBranch($branch)->assertSessionHas('success');

        $this->assertNull(Branch::find($branch->id));
        $this->assertSame($sharedBefore, IngredientCategory::whereNull('branch_id')->count(), 'its categories became shared');
    }

    public function test_an_empty_branch_takes_its_setup_rows_with_it(): void
    {
        $branch = $this->createBranch();
        MenuCategory::factory()->create(['branch_id' => $branch->id]);
        PayrollRule::create(['branch_id' => $branch->id, 'standard_daily_hours' => 8]);
        GcashWallet::factory()->create(['branch_id' => $branch->id]);

        $this->deleteBranch($branch)->assertSessionHas('success');

        $this->assertSame(0, MenuCategory::whereNull('branch_id')->count(), 'its menu categories became orphans');
        $this->assertSame(0, PayrollRule::count());
        $this->assertSame(0, GcashWallet::count());
    }

    /** @return array<string, array{\Closure(Branch): void, string}> */
    public static function recordsThatBlock(): array
    {
        return [
            'sales' => [fn (Branch $b) => Sale::factory()->create(['branch_id' => $b->id]), 'sale'],
            'expenses' => [fn (Branch $b) => Expense::factory()->create(['branch_id' => $b->id]), 'expense'],
            'menu items' => [fn (Branch $b) => MenuItem::factory()->create(['branch_id' => $b->id]), 'menu item'],
            'ingredients' => [fn (Branch $b) => Ingredient::factory()->create(['branch_id' => $b->id]), 'ingredient'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('recordsThatBlock')]
    public function test_a_branch_with_records_is_refused_with_a_message(\Closure $make, string $label): void
    {
        $branch = $this->createBranch();
        $make($branch);

        $this->deleteBranch($branch)
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message) => str_contains($message, $label)
                && str_contains($message, 'Deactivate'));

        $this->assertNotNull(Branch::find($branch->id));
    }

    public function test_a_closed_day_blocks_the_delete_instead_of_a_500(): void
    {
        $branch = $this->createBranch();
        DayClosure::create([
            'branch_id' => $branch->id, 'closed_at_date' => now()->toDateString(), 'closed_by_user_id' => $this->owner->id,
            'closed_at' => now(), 'opening_float' => 0, 'cash_sales_total' => 0, 'mixed_cash_total' => 0,
            'gcash_sales_total' => 0, 'cash_expenses_total' => 0, 'expected_cash' => 0, 'counted_cash' => 0,
            'variance' => 0, 'order_count' => 0, 'expense_count' => 0,
        ]);

        $this->deleteBranch($branch)->assertSessionHas('error');

        $this->assertNotNull(Branch::find($branch->id));
        $this->assertGreaterThan(0, IngredientCategory::where('branch_id', $branch->id)->count(), 'a refused delete must leave the branch intact');
    }
}
