<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRule;
use App\Models\User;
use App\Services\AttendanceSummaryCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Each late tier can be a fixed peso amount or a percentage of the daily rate.
 *
 * Before this, the schema hardcoded the shape: tiers one and two were amounts and
 * only the third could be a percentage. An owner who wanted the deduction expressed
 * as a proportion of the day had exactly one tier to put it on, which is why the live
 * Ramen Naijiro rule reads ₱40 / ₱80 / 50% — the percentage is on the third tier
 * because that is the only place it fits, not because the first two were meant to be
 * flat.
 *
 * The regression that matters most is the first test: an existing rule must keep
 * computing exactly what it computed before, to the centavo.
 */
class PercentageDeductionTiersTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Employee $employee;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');

        $this->branch = Branch::factory()->create();
        $this->owner = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->owner->assignRole('owner');
        $this->employee = Employee::factory()->create([
            'branch_id' => $this->branch->id,
            'daily_rate' => 420,
        ]);
    }

    /** The live Ramen Naijiro rule. */
    private function rule(array $overrides = []): PayrollRule
    {
        return PayrollRule::create(array_merge([
            'branch_id' => $this->branch->id,
            'grace_minutes' => 10,
            'standard_daily_hours' => 8,
            'required_clock_in_time' => '10:00:00',
            'first_deduction_time' => '10:15:00',
            'first_deduction_amount' => 40,
            'second_deduction_time' => '11:15:00',
            'second_deduction_amount' => 80,
            'third_deduction_time' => '12:30:00',
            'third_deduction_percent' => 50,
        ], $overrides));
    }

    private function record(string $date, string $in): AttendanceRecord
    {
        return AttendanceRecord::create([
            'employee_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'work_date' => $date,
            'clock_in_at' => $date.' '.$in,
            'clock_out_at' => $date.' 20:00:00',
            'status' => 'present',
            'captured_by_user_id' => $this->owner->id,
        ]);
    }

    private function deductionFor(PayrollRule $rule, string $clockIn): float
    {
        $this->record('2026-03-02', $clockIn);
        $records = AttendanceRecord::where('employee_id', $this->employee->id)->get();

        $summary = app(AttendanceSummaryCalculator::class)->summarizeForEmployee(
            $this->employee, $records, '2026-03-02', '2026-03-02', $rule->toArray()
        );

        return round((float) $summary['estimated_deductions'], 2);
    }

    // ---- the existing configuration must not move --------------------------

    public function test_an_existing_rule_computes_exactly_what_it_did_before(): void
    {
        $rule = $this->rule();

        $this->assertSame(0.0, $this->deductionFor($rule, '09:55:00'), 'on time');
    }

    public function test_existing_first_tier_is_still_a_flat_forty(): void
    {
        $this->assertSame(40.0, $this->deductionFor($this->rule(), '10:30:00'));
    }

    public function test_existing_second_tier_is_still_a_flat_eighty(): void
    {
        $this->assertSame(80.0, $this->deductionFor($this->rule(), '12:00:00'));
    }

    public function test_existing_third_tier_is_still_half_the_daily_rate(): void
    {
        $this->assertSame(210.0, $this->deductionFor($this->rule(), '14:00:00'));
    }

    // ---- each tier can now be a percentage ---------------------------------

    public function test_the_first_tier_can_be_a_percentage(): void
    {
        $rule = $this->rule([
            'first_deduction_type' => 'percent',
            'first_deduction_percent' => 5,
        ]);

        $this->assertSame(21.0, $this->deductionFor($rule, '10:30:00'), '5% of 420');
    }

    public function test_the_second_tier_can_be_a_percentage(): void
    {
        $rule = $this->rule([
            'second_deduction_type' => 'percent',
            'second_deduction_percent' => 10,
        ]);

        $this->assertSame(42.0, $this->deductionFor($rule, '12:00:00'), '10% of 420');
    }

    public function test_all_three_tiers_can_be_percentages(): void
    {
        $rule = $this->rule([
            'first_deduction_type' => 'percent', 'first_deduction_percent' => 5,
            'second_deduction_type' => 'percent', 'second_deduction_percent' => 10,
            'third_deduction_type' => 'percent', 'third_deduction_percent' => 50,
        ]);

        $this->assertSame(21.0, $this->deductionFor($rule, '10:30:00'));
    }

    public function test_the_third_tier_can_go_the_other_way_and_be_an_amount(): void
    {
        $rule = $this->rule([
            'third_deduction_type' => 'amount',
            'third_deduction_amount' => 150,
        ]);

        $this->assertSame(150.0, $this->deductionFor($rule, '14:00:00'));
    }

    public function test_a_percentage_tier_scales_with_the_employees_rate(): void
    {
        $this->employee->update(['daily_rate' => 1000]);
        $rule = $this->rule(['first_deduction_type' => 'percent', 'first_deduction_percent' => 5]);

        $this->assertSame(50.0, $this->deductionFor($rule, '10:30:00'));
    }

    // ---- the per-day breakdown must agree with the total -------------------

    public function test_the_daily_breakdown_agrees_with_the_summary(): void
    {
        $rule = $this->rule([
            'first_deduction_type' => 'percent', 'first_deduction_percent' => 5,
            'second_deduction_type' => 'percent', 'second_deduction_percent' => 10,
        ]);
        $this->record('2026-03-02', '10:30:00');
        $this->record('2026-03-03', '12:00:00');
        $this->record('2026-03-04', '14:00:00');

        $this->actingAs($this->owner)->post(route('payroll.generate'), [
            'employee_id' => $this->employee->id,
            'start_date' => '2026-03-02',
            'end_date' => '2026-03-04',
        ]);

        $entry = PayrollEntry::sole();
        // 5% + 10% + 50% of 420 = 21 + 42 + 210
        $this->assertEquals(273.0, (float) $entry->deductions);

        $response = $this->actingAs($this->owner)
            ->get(route('payroll.show', $entry->payroll_period_id).'?employee_id='.$this->employee->id)
            ->assertOk();

        $breakdown = collect($response->viewData('dailyBreakdown'));
        $this->assertEqualsWithDelta(273.0, $breakdown->sum('deduction'), 0.01,
            'the per-day rows do not add up to the stored total');
    }

    // ---- the form ----------------------------------------------------------

    public function test_the_rules_form_persists_a_percentage_tier(): void
    {
        $this->rule();

        $this->actingAs($this->owner)->post(route('payroll.rules.update'), [
            'branch_id' => $this->branch->id,
            'standard_daily_hours' => 8,
            'required_clock_in_time' => '10:00',
            'first_deduction_time' => '10:15',
            'first_deduction_type' => 'percent',
            'first_deduction_percent' => 5,
            'first_deduction_amount' => 0,
            'second_deduction_time' => '11:15',
            'second_deduction_type' => 'amount',
            'second_deduction_amount' => 80,
            'second_deduction_percent' => 0,
            'third_deduction_time' => '12:30',
            'third_deduction_type' => 'percent',
            'third_deduction_percent' => 50,
            'third_deduction_amount' => 0,
        ])->assertSessionHasNoErrors();

        $rule = PayrollRule::where('branch_id', $this->branch->id)->sole();
        $this->assertSame('percent', $rule->first_deduction_type);
        $this->assertEquals(5.0, (float) $rule->first_deduction_percent);
        $this->assertSame('amount', $rule->second_deduction_type);
    }

    public function test_a_percentage_over_one_hundred_is_rejected(): void
    {
        $this->actingAs($this->owner)->post(route('payroll.rules.update'), [
            'branch_id' => $this->branch->id,
            'standard_daily_hours' => 8,
            'required_clock_in_time' => '10:00',
            'first_deduction_time' => '10:15',
            'first_deduction_type' => 'percent',
            'first_deduction_percent' => 150,
            'first_deduction_amount' => 0,
            'second_deduction_time' => '11:15',
            'second_deduction_type' => 'amount',
            'second_deduction_amount' => 80,
            'second_deduction_percent' => 0,
            'third_deduction_time' => '12:30',
            'third_deduction_type' => 'percent',
            'third_deduction_percent' => 50,
            'third_deduction_amount' => 0,
        ])->assertSessionHasErrors('first_deduction_percent');
    }

    public function test_an_unknown_tier_type_is_rejected(): void
    {
        $this->actingAs($this->owner)->post(route('payroll.rules.update'), [
            'branch_id' => $this->branch->id,
            'standard_daily_hours' => 8,
            'required_clock_in_time' => '10:00',
            'first_deduction_time' => '10:15',
            'first_deduction_type' => 'sliding_scale',
            'first_deduction_amount' => 40,
            'first_deduction_percent' => 0,
            'second_deduction_time' => '11:15',
            'second_deduction_type' => 'amount',
            'second_deduction_amount' => 80,
            'second_deduction_percent' => 0,
            'third_deduction_time' => '12:30',
            'third_deduction_type' => 'percent',
            'third_deduction_percent' => 50,
            'third_deduction_amount' => 0,
        ])->assertSessionHasErrors('first_deduction_type');
    }

    public function test_the_rules_drawer_offers_the_choice(): void
    {
        $html = $this->actingAs($this->owner)->get(route('payroll.index'))->assertOk()->getContent();

        $this->assertStringContainsString('first_deduction_type', $html);
        $this->assertStringContainsString('second_deduction_percent', $html);
        $this->assertStringContainsString('third_deduction_amount', $html);
    }
}
