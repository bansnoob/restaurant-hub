<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\Sale;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The test schema (SQLite) must agree with production (MySQL) on the payment_method enums,
 * otherwise GCash rows are either impossible to insert or wrongly permitted, and every
 * money assertion built on top of them is meaningless.
 */
class PaymentMethodSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function checkConstraintFor(string $table): string
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('CHECK-constraint introspection here is SQLite-specific.');
        }

        return (string) DB::selectOne(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$table]
        )->sql;
    }

    public function test_sales_payment_method_accepts_the_production_values(): void
    {
        $branch = Branch::factory()->create();

        foreach (['cash', 'gcash', 'mixed', 'unpaid'] as $method) {
            $sale = Sale::factory()->create(['branch_id' => $branch->id, 'payment_method' => $method]);
            $this->assertSame($method, $sale->fresh()->payment_method);
        }
    }

    public function test_expenses_payment_method_accepts_gcash(): void
    {
        $expense = Expense::factory()->gcash()->create();

        $this->assertSame('gcash', $expense->fresh()->payment_method);
    }

    public function test_retired_payment_methods_are_rejected(): void
    {
        $branch = Branch::factory()->create();

        foreach (['e_wallet', 'card'] as $retired) {
            try {
                Sale::factory()->create(['branch_id' => $branch->id, 'payment_method' => $retired]);
                $this->fail("sales.payment_method should reject the retired value '{$retired}'.");
            } catch (QueryException $e) {
                $this->assertTrue(true);
            }
        }
    }

    /**
     * Laravel rebuilds the table to change a column on SQLite and only re-emits CHECK
     * constraints for columns named in the closure. If the migration stops restating these,
     * they degrade to unconstrained varchars and typo'd statuses insert silently — invisible
     * to the GCash report, which filters on sales.status.
     */
    public function test_neighbouring_enum_constraints_survive_the_migration(): void
    {
        $salesSql = $this->checkConstraintFor('sales');
        $expensesSql = $this->checkConstraintFor('expenses');

        $this->assertStringContainsString("\"status\" in ('open', 'completed', 'voided', 'refunded')", $salesSql);
        $this->assertStringContainsString("\"order_type\" in ('dine_in', 'takeout', 'delivery')", $salesSql);
        $this->assertStringContainsString("\"status\" in ('draft', 'approved', 'voided')", $expensesSql);
    }

    public function test_an_invalid_sale_status_is_still_rejected(): void
    {
        $branch = Branch::factory()->create();

        $this->expectException(QueryException::class);
        Sale::factory()->create(['branch_id' => $branch->id, 'status' => 'complete']);
    }
}
