<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Branch;
use App\Models\MenuItem;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Carbon;
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
                'order_type' => $data['order_type'] ?? 'dine_in',
                'status' => 'open',
                'payment_method' => 'unpaid',
                'notes' => $data['notes'] ?? null,
            ]);

            $subTotal = 0.0;
            $discountTotal = 0.0;

            foreach ($data['items'] as $item) {
                $menuItem = MenuItem::findOrFail($item['menu_item_id']);
                $quantity = (float) $item['quantity'];
                $unitPrice = (float) $menuItem->base_price;
                $itemDiscount = (float) ($item['discount_total'] ?? 0);
                $lineSubtotal = $unitPrice * $quantity;
                $lineTotal = round($lineSubtotal - $itemDiscount, 2);

                $sale->saleItems()->create([
                    'menu_item_id' => $menuItem->id,
                    'item_name' => $menuItem->name,
                    'unit_price' => $unitPrice,
                    'quantity' => $quantity,
                    'discount_total' => $itemDiscount,
                    'tax_total' => 0,
                    'line_total' => $lineTotal,
                    'notes' => $item['notes'] ?? null,
                ]);

                $subTotal += $lineSubtotal;
                $discountTotal += $itemDiscount;
            }

            $grandTotal = round($subTotal - $discountTotal, 2);

            $sale->update([
                'sub_total' => round($subTotal, 2),
                'discount_total' => round($discountTotal, 2),
                'tax_total' => 0,
                'grand_total' => $grandTotal,
            ]);

            return $sale->load('saleItems');
        });
    }

    public function processPayment(Sale $sale, string $paymentMethod, float $paidTotal, ?float $cashAmount = null, ?float $gcashAmount = null): Sale
    {
        if ($sale->status !== 'open') {
            throw new \RuntimeException('Only open orders can be paid.');
        }

        $change = round($paidTotal - (float) $sale->grand_total, 2);

        $sale->update([
            'paid_total' => round($paidTotal, 2),
            'change_total' => max(0, $change),
            'payment_method' => $paymentMethod,
            'cash_amount' => $cashAmount !== null ? round($cashAmount, 2) : null,
            'gcash_amount' => $gcashAmount !== null ? round($gcashAmount, 2) : null,
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

    /**
     * Build the next order number for a branch on a given day.
     *
     * The sequence is scoped to its own prefix. Other features (the GCash report's manual
     * records) write differently-prefixed rows to the same branch and day, and the lookup
     * takes the last row by id regardless of prefix — so without this filter the two share
     * one running sequence and POS numbers skip (…-0002 then …-0004) whenever a manual
     * record lands between two orders. That reads as a missing or voided order on a receipt.
     *
     * It does not risk a duplicate: the sequence always increments from the newest row, so
     * unique(branch_id, order_number) still holds either way. For POS-only data every
     * same-day row already shares this prefix, so the filter changes nothing.
     */
    public function generateOrderNumber(int $branchId, ?string $date = null, ?string $prefixOverride = null): string
    {
        $date = $date ?: now()->toDateString();
        $stamp = Carbon::parse($date)->format('Ymd');

        $prefix = $prefixOverride ?: (Branch::find($branchId)?->code ?: 'ORD');

        // branches.code is only validated as a string, so it may contain LIKE wildcards.
        // `!` as the escape character: MySQL and SQLite disagree on backslashes in literals.
        $pattern = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $prefix.'-'.$stamp.'-').'%';

        // Take the highest sequence in use, not the newest row. Order numbers can be
        // re-issued (the GCash report re-numbers a record moved to another branch or day), so
        // the newest row is not necessarily the highest-numbered one — and continuing from it
        // would re-issue a number already taken, which unique(branch_id, order_number) then
        // rejects, permanently wedging new records for that branch and day.
        $highest = Sale::where('branch_id', $branchId)
            ->whereDate('sale_datetime', $date)
            ->whereRaw("order_number LIKE ? ESCAPE '!'", [$pattern])
            ->pluck('order_number')
            ->map(fn ($number) => preg_match('/-(\d+)$/', (string) $number, $matches) ? (int) $matches[1] : 0)
            ->max();

        return sprintf('%s-%s-%04d', $prefix, $stamp, ((int) $highest) + 1);
    }
}
