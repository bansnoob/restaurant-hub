<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Filtering the Expenses page by branch was a 500 every time: the category
 * breakdown joins expense_categories, which has its own branch_id, so the filter's
 * bare `branch_id` was ambiguous. It had been there since April but only showed
 * once there was a second branch to filter to.
 *
 * Also covered: a date or filter value the page could not read (a hand-edited
 * URL) is ignored rather than a 500.
 */
class ExpenseFiltersTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Branch $main;

    private Branch $second;

    private ExpenseCategory $supplies;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        $this->owner = User::factory()->create();
        $this->owner->assignRole('owner');

        $this->main = Branch::factory()->create();
        $this->second = Branch::factory()->create();
        $this->supplies = ExpenseCategory::create(['branch_id' => null, 'name' => 'Supplies', 'slug' => 'supplies', 'is_active' => true]);

        $today = now()->toDateString();
        Expense::factory()->create(['branch_id' => $this->main->id, 'expense_category_id' => $this->supplies->id, 'amount' => 100, 'expense_date' => $today]);
        Expense::factory()->create(['branch_id' => $this->main->id, 'expense_category_id' => null, 'amount' => 50, 'expense_date' => $today]);
        Expense::factory()->create(['branch_id' => $this->second->id, 'expense_category_id' => $this->supplies->id, 'amount' => 30, 'expense_date' => $today]);
    }

    private function page(string $query)
    {
        return $this->actingAs($this->owner)->get('/expenses'.$query)->assertOk();
    }

    public function test_filtering_by_branch_shows_that_branchs_expenses(): void
    {
        $response = $this->page('?branch_id='.$this->second->id);

        $this->assertEquals(30, $response->viewData('summary')['total']);
        $this->assertSame(1, $response->viewData('summary')['count']);
        $this->assertSame(1, $response->viewData('expenses')->total());
    }

    public function test_the_category_breakdown_follows_the_branch_filter(): void
    {
        $breakdown = collect($this->page('?branch_id='.$this->main->id)->viewData('categoryBreakdown'))->keyBy('name');

        $this->assertEquals(100, $breakdown['Supplies']['total']);
        $this->assertEquals(50, $breakdown['Uncategorized']['total']);
    }

    public function test_every_filter_together(): void
    {
        $response = $this->page('?branch_id='.$this->main->id.'&expense_category_id='.$this->supplies->id
            .'&payment_method=cash&search=&date_from='.now()->startOfMonth()->toDateString().'&date_to='.now()->toDateString());

        $this->assertSame(1, $response->viewData('expenses')->total());
    }

    public function test_an_unreadable_date_falls_back_to_this_month(): void
    {
        $filters = $this->page('?date_from=2026-13-45&date_to=garbage')->viewData('filters');

        $this->assertSame(now()->startOfMonth()->toDateString(), $filters['date_from']);
        $this->assertSame(now()->toDateString(), $filters['date_to']);
    }

    /** @return array<string, array{string}> */
    public static function arrayShapedFilters(): array
    {
        return [
            'branch' => ['?branch_id[]=1'],
            'category' => ['?expense_category_id[]=1'],
            'payment' => ['?payment_method[]=cash'],
            'search' => ['?search[]=x'],
            'preset' => ['?preset[]=today'],
            'date' => ['?date_from[]=2026-10-01'],
        ];
    }

    #[DataProvider('arrayShapedFilters')]
    public function test_an_array_shaped_filter_is_ignored(string $query): void
    {
        $this->assertEquals(180, $this->page($query)->viewData('summary')['total']);
    }
}
