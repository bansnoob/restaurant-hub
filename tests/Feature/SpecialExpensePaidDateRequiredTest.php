<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\SpecialExpense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * An overhead row with no paid date was treated as paid on the 1st of its month.
 * A Cash Report that started on the 9th therefore dropped that month's undated rent
 * even when it was paid on the 15th, and the next month's landed on the 1st whenever
 * it was really paid. The form now always records when the money moved.
 */
class SpecialExpensePaidDateRequiredTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        Role::findOrCreate('cashier');

        $this->branch = Branch::factory()->create();
        $this->owner = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->owner->assignRole('owner');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id,
            'period_month' => now()->startOfMonth()->toDateString(),
            'paid_date' => now()->toDateString(),
            'description' => 'Monthly rent',
            'amount' => 40000,
            'payment_method' => 'cash',
        ], $overrides);
    }

    public function test_a_new_row_without_a_paid_date_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->post(route('special-expenses.store'), $this->payload(['paid_date' => '']))
            ->assertSessionHasErrors('paid_date');

        $this->assertSame(0, SpecialExpense::count());
    }

    public function test_a_new_row_with_a_paid_date_is_saved(): void
    {
        $this->actingAs($this->owner)
            ->post(route('special-expenses.store'), $this->payload(['paid_date' => '2026-10-15']))
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-10-15', SpecialExpense::sole()->paid_date->toDateString());
    }

    public function test_an_edit_cannot_clear_the_paid_date(): void
    {
        $row = SpecialExpense::create($this->payload(['paid_date' => '2026-10-15']));

        $this->actingAs($this->owner)
            ->put(route('special-expenses.update', $row), $this->payload(['paid_date' => '']))
            ->assertSessionHasErrors('paid_date');

        $this->assertSame('2026-10-15', $row->fresh()->paid_date->toDateString());
    }

    /**
     * Existing undated rows keep counting on the 1st until someone edits them; they are
     * not rewritten behind the owner's back.
     */
    public function test_an_existing_undated_row_is_left_as_it_is(): void
    {
        $row = SpecialExpense::create($this->payload(['paid_date' => null]));

        $this->actingAs($this->owner)->get(route('special-expenses.index'))->assertOk();

        $this->assertNull($row->fresh()->paid_date);
    }

    public function test_the_form_marks_the_paid_date_required_and_starts_on_today(): void
    {
        foreach ([route('special-expenses.index'), route('day-closures.index')] as $url) {
            $html = $this->actingAs($this->owner)->get($url)->assertOk()->getContent();

            $this->assertMatchesRegularExpression('/<input[^>]*name="paid_date"[^>]*required/', $html, $url);
            $this->assertStringContainsString("today: '".now()->toDateString()."'", $html, $url);
        }
    }
}
