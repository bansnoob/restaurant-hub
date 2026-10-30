<?php

declare(strict_types=1);

use App\Services\DayClosureRecalculator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rewrites past split (cash + GCash) payments to store the cash the drawer kept.
 *
 * Until now the POS's cash handed over was stored as cash_amount, change included, so
 * a ₱450 bill paid with ₱200 GCash and a ₱500 note counted ₱500 of cash sales when
 * ₱250 went back as change. Every closed day carrying one of those has an Expected
 * that is too high and a Variance that shows a shortfall nobody had.
 *
 * Only rows that were overpaid change. A split whose GCash part exceeds the bill is
 * left alone: its cash kept would be negative, so it has no safe automatic reading.
 *
 * Data only, and reversible: paid_total still holds cash + GCash handed over, so the
 * old figure is always paid_total - gcash_amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $overpaid = DB::table('sales')
                ->where('payment_method', 'mixed')
                ->whereNotNull('cash_amount')
                ->whereNotNull('gcash_amount')
                ->whereRaw('cash_amount + gcash_amount > grand_total')
                ->whereRaw('gcash_amount <= grand_total');

            $days = (clone $overpaid)->get(['branch_id', 'sale_datetime']);

            $overpaid->update(['cash_amount' => DB::raw('grand_total - gcash_amount')]);

            $this->recalculateClosures($days);
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $rewritten = DB::table('sales')
                ->where('payment_method', 'mixed')
                ->whereNotNull('cash_amount')
                ->whereNotNull('gcash_amount')
                ->whereRaw('paid_total > grand_total')
                ->whereRaw('ABS(cash_amount - (grand_total - gcash_amount)) < 0.005');

            $days = (clone $rewritten)->get(['branch_id', 'sale_datetime']);

            $rewritten->update(['cash_amount' => DB::raw('paid_total - gcash_amount')]);

            $this->recalculateClosures($days);
        });
    }

    /**
     * Re-derive each closed day the rewrite touched. Counted cash is never changed.
     *
     * @param  \Illuminate\Support\Collection<int, \stdClass>  $sales
     */
    private function recalculateClosures($sales): void
    {
        $recalculator = app(DayClosureRecalculator::class);

        $sales
            ->map(fn ($sale) => ['branch_id' => (int) $sale->branch_id, 'date' => substr((string) $sale->sale_datetime, 0, 10)])
            ->unique(fn (array $day) => $day['branch_id'].'|'.$day['date'])
            ->each(fn (array $day) => $recalculator->recalculateFor($day['branch_id'], $day['date']));
    }
};
