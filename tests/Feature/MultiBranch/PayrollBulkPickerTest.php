<?php

namespace Tests\Feature\MultiBranch;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The bulk-generate picker's branch filter is visual only, but the ticked ids were
 * submitted by the visible checkboxes. Tick branch A, switch the filter to B, tick
 * B: A's employees were not drawn, so they were not sent, while the button still
 * counted them. The form now submits the ticked list itself, one hidden input per
 * id, so what the button counts is what is generated.
 */
class PayrollBulkPickerTest extends TestCase
{
    use RefreshDatabase;

    private string $html;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        $owner = User::factory()->create();
        $owner->assignRole('owner');
        Branch::factory()->count(2)->create();

        $this->html = $this->actingAs($owner)->get('/payroll')->assertOk()->getContent();
    }

    public function test_the_ticked_list_is_what_gets_submitted(): void
    {
        $this->assertMatchesRegularExpression(
            '/<template x-for="id in selectedEmployeeIds"[^>]*>\s*<input type="hidden" name="employee_ids\[\]" :value="id">/',
            $this->html
        );
    }

    public function test_the_visible_checkboxes_no_longer_submit_ids_themselves(): void
    {
        $this->assertDoesNotMatchRegularExpression('/<input type="checkbox" name="employee_ids\[\]"/', $this->html);
    }
}
