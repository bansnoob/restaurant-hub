<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Expense;
use App\Models\SpecialExpense;
use App\Models\SpecialExpenseCategory;
use App\Services\DayClosureRecalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Re-files daily expenses as special expenses.
 *
 * Wages, stock advances and the like are cash the business paid out, but not out of a
 * till — so charging them to a day's drawer reports a shortage the closing cashier
 * never caused. Six weekly salary rows entered on 2026-09-20 drove two already-closed
 * days to a negative expected_cash and a fake surplus of P7,415 and P10,834.
 *
 * Moving them is not just a reclassification: `special_expenses` is structurally
 * invisible to every daily figure, so a row here CANNOT leak back into a drawer, and
 * it carries no API surface, so a cashier cannot see or alter it.
 *
 * Dry run by default. --apply moves the rows in one transaction and recomputes every
 * day closure the rows were charged against.
 */
class MoveExpensesToSpecial extends Command
{
    protected $signature = 'expenses:move-to-special
                            {--id=* : Expense ids to move (repeatable, required)}
                            {--category= : Special expense category name; created if absent}
                            {--apply : Perform the move. Without this the command only reports.}';

    protected $description = 'Re-file daily expenses as special expenses and recompute the affected day closures';

    public function handle(DayClosureRecalculator $recalculator): int
    {
        $ids = array_map('intval', (array) $this->option('id'));
        if ($ids === []) {
            $this->error('No --id given. Nothing to move.');

            return self::FAILURE;
        }

        $expenses = Expense::whereIn('id', $ids)->orderBy('expense_date')->orderBy('id')->get();

        // Refuse a partial move rather than silently re-filing a subset: the caller named
        // specific rows, and a missing one means their list is wrong.
        $missing = array_diff($ids, $expenses->pluck('id')->map(fn ($id) => (int) $id)->all());
        if ($missing !== []) {
            $this->error('These expense ids do not exist: '.implode(', ', $missing));

            return self::FAILURE;
        }

        $categoryName = trim((string) $this->option('category'));

        $this->table(
            ['Expense', 'Date', 'Br', 'Description', 'Amount', 'Method', 'Paid from'],
            $expenses->map(fn (Expense $e) => [
                $e->id,
                $this->dateOf($e->expense_date),
                $e->branch_id,
                Str::limit((string) $e->description, 30),
                number_format((float) $e->amount, 2),
                $e->payment_method,
                $e->paid_from,
            ])->all()
        );

        $this->info(sprintf(
            'Moving %d expense(s), %s total, into special expenses%s.',
            $expenses->count(),
            number_format((float) $expenses->sum('amount'), 2),
            $categoryName !== '' ? ' under "'.$categoryName.'"' : ' with no category'
        ));

        // The days these rows are currently charged against — each needs recomputing once
        // the rows are gone, or its expected_cash keeps the deduction forever.
        $affected = $expenses
            ->map(fn (Expense $e) => ['branch_id' => (int) $e->branch_id, 'date' => $this->dateOf($e->expense_date)])
            ->unique(fn (array $d) => $d['branch_id'].'|'.$d['date'])
            ->values();

        $this->newLine();
        $this->line('Day closures that will be recomputed:');
        $preview = [];
        foreach ($affected as $day) {
            $closure = $recalculator->closureFor($day['branch_id'], $day['date']);
            if (! $closure) {
                $preview[] = [$day['date'], $day['branch_id'], '—', '—', 'not closed'];

                continue;
            }

            $moved = (float) $expenses
                ->where('branch_id', $day['branch_id'])
                ->filter(fn (Expense $e) => $this->dateOf($e->expense_date) === $day['date'])
                ->where('payment_method', 'cash')
                ->where('paid_from', 'drawer')
                ->sum('amount');

            $newExpected = (float) $closure->expected_cash + $moved;
            $preview[] = [
                $day['date'],
                $day['branch_id'],
                number_format((float) $closure->expected_cash, 2).' → '.number_format($newExpected, 2),
                number_format((float) $closure->variance, 2).' → '.number_format((float) $closure->counted_cash - $newExpected, 2),
                '',
            ];
        }
        $this->table(['Date', 'Br', 'expected', 'variance', 'note'], $preview);

        if (! $this->option('apply')) {
            $this->warn('Dry run. Nothing was written. Re-run with --apply to perform the move.');

            return self::SUCCESS;
        }

        $categoryId = $categoryName !== '' ? $this->resolveCategoryId($categoryName) : null;

        DB::transaction(function () use ($expenses, $categoryId) {
            foreach ($expenses as $expense) {
                SpecialExpense::create([
                    'branch_id' => $expense->branch_id,
                    'special_expense_category_id' => $categoryId,
                    'recorded_by_user_id' => $expense->recorded_by_user_id,
                    // Normalised to the 1st, matching SpecialExpenseController — a row that
                    // stored a mid-month period_month would sort and group differently from
                    // every row entered through the UI. paid_date carries the real settlement
                    // day, and the pay period itself lives in the description text.
                    'period_month' => Carbon::parse($this->dateOf($expense->expense_date))
                        ->startOfMonth()->toDateString(),
                    'paid_date' => $this->dateOf($expense->expense_date),
                    'description' => $expense->description,
                    'vendor_name' => $expense->vendor_name,
                    'reference_no' => $expense->reference_no,
                    'amount' => $expense->amount,
                    'payment_method' => $expense->payment_method,
                    'notes' => $expense->notes,
                ]);

                $expense->delete();
            }
        });

        foreach ($affected as $day) {
            $recalculator->recalculateFor($day['branch_id'], $day['date']);
        }

        $this->info(sprintf('Moved %d expense(s). Recomputed %d day closure(s).', $expenses->count(), $affected->count()));

        return self::SUCCESS;
    }

    private function resolveCategoryId(string $name): int
    {
        // Mirrors SpecialExpenseController::resolveCategoryId, including its guard against
        // Str::slug() returning '' and filing unrelated names under one shared row.
        $slug = Str::slug($name);
        if ($slug === '') {
            $slug = 'cat-'.substr(md5($name), 0, 12);
        }

        return (int) SpecialExpenseCategory::firstOrCreate(
            ['slug' => $slug],
            ['name' => $name, 'is_active' => true]
        )->id;
    }

    private function dateOf(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return substr((string) $value, 0, 10);
    }
}
