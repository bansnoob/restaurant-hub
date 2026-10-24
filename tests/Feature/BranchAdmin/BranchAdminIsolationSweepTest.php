<?php

namespace Tests\Feature\BranchAdmin;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\DayClosure;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\GcashAdjustment;
use App\Models\GcashWallet;
use App\Models\Ingredient;
use App\Models\IngredientCategory;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SpecialExpense;
use App\Models\StockCount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A branch admin can neither see nor change another branch, on any website route.
 *
 * The other branch's records (and company-wide overhead) carry a marker in a
 * visible field. Every website route is requested as the admin, with no filters,
 * with the other branch named in the query and the form, and with the other
 * branch's record ids in the URL and the body. The test fails if:
 *
 *  - any response shows a marker (a leak), or
 *  - any request changes a single row of the other branch or of company-wide
 *    overhead (each request runs in a rolled-back transaction).
 *
 * Adding a route with a new URL parameter? Map it in otherIds() or this test fails.
 */
class BranchAdminIsolationSweepTest extends TestCase
{
    use RefreshDatabase;

    private const MARK = 'ZZOTHER';

    private const COMPANY_MARK = 'ZZCOMPANY';

    private Branch $mine;

    private Branch $other;

    private User $admin;

    /** @var array<string, int> */
    private array $otherIds = [];

    private array $leaks = [];

    private array $writes = [];

    public function test_a_branch_admin_never_sees_or_changes_another_branch(): void
    {
        $this->seedBothBranches();

        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->reject(fn ($r) => str_starts_with($r->uri(), 'api/'))
            ->reject(fn ($r) => preg_match('#^(_ignition|sanctum|up$|storage|verify-email|email/|reset-password|forgot-password|confirm-password|login|logout|register|stores/|$)#', $r->uri()));

        $count = 0;
        foreach ($routes as $route) {
            $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();
            $uri = '/'.$this->fillUri($route->uri());

            foreach ($this->payloads($method) as $label => [$query, $body]) {
                $count++;
                $this->hit($method, $uri, $query, $body, $label);
            }
        }

        $this->assertGreaterThan(100, $count, 'the sweep ran far fewer requests than expected');
        $this->assertSame([], array_values(array_unique($this->leaks)), "Another branch's data was shown to a branch admin:\n".implode("\n", array_unique($this->leaks)));
        $this->assertSame([], array_values(array_unique($this->writes)), "A branch admin changed another branch's data:\n".implode("\n", array_unique($this->writes)));
    }

    private function hit(string $method, string $uri, array $query, array $body, string $label): void
    {
        $url = $uri.($query ? '?'.http_build_query($query) : '');
        DB::beginTransaction();
        try {
            $before = $this->otherBranchSnapshot();
            $response = $this->actingAs($this->admin)->call($method, $url, $body, [], [], ['HTTP_ACCEPT' => $label === 'json' ? 'application/json' : 'text/html']);
            $content = (string) $response->getContent();

            foreach ([self::MARK, self::COMPANY_MARK] as $mark) {
                $offset = 0;
                while (($pos = strpos($content, $mark, $offset)) !== false) {
                    $around = preg_replace('/\s+/', ' ', strip_tags(substr($content, max(0, $pos - 60), strlen($mark) + 100)));
                    $this->leaks[] = "$method $uri shows $mark: \"…".trim($around)."…\"";
                    $offset = $pos + strlen($mark);
                }
            }
            if ($this->otherBranchSnapshot() !== $before) {
                $this->writes[] = "$method $uri [$label] -> HTTP {$response->getStatusCode()}";
            }
        } finally {
            DB::rollBack();
            app('auth')->forgetGuards();
        }
    }

    /** @return array<string, array{0: array, 1: array}> */
    private function payloads(string $method): array
    {
        $other = (string) $this->other->id;

        if ($method === 'GET') {
            return [
                'plain' => [[], []],
                'other-branch' => [['branch_id' => $other, 'close_branch_id' => $other, 'close_date' => now()->toDateString()], []],
                'json' => [['branch_id' => $other], []],
            ];
        }

        $today = now()->toDateString();

        return [
            // Everything a form could name, pointed at the other branch.
            'other-branch' => [[], [
                'branch_id' => $other,
                'employee_id' => $this->otherIds['employee'], 'employee_ids' => [$this->otherIds['employee']],
                'record_id' => $this->otherIds['attendanceRecord'], 'payroll_entry_id' => $this->otherIds['payrollEntry'],
                'expense_category_id' => $this->otherIds['expenseCategory'], 'category_id' => $this->otherIds['category'],
                'ingredient_category_id' => $this->otherIds['ingredientCategory'],
                'items' => [['menu_item_id' => $this->otherIds['item'], 'quantity' => 1]],
                'entries' => [['ingredient_id' => $this->otherIds['ingredient'], 'counted_quantity' => 1]],
                'expenses' => [['id' => $this->otherIds['expense'], 'description' => 'hijack', 'amount' => 1, 'paid_from' => 'drawer']],
                'deleted_expense_ids' => [$this->otherIds['expense']],
                'auto_clockout_attendance_ids' => [$this->otherIds['attendanceRecord']],
                'categories' => [['id' => $this->otherIds['ingredientCategory'], 'sort_order' => 1]],
                'name' => 'Hijack', 'first_name' => 'Hi', 'last_name' => 'Jack', 'description' => 'hijack', 'code' => 'hijack1', 'sku' => 'HIJACK',
                'amount' => 1, 'base_price' => 1, 'daily_rate' => 1, 'quantity' => 1, 'current_stock' => 1, 'reorder_level' => 1, 'unit' => 'pcs',
                'payment_method' => 'cash', 'paid_from' => 'drawer', 'status' => 'accepted', 'is_active' => 1, 'sort_order' => 1,
                'date' => $today, 'work_date' => $today, 'expense_date' => $today, 'closed_at_date' => $today, 'hire_date' => $today,
                'start_date' => now()->subDays(6)->toDateString(), 'end_date' => $today, 'period_month' => now()->startOfMonth()->toDateString(),
                'paid_date' => $today, 'adjustment_date' => $today, 'opening_date' => $today, 'opening_balance' => 1, 'counted_at' => $today, 'sale_date' => $today,
                'clock_in_time' => '06:00', 'clock_out_time' => '07:00', 'counted_cash' => 1, 'employment_type' => 'full_time',
                'required_clock_in_time' => '06:00', 'first_deduction_time' => '06:15', 'second_deduction_time' => '06:30', 'third_deduction_time' => '07:00',
                'first_deduction_type' => 'amount', 'second_deduction_type' => 'amount', 'third_deduction_type' => 'percent',
                'first_deduction_amount' => 1, 'second_deduction_amount' => 1, 'third_deduction_percent' => 1, 'standard_daily_hours' => 8,
                'email' => 'hijack@example.com', 'password' => 'secret-pass-123', 'password_confirmation' => 'secret-pass-123',
            ]],
        ];
    }

    private function fillUri(string $uri): string
    {
        return preg_replace_callback('/\{(\w+)\??\}/', function ($m) use ($uri) {
            if ($m[1] === 'type') {
                return 'expense'; // gcash-report/entries/{type}/{id}
            }
            if ($m[1] === 'id') {
                return (string) $this->otherIds['gcashExpense'];
            }
            $this->assertArrayHasKey($m[1], $this->otherIds, "route {$uri} has a URL parameter {{$m[1]}} this test does not know how to fill");

            return (string) $this->otherIds[$m[1]];
        }, $uri);
    }

    /** Every row the admin must not touch: the other branch's, and company-wide overhead. */
    private function otherBranchSnapshot(): string
    {
        $other = $this->other->id;
        $rows = [];
        foreach (['employees', 'attendance_records', 'sales', 'expenses', 'day_closures', 'payroll_periods', 'payroll_rules',
            'menu_categories', 'menu_items', 'ingredients', 'ingredient_categories', 'stock_counts', 'gcash_adjustments', 'gcash_wallets',
            'inventory_movements'] as $table) {
            $rows[$table] = DB::table($table)->where('branch_id', $other)->orderBy('id')->get()->toArray();
        }
        $rows['special_expenses'] = DB::table('special_expenses')->where(fn ($q) => $q->where('branch_id', $other)->orWhereNull('branch_id'))->orderBy('id')->get()->toArray();
        $rows['payroll_entries'] = DB::table('payroll_entries')->whereIn('payroll_period_id', DB::table('payroll_periods')->where('branch_id', $other)->pluck('id'))->orderBy('id')->get()->toArray();
        $rows['sale_items'] = DB::table('sale_items')->whereIn('sale_id', DB::table('sales')->where('branch_id', $other)->pluck('id'))->orderBy('id')->get()->toArray();
        $rows['gcash_entry_statuses'] = DB::table('gcash_entry_statuses')->orderBy('id')->get()->toArray();
        $rows['branch'] = DB::table('branches')->where('id', $other)->get()->toArray();
        $rows['other_users'] = DB::table('users')->where('id', '!=', $this->admin->id)->orderBy('id')->get(['id', 'name', 'email', 'branch_id', 'password'])->toArray();

        return md5(json_encode($rows));
    }

    private function seedBothBranches(): void
    {
        foreach (['owner', 'admin', 'cashier'] as $role) {
            Role::findOrCreate($role);
        }
        $this->mine = Branch::factory()->create(['name' => 'Mine Branch', 'code' => 'mine']);
        $this->other = Branch::factory()->create(['name' => 'Branch '.self::MARK, 'code' => 'zzother']);
        $this->admin = User::factory()->create(['branch_id' => $this->mine->id]);
        $this->admin->assignRole('admin');
        $owner = User::factory()->create(['name' => 'Owner', 'branch_id' => $this->other->id]);
        $owner->assignRole('owner');

        foreach ([$this->mine, $this->other] as $branch) {
            $mark = $branch->is($this->other) ? self::MARK : 'MINE';
            IngredientCategory::seedDefaultsFor($branch->id);

            $employee = Employee::factory()->create(['branch_id' => $branch->id, 'first_name' => $mark.'emp', 'last_name' => 'Staff', 'daily_rate' => 777, 'is_active' => true]);
            $record = AttendanceRecord::factory()->create(['employee_id' => $employee->id, 'branch_id' => $branch->id, 'work_date' => now()->toDateString(), 'clock_in_at' => now()->subHours(3), 'clock_out_at' => null, 'notes' => $mark.' shift']);
            $menuCat = MenuCategory::factory()->create(['branch_id' => $branch->id, 'name' => $mark.' Meals']);
            $item = MenuItem::factory()->create(['branch_id' => $branch->id, 'category_id' => $menuCat->id, 'name' => $mark.' Adobo']);
            $sale = Sale::factory()->create(['branch_id' => $branch->id, 'order_number' => $mark.'-0001', 'status' => 'completed', 'payment_method' => 'gcash', 'grand_total' => 250, 'gcash_amount' => 250, 'sale_datetime' => now()]);
            SaleItem::factory()->create(['sale_id' => $sale->id, 'menu_item_id' => $item->id, 'item_name' => $mark.' Adobo']);
            $expense = Expense::factory()->create(['branch_id' => $branch->id, 'description' => $mark.' ice', 'vendor_name' => $mark.' Vendor', 'payment_method' => 'cash', 'expense_date' => now()->toDateString()]);
            $gcashExpense = Expense::factory()->gcash()->create(['branch_id' => $branch->id, 'description' => $mark.' gcash out', 'expense_date' => now()->toDateString()]);
            $special = SpecialExpense::factory()->create(['branch_id' => $branch->id, 'description' => $mark.' rent', 'period_month' => now()->startOfMonth()->toDateString(), 'payment_method' => 'gcash']);
            $closure = DayClosure::create([
                'branch_id' => $branch->id, 'closed_at_date' => now()->subDay()->toDateString(), 'closed_by_user_id' => $owner->id, 'closed_at' => now()->subDay(),
                'opening_float' => 0, 'cash_sales_total' => 0, 'mixed_cash_total' => 0, 'gcash_sales_total' => 0, 'cash_expenses_total' => 0,
                'expected_cash' => 0, 'counted_cash' => 0, 'variance' => 0, 'order_count' => 0, 'expense_count' => 0, 'notes' => $mark.' closing note',
            ]);
            $period = PayrollPeriod::create(['branch_id' => $branch->id, 'start_date' => now()->subDays(6)->toDateString(), 'end_date' => now()->toDateString(), 'cutoff_label' => $mark.' week', 'status' => 'draft']);
            $entry = PayrollEntry::create(['payroll_period_id' => $period->id, 'employee_id' => $employee->id, 'gross_pay' => 777, 'net_pay' => 777, 'status' => 'draft', 'notes' => $mark.' pay']);
            $ingCat = IngredientCategory::where('branch_id', $branch->id)->orderBy('sort_order')->firstOrFail();
            $ingredient = Ingredient::factory()->create(['branch_id' => $branch->id, 'ingredient_category_id' => $ingCat->id, 'name' => $mark.' rice', 'current_stock' => 1, 'reorder_level' => 10]);
            $count = StockCount::factory()->create(['branch_id' => $branch->id, 'recorded_by_user_id' => $owner->id, 'notes' => $mark.' count']);
            GcashWallet::factory()->create(['branch_id' => $branch->id, 'opening_balance' => 0, 'opening_date' => now()->startOfMonth()->toDateString()]);
            $adjustment = GcashAdjustment::factory()->create(['branch_id' => $branch->id, 'reason' => $mark.' adjustment']);
            $expenseCategory = ExpenseCategory::create(['branch_id' => $branch->id, 'name' => $mark.' Category', 'slug' => strtolower($mark).'-category', 'is_active' => true]);

            if ($branch->is($this->other)) {
                $this->otherIds = [
                    'branch' => $branch->id, 'employee' => $employee->id, 'attendanceRecord' => $record->id,
                    'category' => $menuCat->id, 'item' => $item->id, 'sale' => $sale->id, 'dayClosure' => $closure->id,
                    'expense' => $expense->id, 'gcashExpense' => $gcashExpense->id, 'specialExpense' => $special->id,
                    'payrollPeriod' => $period->id, 'payrollEntry' => $entry->id, 'ingredient' => $ingredient->id,
                    'ingredientCategory' => $ingCat->id, 'stockCount' => $count->id, 'gcashAdjustment' => $adjustment->id,
                    'expenseCategory' => $expenseCategory->id, 'user' => $owner->id,
                ];
            }
        }

        SpecialExpense::factory()->companyWide()->create(['description' => self::COMPANY_MARK.' head office rent', 'period_month' => now()->startOfMonth()->toDateString(), 'payment_method' => 'gcash']);
    }
}
