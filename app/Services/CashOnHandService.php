<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Branch;
use App\Models\CashOpeningBalance;
use App\Models\DayClosure;
use App\Models\Expense;
use App\Models\SpecialExpense;

/**
 * Cash on Hand: what the business should be holding right now, outside the tills.
 *
 * Net Cash Taken describes a date range, so it can never be a balance — cash already
 * held before the range is not in it. This counts forward from an opening balance the
 * owner counted by hand:
 *
 *   opening balance
 *   + each closed day's counted cash minus its float (the takings handed over)
 *   - cash overhead and outside-paid expenses (cash that left without touching a till)
 *
 * Drawer expenses are deliberately absent: they left before the count, so they are
 * already missing from counted_cash. Unclosed days are absent too — until a day is
 * counted there is no figure for what it handed over.
 */
class CashOnHandService
{
    /**
     * Deliberately NOT filtered by the report's date range — a balance is a running
     * position. It follows the branch filter, because each branch holds its own cash.
     *
     * @return array{balance: float, opening_balance: float, opening_date: ?string,
     *               taken: float, paid_outside: float, company_wide_overhead: float,
     *               configured: bool, scope_count: int, unconfigured_count: int,
     *               per_branch: array<int, array{id: int, name: string, balance: float, configured: bool}>}
     */
    public function summary(?int $branchId): array
    {
        // Not filtered by is_active: deactivating a branch does not spend its cash.
        $branches = $branchId !== null
            ? Branch::where('id', $branchId)->get()
            : Branch::orderBy('name')->get();

        $openings = CashOpeningBalance::whereIn('branch_id', $branches->pluck('id'))->get()->keyBy('branch_id');

        $balance = 0.0;
        $openingTotal = 0.0;
        $taken = 0.0;
        $paidOutside = 0.0;
        $perBranch = [];

        foreach ($branches as $branch) {
            $opening = $openings->get($branch->id);
            $openingBalance = (float) ($opening->opening_balance ?? 0);
            // With no opening balance, count every closed day rather than show nothing —
            // the figure is then "takings so far", which the view labels as such.
            $since = $opening?->opening_date?->toDateString();

            $branchTaken = $this->takenSince($branch->id, $since);
            $branchPaidOutside = $this->cashOverheadSince($branch->id, $since)
                + $this->outsideExpensesSince($branch->id, $since);
            $branchBalance = $openingBalance + $branchTaken - $branchPaidOutside;

            $openingTotal += $openingBalance;
            $taken += $branchTaken;
            $paidOutside += $branchPaidOutside;
            $balance += $branchBalance;

            if ($opening !== null || abs($branchBalance) > 0.001) {
                $perBranch[] = [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'balance' => round($branchBalance, 2),
                    'configured' => $opening !== null,
                ];
            }
        }

        // Company-wide overhead belongs to no branch, so it comes off the all-branches
        // figure once, and never off a single branch's. Counted from the earliest opening
        // date only when every branch has one: overhead paid before then is already
        // inside those balances. Otherwise all of it, matching the branches counted from
        // their first day.
        $companyWideOverhead = 0.0;
        if ($branchId === null) {
            $companySince = $openings->count() === $branches->count()
                ? $openings->map(fn (CashOpeningBalance $opening) => $opening->opening_date?->toDateString())->filter()->min()
                : null;
            $companyWideOverhead = $this->cashOverheadSince(null, $companySince);
        }
        $paidOutside += $companyWideOverhead;
        $balance -= $companyWideOverhead;

        $single = $branchId !== null ? $openings->get($branchId) : null;

        return [
            'balance' => round($balance, 2),
            'opening_balance' => round($openingTotal, 2),
            // Only one branch in scope has a meaningful date; across branches they differ.
            'opening_date' => $single?->opening_date?->toDateString(),
            'taken' => round($taken, 2),
            'paid_outside' => round($paidOutside, 2),
            'company_wide_overhead' => round($companyWideOverhead, 2),
            'configured' => $branchId !== null ? $single !== null : $openings->count() === $branches->count(),
            'scope_count' => $branches->count(),
            'unconfigured_count' => $branches->count() - $openings->count(),
            'per_branch' => $perBranch,
        ];
    }

    private function takenSince(int $branchId, ?string $since): float
    {
        return (float) DayClosure::query()
            ->where('branch_id', $branchId)
            ->when($since, fn ($query) => $query->whereDate('closed_at_date', '>=', $since))
            ->toBase()
            ->selectRaw('COALESCE(SUM(counted_cash - opening_float), 0) as taken')
            ->value('taken');
    }

    /**
     * Settlement-dated on COALESCE(paid_date, period_month), like the Cash Report's
     * Paid Outside Drawer, so the two never disagree about when a payment happened.
     * A null branch means company-wide rows.
     */
    private function cashOverheadSince(?int $branchId, ?string $since): float
    {
        return (float) SpecialExpense::query()
            ->where('payment_method', 'cash')
            ->when(
                $branchId === null,
                fn ($query) => $query->whereNull('branch_id'),
                fn ($query) => $query->where('branch_id', $branchId)
            )
            ->when($since, fn ($query) => $query->whereRaw('COALESCE(paid_date, period_month) >= ?', [$since]))
            ->sum('amount');
    }

    private function outsideExpensesSince(int $branchId, ?string $since): float
    {
        return (float) Expense::query()
            ->where('branch_id', $branchId)
            ->where('status', 'approved')
            ->where('payment_method', 'cash')
            ->where('paid_from', 'outside')
            ->when($since, fn ($query) => $query->whereDate('expense_date', '>=', $since))
            ->sum('amount');
    }
}
