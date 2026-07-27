<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesCategoryFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Branch $branch;

    private MenuCategory $ramen;

    private MenuCategory $drinks;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');

        $this->branch = Branch::factory()->create(['code' => 'MAIN', 'name' => 'Main']);
        $this->owner = User::factory()->create();
        $this->owner->assignRole('owner');

        $this->ramen = MenuCategory::create(['branch_id' => $this->branch->id, 'name' => 'Ramen', 'slug' => 'ramen', 'is_active' => true]);
        $this->drinks = MenuCategory::create(['branch_id' => $this->branch->id, 'name' => 'Drinks', 'slug' => 'drinks', 'is_active' => true]);
    }

    private function item(MenuCategory $category, ?Branch $branch = null): MenuItem
    {
        return MenuItem::factory()->create([
            'branch_id' => ($branch ?? $this->branch)->id,
            'category_id' => $category->id,
        ]);
    }

    /**
     * @param  array<int, array{cat: MenuCategory, amount: float}>  $lines
     */
    private function order(array $lines, array $attributes = []): Sale
    {
        $grand = array_sum(array_column($lines, 'amount'));
        $sale = Sale::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'status' => 'completed',
            'payment_method' => 'cash',
            'sale_datetime' => now(),
            'grand_total' => $grand,
        ], $attributes));

        foreach ($lines as $line) {
            SaleItem::create([
                'sale_id' => $sale->id,
                'menu_item_id' => $this->item($line['cat'])->id,
                'item_name' => $line['cat']->name.' item',
                'unit_price' => $line['amount'],
                'quantity' => 1,
                'discount_total' => $line['discount'] ?? 0,
                'tax_total' => 0,
                'line_total' => $line['amount'],
            ]);
        }

        return $sale;
    }

    private function load(array $query): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->owner)->get(route('sales.index', array_merge([
            'date_from' => now()->subDays(2)->toDateString(),
            'date_to' => now()->addDay()->toDateString(),
        ], $query)));
    }

    public function test_without_a_category_the_page_shows_whole_order_totals(): void
    {
        $this->order([['cat' => $this->ramen, 'amount' => 150], ['cat' => $this->drinks, 'amount' => 850]]);

        $summary = $this->load([])->assertOk()->viewData('summary');

        $this->assertSame(1, $summary['orders']);
        $this->assertSame(1000.0, $summary['gross_sales']);
    }

    public function test_the_category_dropdown_is_offered(): void
    {
        $this->load([])->assertOk()->assertSee('All categories')->assertSee('Ramen')->assertSee('Drinks');
    }

    public function test_filtering_lists_only_orders_that_contain_the_category(): void
    {
        $withRamen = $this->order([['cat' => $this->ramen, 'amount' => 150], ['cat' => $this->drinks, 'amount' => 850]]);
        $drinksOnly = $this->order([['cat' => $this->drinks, 'amount' => 300]]);

        $sales = $this->load(['category_id' => $this->ramen->id])->assertOk()->viewData('sales');

        $this->assertCount(1, $sales);
        $this->assertSame($withRamen->id, $sales->first()->id);
        $this->assertTrue($sales->doesntContain('id', $drinksOnly->id));
    }

    public function test_each_row_shows_only_the_category_slice_of_the_order(): void
    {
        $this->order([['cat' => $this->ramen, 'amount' => 150], ['cat' => $this->drinks, 'amount' => 850]]);

        $row = $this->load(['category_id' => $this->ramen->id])->viewData('sales')->first();

        $this->assertSame(150.0, (float) $row->category_line, 'Row total should be the Ramen portion, not the ₱1000 order.');
        $this->assertSame(1, (int) $row->category_items);
        $this->assertSame(2, (int) $row->sale_items_count);
    }

    public function test_summary_tiles_reflect_the_category_portion(): void
    {
        // Two orders that each include Ramen plus other categories.
        $this->order([['cat' => $this->ramen, 'amount' => 150], ['cat' => $this->drinks, 'amount' => 850]]);
        $this->order([['cat' => $this->ramen, 'amount' => 250], ['cat' => $this->drinks, 'amount' => 50]]);
        // A pure-Drinks order that must not count toward Ramen at all.
        $this->order([['cat' => $this->drinks, 'amount' => 999]]);

        $summary = $this->load(['category_id' => $this->ramen->id])->viewData('summary');

        $this->assertSame(2, $summary['orders']);
        $this->assertSame(400.0, $summary['gross_sales']);   // 150 + 250, not the order totals
        $this->assertSame(400.0, $summary['net_sales']);
        $this->assertSame(200.0, $summary['avg_order']);     // 400 / 2
    }

    public function test_row_totals_sum_to_the_gross_tile(): void
    {
        $this->order([['cat' => $this->ramen, 'amount' => 120], ['cat' => $this->drinks, 'amount' => 80]]);
        $this->order([['cat' => $this->ramen, 'amount' => 305]]);

        $response = $this->load(['category_id' => $this->ramen->id]);
        $rowSum = $response->viewData('sales')->sum(fn ($s) => (float) $s->category_line);

        $this->assertSame(425.0, round($rowSum, 2));
        $this->assertSame(425.0, round($response->viewData('summary')['gross_sales'], 2));
    }

    public function test_voided_and_refunded_category_portions_are_handled_like_whole_orders(): void
    {
        $this->order([['cat' => $this->ramen, 'amount' => 500]]);                                   // completed
        $this->order([['cat' => $this->ramen, 'amount' => 200]], ['status' => 'voided']);
        $this->order([['cat' => $this->ramen, 'amount' => 100]], ['status' => 'refunded']);

        $summary = $this->load(['category_id' => $this->ramen->id])->viewData('summary');

        $this->assertSame(500.0, $summary['gross_sales']);       // completed only
        $this->assertSame(1, $summary['orders']);
        $this->assertSame(200.0, $summary['voided_total']);
        $this->assertSame(100.0, $summary['refunded_total']);
        $this->assertSame(200.0, $summary['net_sales']);         // 500 - 200 - 100
    }

    public function test_a_mixed_payment_is_split_by_its_cash_to_total_ratio(): void
    {
        // ₱1000 order paid ₱400 cash / ₱600 gcash; Ramen slice is ₱200.
        $this->order(
            [['cat' => $this->ramen, 'amount' => 200], ['cat' => $this->drinks, 'amount' => 800]],
            ['payment_method' => 'mixed', 'cash_amount' => 400, 'gcash_amount' => 600]
        );

        $breakdown = $this->load(['category_id' => $this->ramen->id])->viewData('paymentBreakdown');

        // 200 * (400/1000) = 80 cash, 200 * (600/1000) = 120 gcash.
        $this->assertSame(80.0, round($breakdown['cash']['total'], 2));
        $this->assertSame(120.0, round($breakdown['gcash']['total'], 2));
    }

    public function test_cash_and_gcash_orders_attribute_the_whole_category_slice(): void
    {
        $this->order([['cat' => $this->ramen, 'amount' => 100]], ['payment_method' => 'cash']);
        $this->order([['cat' => $this->ramen, 'amount' => 300]], ['payment_method' => 'gcash']);

        $breakdown = $this->load(['category_id' => $this->ramen->id])->viewData('paymentBreakdown');

        $this->assertSame(100.0, round($breakdown['cash']['total'], 2));
        $this->assertSame(300.0, round($breakdown['gcash']['total'], 2));
    }

    public function test_item_discounts_reduce_the_category_line_total(): void
    {
        // line_total already nets the item discount; discounts tile still surfaces it.
        $this->order([['cat' => $this->ramen, 'amount' => 180, 'discount' => 20]]);

        $summary = $this->load(['category_id' => $this->ramen->id])->viewData('summary');

        $this->assertSame(180.0, $summary['gross_sales']);
        $this->assertSame(20.0, $summary['discounts']);
    }

    public function test_the_category_filter_combines_with_the_payment_filter(): void
    {
        $this->order([['cat' => $this->ramen, 'amount' => 100]], ['payment_method' => 'cash']);
        $gcashOrder = $this->order([['cat' => $this->ramen, 'amount' => 300]], ['payment_method' => 'gcash']);

        $sales = $this->load(['category_id' => $this->ramen->id, 'payment_method' => 'gcash'])->viewData('sales');

        $this->assertCount(1, $sales);
        $this->assertSame($gcashOrder->id, $sales->first()->id);
    }

    public function test_the_category_filter_combines_with_the_status_filter(): void
    {
        $this->order([['cat' => $this->ramen, 'amount' => 100]], ['status' => 'completed']);
        $voided = $this->order([['cat' => $this->ramen, 'amount' => 300]], ['status' => 'voided']);

        $sales = $this->load(['category_id' => $this->ramen->id, 'statuses' => ['voided']])->viewData('sales');

        $this->assertCount(1, $sales);
        $this->assertSame($voided->id, $sales->first()->id);
    }

    public function test_an_item_whose_menu_item_was_deleted_is_not_attributed_to_a_category(): void
    {
        $sale = $this->order([['cat' => $this->ramen, 'amount' => 150]]);
        // Simulate the menu item being deleted after the sale (nullOnDelete).
        SaleItem::create([
            'sale_id' => $sale->id,
            'menu_item_id' => null,
            'item_name' => 'Deleted item',
            'unit_price' => 500,
            'quantity' => 1,
            'discount_total' => 0,
            'tax_total' => 0,
            'line_total' => 500,
        ]);

        $summary = $this->load(['category_id' => $this->ramen->id])->viewData('summary');

        // Only the Ramen line counts; the orphaned 500 line has no category.
        $this->assertSame(150.0, $summary['gross_sales']);
    }

    public function test_a_foreign_or_invalid_category_id_is_ignored(): void
    {
        $otherBranch = Branch::factory()->create(['code' => 'OTHER']);
        $foreignCategory = MenuCategory::create(['branch_id' => $otherBranch->id, 'name' => 'Sushi', 'slug' => 'sushi', 'is_active' => true]);

        $this->order([['cat' => $this->ramen, 'amount' => 150], ['cat' => $this->drinks, 'amount' => 850]]);

        // Filtering the Main branch by another branch's category must fall back to no filter.
        $response = $this->load(['branch_id' => $this->branch->id, 'category_id' => $foreignCategory->id]);
        $this->assertNull($response->viewData('filters')['category_id']);
        $this->assertSame(1000.0, $response->viewData('summary')['gross_sales']);

        $this->assertNull($this->load(['category_id' => 999999])->viewData('filters')['category_id']);
    }

    public function test_the_category_dropdown_is_scoped_to_the_selected_branch(): void
    {
        $otherBranch = Branch::factory()->create(['code' => 'OTHER', 'name' => 'Other']);
        MenuCategory::create(['branch_id' => $otherBranch->id, 'name' => 'Sushi', 'slug' => 'sushi', 'is_active' => true]);
        // A shared category with no branch must always be offered.
        MenuCategory::create(['branch_id' => null, 'name' => 'Specials', 'slug' => 'specials', 'is_active' => true]);

        $categories = $this->load(['branch_id' => $this->branch->id])->viewData('categories');
        $names = $categories->pluck('name');

        $this->assertTrue($names->contains('Ramen'));
        $this->assertTrue($names->contains('Specials'));
        $this->assertFalse($names->contains('Sushi'), "Another branch's category must not be offered.");
    }

    public function test_inactive_categories_are_not_offered(): void
    {
        MenuCategory::create(['branch_id' => $this->branch->id, 'name' => 'Retired', 'slug' => 'retired', 'is_active' => false]);

        $names = $this->load([])->viewData('categories')->pluck('name');

        $this->assertFalse($names->contains('Retired'));
    }
}
