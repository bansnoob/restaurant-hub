<?php

namespace Tests\Feature\MultiBranch;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Ingredient;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\StockCount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The summary cards on Employees, Payroll and Inventory always counted the whole
 * company, whatever branch the page was filtered to. Filtering to the new, empty
 * branch still showed the old branch's headcount, payroll totals, item count and
 * "N below reorder level". They now follow the branch filter; with no filter they
 * still cover every branch.
 */
class StatsFollowBranchFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Branch $main;

    private Branch $second;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        $this->owner = User::factory()->create();
        $this->owner->assignRole('owner');

        $this->main = Branch::factory()->create();
        $this->second = Branch::factory()->create();
    }

    private function stats(string $url): array
    {
        return $this->actingAs($this->owner)->get($url)->assertOk()->viewData('stats');
    }

    // ── Employees ────────────────────────────────────────────────────────

    public function test_employee_cards_follow_the_branch_filter(): void
    {
        Employee::factory()->count(2)->create(['branch_id' => $this->main->id, 'is_active' => true, 'employment_type' => 'full_time']);
        Employee::factory()->create(['branch_id' => $this->second->id, 'is_active' => true, 'employment_type' => 'part_time']);

        $second = $this->stats('/employees?branch_id='.$this->second->id);
        $this->assertSame(1, $second['total']);
        $this->assertSame(1, $second['active']);
        $this->assertSame(0, $second['full_time']);
        $this->assertSame(1, $second['part_time']);

        $this->assertSame(3, $this->stats('/employees')['total']);
    }

    // ── Payroll ──────────────────────────────────────────────────────────

    private function draftEntry(Branch $branch, float $net): void
    {
        $period = PayrollPeriod::firstOrCreate(
            ['branch_id' => $branch->id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-07'],
            ['cutoff_label' => 'Oct 1 – Oct 7', 'status' => 'draft']
        );
        PayrollEntry::create([
            'payroll_period_id' => $period->id,
            'employee_id' => Employee::factory()->create(['branch_id' => $branch->id])->id,
            'gross_pay' => $net,
            'net_pay' => $net,
            'status' => 'draft',
        ]);
    }

    public function test_payroll_cards_follow_the_branch_filter(): void
    {
        $this->draftEntry($this->main, 1000);
        $this->draftEntry($this->main, 500);
        $this->draftEntry($this->second, 200);

        $second = $this->stats('/payroll?branch_id='.$this->second->id);
        $this->assertSame(1, $second['drafts']);
        $this->assertEquals(200, $second['pending_total']);

        $all = $this->stats('/payroll');
        $this->assertSame(3, $all['drafts']);
        $this->assertEquals(1700, $all['pending_total']);
    }

    // ── Inventory ────────────────────────────────────────────────────────

    public function test_inventory_cards_banner_and_recent_counts_follow_the_branch_filter(): void
    {
        Ingredient::factory()->count(2)->create(['branch_id' => $this->main->id, 'current_stock' => 1, 'reorder_level' => 10]);
        StockCount::factory()->create(['branch_id' => $this->main->id, 'recorded_by_user_id' => $this->owner->id]);

        $response = $this->actingAs($this->owner)->get('/inventory?branch_id='.$this->second->id)->assertOk();

        $this->assertSame(0, $response->viewData('stats')['total_items']);
        $this->assertSame(0, $response->viewData('stats')['low_stock_count']);
        $this->assertNull($response->viewData('stats')['last_count_at']);
        $this->assertCount(0, $response->viewData('lowStock'), 'the low-stock banner counted another branch');
        $this->assertCount(0, $response->viewData('recentCounts'));

        $all = $this->actingAs($this->owner)->get('/inventory')->assertOk();
        $this->assertSame(2, $all->viewData('stats')['low_stock_count']);
        $this->assertCount(2, $all->viewData('lowStock'));
        $this->assertCount(1, $all->viewData('recentCounts'));
    }

    public function test_the_low_stock_banner_matches_the_tile_by_skipping_inactive_items(): void
    {
        Ingredient::factory()->create(['branch_id' => $this->main->id, 'current_stock' => 1, 'reorder_level' => 10, 'is_active' => true]);
        Ingredient::factory()->create(['branch_id' => $this->main->id, 'current_stock' => 1, 'reorder_level' => 10, 'is_active' => false]);

        $response = $this->actingAs($this->owner)->get('/inventory')->assertOk();

        $this->assertSame(1, $response->viewData('stats')['low_stock_count']);
        $this->assertCount(1, $response->viewData('lowStock'));
    }
}
