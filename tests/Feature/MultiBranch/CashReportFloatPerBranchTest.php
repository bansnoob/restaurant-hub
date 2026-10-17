<?php

namespace Tests\Feature\MultiBranch;

use App\Models\Branch;
use App\Models\DayClosure;
use App\Models\User;
use App\Services\CashReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Float held separately" was the newest single closure's float. With two tills,
 * the All-branches figure showed one branch's float, whichever closed last. It is
 * now each branch's latest float, added up. A single branch is unchanged: its own
 * latest float.
 */
class CashReportFloatPerBranchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Branch $main;

    private Branch $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->main = Branch::factory()->create();
        $this->second = Branch::factory()->create();
    }

    private function day(Branch $branch, string $date, float $opening): void
    {
        DayClosure::create([
            'branch_id' => $branch->id, 'closed_at_date' => $date,
            'closed_by_user_id' => $this->user->id, 'closed_at' => $date.' 21:00:00',
            'opening_float' => $opening,
            'cash_sales_total' => 0, 'mixed_cash_total' => 0, 'gcash_sales_total' => 0,
            'cash_expenses_total' => 0, 'expected_cash' => $opening, 'counted_cash' => $opening,
            'variance' => 0, 'order_count' => 0, 'expense_count' => 0,
        ]);
    }

    private function floatHeld(?int $branchId): float
    {
        $svc = app(CashReportService::class);
        $rows = $svc->dayRows('2026-09-01', '2026-09-30', $branchId);

        return $svc->totals($rows, '2026-09-01', '2026-09-30', $branchId)['float_in_till'];
    }

    public function test_all_branches_adds_each_tills_latest_float(): void
    {
        $this->day($this->main, '2026-09-10', 1000);
        $this->day($this->main, '2026-09-11', 1200);
        $this->day($this->second, '2026-09-10', 500);

        $this->assertEqualsWithDelta(1700.0, $this->floatHeld(null), 0.005,
            'main\'s latest (1,200) plus second\'s latest (500)');
    }

    public function test_one_branch_still_reports_its_own_latest_float(): void
    {
        $this->day($this->main, '2026-09-10', 1000);
        $this->day($this->main, '2026-09-11', 1200);
        $this->day($this->second, '2026-09-12', 500);

        $this->assertEqualsWithDelta(1200.0, $this->floatHeld($this->main->id), 0.005);
    }
}
