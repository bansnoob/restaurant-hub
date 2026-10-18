<?php

namespace Tests\Feature;

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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/**
 * No route answers with a 500, whatever it is sent.
 *
 * Every registered route (website and iPad API) is requested as the owner, and the
 * API also as a cashier, against a seeded two-branch database. Pages get no filters,
 * every real filter alone and combined with a branch, every preset, garbage values,
 * unreadable dates and array-shaped values. Writes get empty, garbage, array-shaped,
 * nested-garbage and plausible payloads. A refusal (validation, 403, 404, the
 * cashier redirect) is fine; an unhandled exception is a 500 and fails the test.
 *
 * This is how the Expenses page's branch filter (an ambiguous column after a join)
 * was found. Each request runs in its own rolled-back transaction.
 *
 * Adding a route with a new URL parameter? Map it in fillUri() or this test fails.
 */
class NoServerErrorsOnAnyRouteTest extends TestCase
{
    use RefreshDatabase;

    private const FIELDS = 'address adjustment_date amount assign_to_me base_price birthday branch_id cash cash_amount category_id clock_in_time clock_out_time closed_at_date code count counted_at counted_cash current_password current_stock daily_rate date date_from date_to description device_name email employee_code employee_id employment_type end_date expected_current_stock expense_category_id expense_date first_deduction_amount first_deduction_percent first_deduction_time first_deduction_type first_name gcash gcash_amount hire_date include_inactive ingredient_category_id ingredient_id is_active last_name limit low_only mixed name new_category_name note notes opening_balance opening_date opening_float order_type paid_date paid_from paid_total password password_confirmation payment_method payroll_entry_id period_month phone quantity reason record_id reference_no reorder_level required_clock_in_time restock_cursor sale_date search second_deduction_amount second_deduction_percent second_deduction_time second_deduction_type sku slug sort_order special_expense_category_id standard_daily_hours start_date status table_label third_deduction_amount third_deduction_percent third_deduction_time third_deduction_type unit unit_cost vendor_name work_date';

    private const LIST_FIELDS = 'items entries expenses categories employee_ids auto_clockout_attendance_ids deleted_expense_ids';

    private const QUERY_KEYS = 'branch_id category category_id expense_category_id special_expense_category_id ingredient_category_id payment_method search preset date date_from date_to month status active types unit low_only page employee_id limit include_inactive close_date close_branch_id work_date sort order_type';

    /** @var array<string, int|string> */
    private array $ids = [];

    private array $fails = [];

    private User $owner;

    private User $cashier;

    public function test_no_route_answers_with_a_500(): void
    {
        $this->seedEverything();
        $this->withoutExceptionHandling();
        // The inventory API is throttle:60,1; a 429 would mask everything behind it.
        $this->withoutMiddleware(ThrottleRequests::class);

        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->reject(fn ($r) => preg_match('#^(_ignition|sanctum|up$|storage|verify-email|email/|reset-password|forgot-password|confirm-password|login|logout|api/v1/auth/logout)#', $r->uri()))
            // Deletes last, so earlier routes still find their records (each request is rolled back anyway).
            ->sortBy(fn ($r) => in_array('DELETE', $r->methods()) ? 1 : 0);

        $count = 0;
        foreach ($routes as $route) {
            $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();
            $uri = $this->fillUri($route->uri());
            $isApi = str_starts_with($route->uri(), 'api/');
            $actors = $isApi ? ['owner' => $this->owner, 'cashier' => $this->cashier] : ['owner' => $this->owner];

            foreach ($actors as $who => $user) {
                foreach ($this->variants($method) as $label => [$query, $body]) {
                    $count++;
                    $this->hit($method, $uri, $query, $body, $user, $isApi, "$who/$label");
                }
            }
        }

        $this->assertGreaterThan(2000, $count, 'the route sweep ran far fewer requests than expected');

        $grouped = collect($this->fails)->groupBy('where');
        $report = '';
        foreach ($grouped as $where => $hits) {
            $first = $hits->first();
            $report .= "\n* {$first['error']}\n  at {$where}\n  e.g. ".$hits->pluck('req')->unique()->take(3)->implode("\n       ")."\n";
        }
        $this->assertSame(0, $grouped->count(), "{$grouped->count()} place(s) answer with a 500 out of {$count} requests:\n".$report);
    }

    private function hit(string $method, string $uri, array $query, array $body, User $user, bool $isApi, string $label): void
    {
        $url = '/'.$uri.($query ? '?'.http_build_query($query) : '');
        DB::beginTransaction();
        try {
            if ($isApi) {
                Sanctum::actingAs($user);
                $this->json($method, $url, $body);
            } else {
                $this->actingAs($user)->call($method, $url, $body);
            }
        } catch (\Throwable $e) {
            if (! $this->expected($e)) {
                $frame = $this->appFrame($e);
                $this->fails[] = [
                    'where' => $frame,
                    'error' => class_basename($e).': '.mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 170),
                    'req' => "$method $url  [$label]",
                ];
            }
        } finally {
            DB::rollBack();
            app('auth')->forgetGuards();
        }
    }

    private function expected(\Throwable $e): bool
    {
        if ($e instanceof HttpExceptionInterface) {
            return $e->getStatusCode() < 500;
        }

        return $e instanceof ValidationException
            || $e instanceof AuthenticationException
            || $e instanceof AuthorizationException
            || $e instanceof ModelNotFoundException
            || $e instanceof UnauthorizedException
            || $e instanceof HttpResponseException;
    }

    private function appFrame(\Throwable $e): string
    {
        $root = $e;
        while ($root->getPrevious()) {
            $root = $root->getPrevious();
        }
        foreach (array_merge([['file' => $root->getFile(), 'line' => $root->getLine()]], $root->getTrace()) as $f) {
            $file = $f['file'] ?? '';
            if ($file && str_contains($file, base_path()) && ! str_contains($file, '/vendor/') && ! str_contains($file, '/storage/framework/')) {
                return str_replace(base_path().'/', '', $file).':'.($f['line'] ?? '?');
            }
        }

        return str_replace(base_path().'/', '', $root->getFile()).':'.$root->getLine();
    }

    /** @return array<string, array{0: array, 1: array}> */
    private function variants(string $method): array
    {
        $keys = explode(' ', self::QUERY_KEYS);
        if ($method === 'GET') {
            return [
                'plain' => [[], []],
                'garbage-query' => [array_fill_keys($keys, 'zz-garbage'), []],
                'bad-dates' => [['date' => '2026-13-45', 'date_from' => 'garbage', 'date_to' => '2026-02-31', 'month' => '2026-99', 'work_date' => 'x', 'close_date' => 'x'], []],
                'array-query' => [array_fill_keys($keys, ['x']), []],
                'huge-ids' => [['branch_id' => '99999999999999999999', 'page' => '-5', 'limit' => '99999999'], []],
            ] + $this->validFilterVariants();
        }

        $fields = explode(' ', self::FIELDS);
        $lists = explode(' ', self::LIST_FIELDS);

        return [
            'empty' => [[], []],
            'garbage' => [[], array_fill_keys($fields, 'zz-garbage') + array_fill_keys($lists, 'zz-garbage')],
            'array-shaped' => [[], array_fill_keys(array_merge($fields, $lists), ['x'])],
            'nested-garbage' => [[], array_fill_keys($lists, [['id' => 'x', 'menu_item_id' => 'x', 'ingredient_id' => 'x', 'quantity' => 'x', 'amount' => 'x', 'counted_quantity' => 'x', 'sort_order' => 'x', 'expense_category_id' => 'x', 'paid_from' => 'x', 'description' => ['x']]])],
            'plausible' => [[], $this->plausible()],
        ];
    }

    /** Real filter values, alone and combined: the kind of input that tripped the Expenses page. */
    private function validFilterVariants(): array
    {
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();
        $single = [
            'branch' => ['branch_id' => $this->ids['mainBranch']],
            'branch2' => ['branch_id' => $this->ids['branch']],
            'category' => ['category_id' => $this->ids['category'], 'category' => $this->ids['category'], 'expense_category_id' => $this->ids['expenseCategory'], 'ingredient_category_id' => $this->ids['ingredientCategory']],
            'dates' => ['date' => $today, 'date_from' => $monthStart, 'date_to' => $today, 'work_date' => $today, 'month' => now()->format('Y-m'), 'close_date' => $today],
            'reversed-dates' => ['date_from' => $today, 'date_to' => $monthStart],
            'statuses' => ['status' => 'draft', 'payment_method' => 'cash', 'active' => 'active', 'types' => ['full_time'], 'unit' => 'pcs', 'low_only' => '1', 'order_type' => 'dine_in'],
            'search' => ['search' => 'a'],
            'presets' => ['preset' => 'month'],
            'page2' => ['page' => '2'],
            'employee' => ['employee_id' => $this->ids['employee']],
            'close-branch' => ['close_date' => $today, 'close_branch_id' => $this->ids['branch']],
        ];
        $out = [];
        foreach ($single as $k => $q) {
            $out["valid-$k"] = [$q, []];
        }
        // Branch combined with each other filter, then everything at once.
        foreach ($single as $k => $q) {
            if (str_starts_with($k, 'branch')) {
                continue;
            }
            $out["valid-branch+$k"] = [['branch_id' => $this->ids['mainBranch']] + $q, []];
        }
        $out['valid-all'] = [array_merge(...array_values($single)), []];
        foreach (['today', 'yesterday', '7d', '30d', 'month', 'week', 'last_month'] as $preset) {
            $out["preset-$preset"] = [['preset' => $preset, 'branch_id' => $this->ids['mainBranch']], []];
        }

        return $out;
    }

    /** Mostly-valid values pointing at seeded records: reaches deeper than validation. */
    private function plausible(): array
    {
        $today = now()->toDateString();

        return [
            'branch_id' => $this->ids['branch'], 'employee_id' => $this->ids['employee'], 'record_id' => $this->ids['attendanceRecord'],
            'category_id' => $this->ids['category'], 'expense_category_id' => $this->ids['expenseCategory'],
            'special_expense_category_id' => null, 'ingredient_category_id' => $this->ids['ingredientCategory'],
            'name' => 'Probe Name', 'first_name' => 'Pro', 'last_name' => 'Be', 'code' => 'probe1', 'sku' => 'PROBE1',
            'description' => 'probe', 'amount' => 10, 'base_price' => 10, 'daily_rate' => 500, 'quantity' => 1, 'current_stock' => 5,
            'reorder_level' => 1, 'unit' => 'pcs', 'unit_cost' => 1, 'payment_method' => 'cash', 'paid_from' => 'drawer',
            'date' => $today, 'work_date' => $today, 'expense_date' => $today, 'closed_at_date' => $today, 'hire_date' => $today,
            'start_date' => now()->subDays(6)->toDateString(), 'end_date' => $today, 'period_month' => now()->startOfMonth()->toDateString(),
            'paid_date' => $today, 'adjustment_date' => $today, 'opening_date' => $today, 'counted_at' => $today, 'sale_date' => $today,
            'clock_in_time' => '09:00', 'clock_out_time' => '17:00', 'counted_cash' => 100, 'opening_float' => 0, 'opening_balance' => 0,
            'employment_type' => 'full_time', 'order_type' => 'dine_in', 'status' => 'verified', 'is_active' => 1, 'sort_order' => 1,
            'email' => 'probe@example.com', 'password' => 'secret-pass-123', 'password_confirmation' => 'secret-pass-123', 'device_name' => 'probe',
            'items' => [['menu_item_id' => $this->ids['item'], 'quantity' => 1]],
            'employee_ids' => [$this->ids['employee']],
            'entries' => [['ingredient_id' => $this->ids['ingredient'], 'counted_quantity' => 3]],
            'categories' => [['id' => $this->ids['ingredientCategory'], 'sort_order' => 1]],
            'expenses' => [['id' => $this->ids['expense'], 'amount' => 5, 'description' => 'x', 'paid_from' => 'drawer']],
            'required_clock_in_time' => '09:00', 'first_deduction_time' => '09:15', 'second_deduction_time' => '09:30', 'third_deduction_time' => '10:00',
            'first_deduction_type' => 'amount', 'second_deduction_type' => 'amount', 'third_deduction_type' => 'percent',
            'first_deduction_amount' => 40, 'second_deduction_amount' => 80, 'third_deduction_percent' => 50, 'standard_daily_hours' => 8,
            'cash_amount' => 10, 'gcash_amount' => 0, 'paid_total' => 10, 'reason' => 'probe', 'note' => 'probe', 'notes' => 'probe',
        ];
    }

    private function fillUri(string $uri): string
    {
        return preg_replace_callback('/\{(\w+)\??\}/', function ($m) use ($uri) {
            $name = $m[1];
            // gcash-report/entries/{type}/{id}
            if ($name === 'type') {
                return 'sale';
            }
            if ($name === 'id') {
                return (string) $this->ids['sale'];
            }
            $this->assertArrayHasKey($name, $this->ids, "route {$uri} has a URL parameter {{$name}} this test does not know how to fill");

            // Menu routes use {category}/{item}; inventory categories use {ingredientCategory}.
            return (string) $this->ids[$name];
        }, $uri);
    }

    private function seedEverything(): void
    {
        foreach (['owner', 'cashier'] as $role) {
            Role::findOrCreate($role);
        }
        $main = Branch::factory()->create(['name' => 'Main']);
        $second = Branch::factory()->create(['name' => 'Second']);
        IngredientCategory::seedDefaultsFor($main->id);
        IngredientCategory::seedDefaultsFor($second->id);

        $this->owner = User::factory()->create(['branch_id' => $main->id]);
        $this->owner->assignRole('owner');
        $this->cashier = User::factory()->create(['branch_id' => null]);
        $this->cashier->assignRole('cashier');

        $employee = Employee::factory()->create(['branch_id' => $main->id, 'daily_rate' => 500, 'user_id' => $this->cashier->id]);
        Employee::factory()->create(['branch_id' => $second->id, 'daily_rate' => 500]);
        $record = AttendanceRecord::factory()->create(['employee_id' => $employee->id, 'branch_id' => $main->id, 'work_date' => now()->toDateString(), 'clock_in_at' => now()->subHours(3), 'clock_out_at' => null]);

        $menuCat = MenuCategory::factory()->create(['branch_id' => $main->id]);
        $item = MenuItem::factory()->create(['branch_id' => $main->id, 'category_id' => $menuCat->id]);
        $sale = Sale::factory()->create(['branch_id' => $main->id, 'status' => 'open', 'payment_method' => 'gcash', 'sale_datetime' => now()]);
        SaleItem::factory()->create(['sale_id' => $sale->id, 'menu_item_id' => $item->id]);
        Sale::factory()->create(['branch_id' => $main->id, 'status' => 'completed', 'payment_method' => 'cash', 'sale_datetime' => now()]);

        $expCat = ExpenseCategory::create(['branch_id' => null, 'name' => 'Supplies', 'slug' => 'supplies', 'is_active' => true]);
        $expense = Expense::factory()->create(['branch_id' => $main->id, 'expense_category_id' => $expCat->id, 'expense_date' => now()->toDateString()]);
        $special = SpecialExpense::factory()->create(['branch_id' => $main->id, 'period_month' => now()->startOfMonth()->toDateString()]);

        $closure = DayClosure::create([
            'branch_id' => $main->id, 'closed_at_date' => now()->subDay()->toDateString(), 'closed_by_user_id' => $this->owner->id, 'closed_at' => now()->subDay(),
            'opening_float' => 0, 'cash_sales_total' => 0, 'mixed_cash_total' => 0, 'gcash_sales_total' => 0, 'cash_expenses_total' => 0,
            'expected_cash' => 0, 'counted_cash' => 0, 'variance' => 0, 'order_count' => 0, 'expense_count' => 0,
        ]);
        $period = PayrollPeriod::create(['branch_id' => $main->id, 'start_date' => now()->subDays(6)->toDateString(), 'end_date' => now()->toDateString(), 'cutoff_label' => 'probe', 'status' => 'draft']);
        $entry = PayrollEntry::create(['payroll_period_id' => $period->id, 'employee_id' => $employee->id, 'gross_pay' => 500, 'net_pay' => 500, 'status' => 'draft']);

        $ingCat = IngredientCategory::where('branch_id', $main->id)->firstOrFail();
        $ingredient = Ingredient::factory()->create(['branch_id' => $main->id, 'ingredient_category_id' => $ingCat->id]);
        $count = StockCount::factory()->create(['branch_id' => $main->id, 'recorded_by_user_id' => $this->owner->id]);
        GcashWallet::factory()->create(['branch_id' => $main->id]);
        $adjustment = GcashAdjustment::factory()->create(['branch_id' => $main->id]);

        $this->ids = [
            'branch' => $second->id, 'mainBranch' => $main->id, 'employee' => $employee->id, 'attendanceRecord' => $record->id,
            'category' => $menuCat->id, 'item' => $item->id, 'sale' => $sale->id, 'dayClosure' => $closure->id,
            'expense' => $expense->id, 'expenseCategory' => $expCat->id, 'specialExpense' => $special->id,
            'payrollPeriod' => $period->id, 'payrollEntry' => $entry->id, 'ingredient' => $ingredient->id,
            'ingredientCategory' => $ingCat->id, 'stockCount' => $count->id, 'gcashAdjustment' => $adjustment->id,
        ];
    }
}
