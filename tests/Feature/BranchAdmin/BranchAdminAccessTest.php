<?php

namespace Tests\Feature\BranchAdmin;

use App\Models\Branch;
use App\Models\DayClosure;
use App\Models\Expense;
use App\Models\Sale;
use App\Models\SpecialExpense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A branch admin runs one branch from the website: every tab the super admin has
 * except Branches (and Users), with everything locked to their own branch. They
 * never see another branch's records, cannot open one by its URL, and anything they
 * save lands on their branch whatever the form says.
 *
 * The super admin (the `owner` role) is unchanged: every branch, every tab.
 */
class BranchAdminAccessTest extends TestCase
{
    use RefreshDatabase;

    private Branch $mine;

    private Branch $other;

    private User $admin;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['owner', 'admin', 'cashier'] as $role) {
            Role::findOrCreate($role);
        }
        $this->mine = Branch::factory()->create(['name' => 'Mine Branch']);
        $this->other = Branch::factory()->create(['name' => 'Other Branch']);

        $this->admin = User::factory()->create(['branch_id' => $this->mine->id]);
        $this->admin->assignRole('admin');
        $this->superAdmin = User::factory()->create(['branch_id' => $this->mine->id]);
        $this->superAdmin->assignRole('owner');
    }

    /** @return array<string, array{string}> */
    public static function websiteTabs(): array
    {
        return [
            'dashboard' => ['/dashboard'], 'employees' => ['/employees'], 'menu' => ['/menu'],
            'attendance' => ['/attendance'], 'sales' => ['/sales'], 'expenses' => ['/expenses'],
            'special expenses' => ['/expenses/special'], 'payroll' => ['/payroll'], 'inventory' => ['/inventory'],
            'cash report' => ['/cash-report'], 'gcash report' => ['/gcash-report'],
        ];
    }

    #[DataProvider('websiteTabs')]
    public function test_a_branch_admin_opens_every_tab(string $url): void
    {
        $this->actingAs($this->admin)->get($url)->assertOk();
    }

    public function test_branches_stay_super_admin_only(): void
    {
        $this->actingAs($this->admin)->get('/branches')->assertForbidden();
        $this->actingAs($this->admin)->post('/branches', ['code' => 'x1', 'name' => 'X'])->assertForbidden();
    }

    public function test_an_admin_sees_only_their_branchs_records(): void
    {
        Sale::factory()->create(['branch_id' => $this->mine->id, 'order_number' => 'MINE-0001', 'status' => 'completed', 'sale_datetime' => now()]);
        Sale::factory()->create(['branch_id' => $this->other->id, 'order_number' => 'OTHER-0001', 'status' => 'completed', 'sale_datetime' => now()]);

        $this->actingAs($this->admin)->get('/sales')->assertSee('MINE-0001')->assertDontSee('OTHER-0001');
    }

    public function test_asking_for_another_branch_in_the_url_still_shows_their_own(): void
    {
        Expense::factory()->create(['branch_id' => $this->mine->id, 'description' => 'Mine ice', 'expense_date' => now()->toDateString()]);
        Expense::factory()->create(['branch_id' => $this->other->id, 'description' => 'Other ice', 'expense_date' => now()->toDateString()]);

        $this->actingAs($this->admin)->get('/expenses?branch_id='.$this->other->id)
            ->assertOk()->assertSee('Mine ice')->assertDontSee('Other ice');
    }

    public function test_an_admin_cannot_open_another_branchs_record(): void
    {
        $expense = Expense::factory()->create(['branch_id' => $this->other->id]);
        $sale = Sale::factory()->create(['branch_id' => $this->other->id]);
        $closure = DayClosure::create([
            'branch_id' => $this->other->id, 'closed_at_date' => now()->subDay()->toDateString(), 'closed_by_user_id' => $this->superAdmin->id,
            'closed_at' => now()->subDay(), 'opening_float' => 0, 'cash_sales_total' => 0, 'mixed_cash_total' => 0, 'gcash_sales_total' => 0,
            'cash_expenses_total' => 0, 'expected_cash' => 0, 'counted_cash' => 0, 'variance' => 0, 'order_count' => 0, 'expense_count' => 0,
        ]);

        $this->actingAs($this->admin)->getJson("/expenses/{$expense->id}/details")->assertNotFound();
        $this->actingAs($this->admin)->getJson("/sales/{$sale->id}/details")->assertNotFound();
        $this->actingAs($this->admin)->getJson("/day-close/{$closure->id}/edit")->assertNotFound();
        $this->actingAs($this->admin)->delete("/expenses/{$expense->id}")->assertNotFound();
        $this->assertNotNull($expense->fresh());
    }

    public function test_company_wide_overhead_is_not_an_admins(): void
    {
        $companyWide = SpecialExpense::factory()->companyWide()->create(['description' => 'Head office rent']);

        $this->actingAs($this->admin)->getJson("/expenses/special/{$companyWide->id}/details")->assertNotFound();
        $this->actingAs($this->admin)->get('/expenses/special')->assertOk()->assertDontSee('Head office rent');
    }

    public function test_what_an_admin_saves_lands_on_their_branch(): void
    {
        $this->actingAs($this->admin)->post('/expenses', [
            'branch_id' => $this->other->id,
            'expense_date' => now()->toDateString(),
            'description' => 'Paper towels',
            'amount' => 40,
            'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('expenses', ['description' => 'Paper towels', 'branch_id' => $this->mine->id]);
        $this->assertDatabaseMissing('expenses', ['description' => 'Paper towels', 'branch_id' => $this->other->id]);
    }

    public function test_an_admin_without_a_branch_is_told_so(): void
    {
        $this->admin->update(['branch_id' => null]);

        $this->actingAs($this->admin)->get('/sales')->assertForbidden()->assertSee('not assigned to a branch');
    }

    public function test_the_super_admin_still_sees_every_branch(): void
    {
        Sale::factory()->create(['branch_id' => $this->mine->id, 'order_number' => 'MINE-0001', 'status' => 'completed', 'sale_datetime' => now()]);
        Sale::factory()->create(['branch_id' => $this->other->id, 'order_number' => 'OTHER-0001', 'status' => 'completed', 'sale_datetime' => now()]);

        $this->actingAs($this->superAdmin)->get('/sales')->assertSee('MINE-0001')->assertSee('OTHER-0001');
        $this->actingAs($this->superAdmin)->get('/branches')->assertOk();
    }

    public function test_the_dashboard_counts_only_the_admins_branch(): void
    {
        Sale::factory()->create(['branch_id' => $this->mine->id, 'status' => 'completed', 'payment_method' => 'cash', 'grand_total' => 100, 'sale_datetime' => now()]);
        Sale::factory()->create(['branch_id' => $this->other->id, 'status' => 'completed', 'payment_method' => 'cash', 'grand_total' => 900, 'sale_datetime' => now()]);

        $this->assertEquals(100, $this->actingAs($this->admin)->get('/dashboard')->assertOk()->viewData('todaySales'));
        $this->assertEquals(1000, $this->actingAs($this->superAdmin)->get('/dashboard')->assertOk()->viewData('todaySales'));
    }

    public function test_the_dashboard_lists_only_the_admins_branch_closure_status(): void
    {
        $rows = $this->actingAs($this->admin)->get('/dashboard')->assertOk()->viewData('branchClosures');

        $this->assertSame([$this->mine->id], collect($rows)->pluck('branch_id')->all());
    }
}
