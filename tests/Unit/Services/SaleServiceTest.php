<?php

namespace Tests\Unit\Services;

use App\Models\Branch;
use App\Models\MenuItem;
use App\Models\Sale;
use App\Models\User;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleServiceTest extends TestCase
{
    use RefreshDatabase;

    private SaleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SaleService;
    }

    public function test_create_order_calculates_totals_correctly(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->create();
        $menuItem = MenuItem::factory()->create([
            'branch_id' => $branch->id,
            'base_price' => 100.00,
            'tax_rate' => 12.00,
        ]);

        $sale = $this->service->createOrder([
            'branch_id' => $branch->id,
            'order_type' => 'dine_in',
            'table_label' => 'T1',
            'notes' => null,
            'items' => [
                ['menu_item_id' => $menuItem->id, 'quantity' => 2],
            ],
        ], $cashier);

        $this->assertEquals('open', $sale->status);
        $this->assertEquals(200.00, (float) $sale->sub_total);
        $this->assertEquals(24.00, (float) $sale->tax_total);
        $this->assertEquals(224.00, (float) $sale->grand_total);
        $this->assertCount(1, $sale->saleItems);

        $saleItem = $sale->saleItems->first();
        $this->assertEquals(100.00, (float) $saleItem->unit_price);
        $this->assertEquals(2.0, (float) $saleItem->quantity);
        $this->assertEquals(224.00, (float) $saleItem->line_total);
    }

    public function test_create_order_with_discount(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->create();
        $menuItem = MenuItem::factory()->create([
            'branch_id' => $branch->id,
            'base_price' => 100.00,
            'tax_rate' => 12.00,
        ]);

        $sale = $this->service->createOrder([
            'branch_id' => $branch->id,
            'order_type' => 'takeout',
            'table_label' => null,
            'notes' => null,
            'items' => [
                ['menu_item_id' => $menuItem->id, 'quantity' => 1, 'discount_total' => 10.00],
            ],
        ], $cashier);

        // sub_total = 100, discount = 10, taxable = 90, tax = 90 * 0.12 = 10.80
        $this->assertEquals(100.00, (float) $sale->sub_total);
        $this->assertEquals(10.00, (float) $sale->discount_total);
        $this->assertEquals(10.80, (float) $sale->tax_total);
        $this->assertEquals(100.80, (float) $sale->grand_total);
    }

    public function test_process_payment_completes_order(): void
    {
        $sale = Sale::factory()->create([
            'status' => 'open',
            'grand_total' => 224.00,
        ]);

        $result = $this->service->processPayment($sale, 'cash', 250.00);

        $this->assertEquals('completed', $result->status);
        $this->assertEquals('cash', $result->payment_method);
        $this->assertEquals(250.00, (float) $result->paid_total);
        $this->assertEquals(26.00, (float) $result->change_total);
        $this->assertNotNull($result->closed_at);
    }

    public function test_process_payment_fails_on_non_open_order(): void
    {
        $sale = Sale::factory()->create(['status' => 'completed']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only open orders can be paid');

        $this->service->processPayment($sale, 'cash', 100.00);
    }

    public function test_void_sale_updates_status(): void
    {
        $sale = Sale::factory()->create(['status' => 'open']);

        $result = $this->service->voidSale($sale, 'Customer left');

        $this->assertEquals('voided', $result->status);
        $this->assertStringContainsString('Customer left', $result->notes);
        $this->assertNotNull($result->closed_at);
    }

    public function test_void_sale_fails_on_already_voided(): void
    {
        $sale = Sale::factory()->create(['status' => 'voided']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already voided or refunded');

        $this->service->voidSale($sale);
    }

    public function test_create_order_generates_unique_order_number(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->create();
        $menuItem = MenuItem::factory()->create([
            'branch_id' => $branch->id,
            'base_price' => 50.00,
            'tax_rate' => 0,
        ]);

        $sale1 = $this->service->createOrder([
            'branch_id' => $branch->id,
            'order_type' => 'dine_in',
            'table_label' => null,
            'notes' => null,
            'items' => [['menu_item_id' => $menuItem->id, 'quantity' => 1]],
        ], $cashier);

        $sale2 = $this->service->createOrder([
            'branch_id' => $branch->id,
            'order_type' => 'dine_in',
            'table_label' => null,
            'notes' => null,
            'items' => [['menu_item_id' => $menuItem->id, 'quantity' => 1]],
        ], $cashier);

        $this->assertNotEquals($sale1->order_number, $sale2->order_number);
    }
}
