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
        // Separate from drift on purpose. Drift asks "do the rows still say this?".
        // This asks "does the closure agree with ITSELF?" — a stored expected_cash or
        // variance that does not follow from the closure's own stored components. The
        // drift loop cannot see that, because when the components match the live rows
        // it stops before ever checking the two figures derived from them.
        $inconsistent = [];

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

            $storedExpected = (float) $closure->opening_float
                + (float) $closure->cash_sales_total
                + (float) $closure->mixed_cash_total
                - (float) $closure->cash_expenses_total;

            $expectedOff = round($storedExpected - (float) $closure->expected_cash, 2);
            $varianceOff = round(
                ((float) $closure->counted_cash - (float) $closure->expected_cash) - (float) $closure->variance,
                2
            );

            if ($expectedOff !== 0.0 || $varianceOff !== 0.0) {
                $inconsistent[] = [
                    'closure' => $closure,
                    'date' => $date,
                    'expected_off' => $expectedOff,
                    'variance_off' => $varianceOff,
                ];
            }

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

        $this->info(sprintf(
            'Checked %d closures. %d disagree with their rows. %d disagree with themselves.',
            $closures->count(),
            count($drifted),
            count($inconsistent)
        ));

        if ($inconsistent !== []) {
            $this->warn('These closures do not follow from their own stored figures:');
            $this->table(
                ['Date', 'Br', 'expected is off by', 'variance is off by'],
                array_map(fn (array $i) => [
                    $i['date'],
                    $i['closure']->branch_id,
                    number_format($i['expected_off'], 2),
                    number_format($i['variance_off'], 2),
                ], $inconsistent)
            );
        }

        if ($drifted === [] && $inconsistent === []) {
            return self::SUCCESS;
        }

        if ($drifted === []) {
            if (! $this->option('apply')) {
                $this->warn('Dry run. Nothing was written. Re-run with --apply to correct these.');

                return self::SUCCESS;
            }

            foreach ($inconsistent as $i) {
                $recalculator->recalculate($i['closure']);
            }

            $this->info(sprintf('Corrected %d closures.', count($inconsistent)));

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

        $repair = collect($drifted)->pluck('closure')
            ->merge(collect($inconsistent)->pluck('closure'))
            ->unique('id');

        foreach ($repair as $closure) {
            $recalculator->recalculate($closure);
        }

        $this->info(sprintf('Corrected %d closures.', $repair->count()));

        return self::SUCCESS;
    }
}
