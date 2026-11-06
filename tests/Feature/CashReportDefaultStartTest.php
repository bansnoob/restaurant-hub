<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Ramen Naijiro started counting its Cash on Hand on 2026-11-01, so its admin's Cash
 * Report opens there (the owner's call). Everyone else keeps the last 30 days.
 */
class CashReportDefaultStartTest extends TestCase
{
    use RefreshDatabase;

    private Branch $ramen;

    private Branch $samgyupsal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-20 12:00:00');
        foreach (['owner', 'admin', 'cashier'] as $role) {
            Role::findOrCreate($role);
        }

        // Production's codes: Ramen Naijiro is sm001, despite the name.
        $this->ramen = Branch::factory()->create(['code' => 'sm001', 'name' => 'Ramen Naijiro']);
        $this->samgyupsal = Branch::factory()->create(['code' => 'sm002', 'name' => 'Samgyupsal']);
    }

    private function user(string $role, Branch $branch): User
    {
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $user->assignRole($role);

        return $user;
    }

    private function filters(User $user, array $query = []): array
    {
        return $this->actingAs($user)
            ->get(route('day-closures.index', $query))
            ->assertOk()
            ->viewData('filters');
    }

    public function test_ramen_naijiros_admin_opens_the_report_from_nov_1(): void
    {
        $filters = $this->filters($this->user('admin', $this->ramen));

        $this->assertSame('2026-11-01', $filters['date_from']);
        $this->assertSame('2026-11-20', $filters['date_to']);
    }

    public function test_dates_asked_for_still_win(): void
    {
        $filters = $this->filters($this->user('admin', $this->ramen), ['date_from' => '2026-11-10', 'date_to' => '2026-11-15']);

        $this->assertSame('2026-11-10', $filters['date_from']);
        $this->assertSame('2026-11-15', $filters['date_to']);
    }

    public function test_an_owners_all_branches_view_keeps_the_last_30_days(): void
    {
        // Production's owners have Ramen Naijiro as their own branch too.
        $filters = $this->filters($this->user('owner', $this->ramen));

        $this->assertSame('2026-10-22', $filters['date_from']);
    }

    public function test_another_branchs_admin_keeps_the_last_30_days(): void
    {
        $this->assertSame('2026-10-22', $this->filters($this->user('admin', $this->samgyupsal))['date_from']);
    }

    /** Reset goes back to the default, so offering it on the default view is a no-op. */
    public function test_reset_shows_only_once_the_dates_move_off_the_default(): void
    {
        $admin = $this->user('admin', $this->ramen);

        $this->actingAs($admin)->get(route('day-closures.index'))
            ->assertOk()->assertDontSee('class="rh-emp-clear"', false);

        $this->actingAs($admin)->get(route('day-closures.index', ['date_from' => '2026-11-10']))
            ->assertOk()->assertSee('class="rh-emp-clear"', false);
    }
}
