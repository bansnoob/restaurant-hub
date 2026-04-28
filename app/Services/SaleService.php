<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Branch;
use App\Models\MenuItem;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaleService
{
    /**
     * @param array{
     *   branch_id: int,
     *   order_type: string,
     *   table_label: ?string,
     *   notes: ?string,
     *   items: array<int, array{
     *     menu_item_id: int,
     *     quantity: float,
     *     discount_total?: float,
     *     notes?: ?string,
     *   }>
     * } $data
     */
    public function createOrder(array $data, User $cashier): Sale
    {
        return DB::transaction(function () use ($data, $cashier): Sale {
            $sale = Sale::create([
                'branch_id' => $data['branch_id'],
                'order_number' => $this->generateOrderNumber((int) $data['branch_id']),
                'sale_datetime' => now(),
                'cashier_user_id' => $cashier->id,
                'table_label' => $data['table_label'] ?? null,
                'order_type' => $data['order_type'],
                'status' => 'open',
                'payment_method' => 'unpaid',
                'notes' => $data['notes'] ?? null,
            ]);

            $subTotal = 0.0;
            $discountTotal = 0.0;
            $taxTotal = 0.0;

            foreach ($data['items'] as $item) {
                $menuItem = MenuItem::findOrFail($item['menu_item_id']);
                $quantity = (float) $item['quantity'];
                $unitPrice = (float) $menuItem->base_price;
                $itemDiscount = (float) ($item['discount_total'] ?? 0);
                $lineSubtotal = $unitPrice * $quantity;
                $itemTax = round(($lineSubtotal - $itemDiscount) * ((float) $menuItem->tax_rate / 100), 2);
                $lineTotal = round($lineSubtotal - $itemDiscount + $itemTax, 2);

                $sale->saleItems()->create([
                    'menu_item_id' => $menuItem->id,
                    'item_name' => $menuItem->name,
                    'unit_price' => $unitPrice,
                    'quantity' => $quantity,
                    'discount_total' => $itemDiscount,
                    'tax_total' => $itemTax,
                    'line_total' => $lineTotal,
                    'notes' => $item['notes'] ?? null,
                ]);

                $subTotal += $lineSubtotal;
                $discountTotal += $itemDiscount;
                $taxTotal += $itemTax;
            }

            $grandTotal = round($subTotal - $discountTotal + $taxTotal, 2);

            $sale->update([
                'sub_total' => round($subTotal, 2),
                'discount_total' => round($discountTotal, 2),
                'tax_total' => round($taxTotal, 2),
                'grand_total' => $grandTotal,
            ]);

            return $sale->load('saleItems');
        });
    }

    public function processPayment(Sale $sale, string $paymentMethod, float $paidTotal): Sale
    {
        if ($sale->status !== 'open') {
            throw new \RuntimeException('Only open orders can be paid.');
        }

        $change = round($paidTotal - (float) $sale->grand_total, 2);

        $sale->update([
            'paid_total' => round($paidTotal, 2),
            'change_total' => max(0, $change),
            'payment_method' => $paymentMethod,
            'status' => 'completed',
            'closed_at' => now(),
        ]);

        return $sale;
    }

    public function voidSale(Sale $sale, ?string $reason = null): Sale
    {
        if (in_array($sale->status, ['voided', 'refunded'], true)) {
            throw new \RuntimeException('This order is already voided or refunded.');
        }

        $notes = $sale->notes;
        if ($reason) {
            $notes = $notes ? "{$notes} | Void reason: {$reason}" : "Void reason: {$reason}";
        }

        $sale->update([
            'status' => 'voided',
            'notes' => $notes,
            'closed_at' => now(),
        ]);

        return $sale;
    }

    private function generateOrderNumber(int $branchId): string
    {
        $branch = Branch::find($branchId);
        $prefix = $branch ? $branch->code : 'ORD';
        $date = now()->format('Ymd');

        $lastOrder = Sale::where('branch_id', $branchId)
            ->whereDate('sale_datetime', now()->toDateString())
            ->orderByDesc('id')
            ->first();

        $sequence = 1;
        if ($lastOrder && preg_match('/-(\d+)$/', $lastOrder->order_number, $matches)) {
            $sequence = ((int) $matches[1]) + 1;
        }

        return sprintf('%s-%s-%04d', $prefix, $date, $sequence);
    }
}
