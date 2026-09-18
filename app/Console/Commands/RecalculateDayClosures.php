<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DayClosure;
use App\Services\DayClosureRecalculator;
use Illuminate\Console\Command;

/**
 * Realigns closures whose stored figures no longer match the rows they came from.
 *
 * Needed once because the Expenses page never blocked edits to a closed day and nothing
 * refreshed the closure afterwards. Kept afterwards as an audit: a clean run is proof
 * that every closure still agrees with its own data.
 */
class RecalculateDayClosures extends Command
{
    protected $signature = 'closures:recalculate
                            {--apply : Write the corrections. Without this the command only reports.}
                            {--branch= : Limit to one branch id}
                            {--date=* : Limit to specific closed_at_date values (repeatable)}';

    protected $description = 'Report (or fix) day closures whose stored totals disagree with their sales and expenses';

    public function handle(DayClosureRecalculator $recalculator): int
    {
        $query = DayClosure::query()->orderBy('closed_at_date');

        if ($this->option('branch')) {
            $query->where('branch_id', (int) $this->option('branch'));
        }

        // Targeted runs matter: a drifted day is not automatically a day that SHOULD be
        // recomputed. Backdating cash that never passed through the till makes the live
        // figure the wrong one, and those days have to be excluded by hand.
        if ($dates = $this->option('date')) {
            $query->where(function ($q) use ($dates) {
                foreach ($dates as $date) {
                    $q->orWhereDate('closed_at_date', $date);
                }
            });
        }

        $closures = $query->get();
        $drifted = [];

        foreach ($closures as $closure) {
            $date = $closure->closed_at_date->format('Y-m-d');
            $live = $recalculator->totalsFor((int) $closure->branch_id, $date);

            $expected = (float) $closure->opening_float
                + $live['cash_sales_total']
                + $live['mixed_cash_total']
                - $live['cash_expenses_total'];

            $deltas = [
                'sales' => round($live['cash_sales_total'] - (float) $closure->cash_sales_total, 2),
                'mixed' => round($live['mixed_cash_total'] - (float) $closure->mixed_cash_total, 2),
                'gcash' => round($live['gcash_sales_total'] - (float) $closure->gcash_sales_total, 2),
                'expenses' => round($live['cash_expenses_total'] - (float) $closure->cash_expenses_total, 2),
            ];

            if (! array_filter($deltas)) {
                continue;
            }

            $drifted[] = [
                'closure' => $closure,
                'date' => $date,
                'deltas' => $deltas,
                'expected_before' => round((float) $closure->expected_cash, 2),
                'expected_after' => round($expected, 2),
                'variance_before' => round((float) $closure->variance, 2),
                'variance_after' => round((float) $closure->counted_cash - $expected, 2),
            ];
        }

        $this->info(sprintf('Checked %d closures. %d disagree with their rows.', $closures->count(), count($drifted)));

        if ($drifted === []) {
            return self::SUCCESS;
        }

        $this->table(
            ['Date', 'Br', 'Δ sales', 'Δ expenses', 'expected', '→', 'variance', '→'],
            array_map(fn (array $d) => [
                $d['date'],
                $d['closure']->branch_id,
                number_format($d['deltas']['sales'], 2),
                number_format($d['deltas']['expenses'], 2),
                number_format($d['expected_before'], 2),
                number_format($d['expected_after'], 2),
                number_format($d['variance_before'], 2),
                number_format($d['variance_after'], 2),
            ], $drifted)
        );

        if (! $this->option('apply')) {
            $this->warn('Dry run. Nothing was written. Re-run with --apply to correct these.');

            return self::SUCCESS;
        }

        foreach ($drifted as $d) {
            $recalculator->recalculate($d['closure']);
        }

        $this->info(sprintf('Corrected %d closures.', count($drifted)));

        return self::SUCCESS;
    }
}
