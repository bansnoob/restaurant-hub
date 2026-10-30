<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DayClosure;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Close Day used to target the calendar date the button was pressed on. A close at
 * 12:30am filed the night's cash under the next day: the real day showed "Not
 * closed", and that next night's close was then refused as "Day already closed", so
 * its count was never recorded at all.
 *
 * Until 5am, the day being closed is yesterday.
 */
class DayCloseBeforeFiveAmClosesYesterdayTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $owner;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('owner');
        Role::findOrCreate('cashier');

        $this->branch = Branch::factory()->create();
        $this->owner = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->owner->assignRole('owner');

        $this->cashier = User::factory()->create();
        $this->cashier->assignRole('cashier');
        Employee::factory()->create(['user_id' => $this->cashier->id, 'branch_id' => $this->branch->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ipadPreviewDate(): string
    {
        Sanctum::actingAs($this->cashier);

        return $this->getJson('/api/v1/day-close/preview')->assertOk()->json('date');
    }

    private function webPreviewDate(): string
    {
        return $this->actingAs($this->owner)
            ->getJson(route('day-close.preview', ['branch_id' => $this->branch->id]))
            ->assertOk()
            ->json('date');
    }

    public function test_the_ipad_closes_yesterday_after_midnight(): void
    {
        Carbon::setTestNow('2026-10-31 00:30:00');

        $this->assertSame('2026-10-30', $this->ipadPreviewDate());
    }

    public function test_the_ipad_closes_yesterday_just_before_five(): void
    {
        Carbon::setTestNow('2026-10-31 04:59:59');

        $this->assertSame('2026-10-30', $this->ipadPreviewDate());
    }

    public function test_the_ipad_closes_today_from_five(): void
    {
        Carbon::setTestNow('2026-10-31 05:00:00');

        $this->assertSame('2026-10-31', $this->ipadPreviewDate());
    }

    public function test_the_ipad_can_close_a_day_after_midnight(): void
    {
        Carbon::setTestNow('2026-10-31 00:30:00');
        Sanctum::actingAs($this->cashier);

        $this->postJson('/api/v1/day-close', [
            'closed_at_date' => $this->ipadPreviewDate(),
            'opening_float' => 0,
            'counted_cash' => 1200,
        ])->assertSuccessful();

        $closure = DayClosure::sole();
        $this->assertSame($this->branch->id, (int) $closure->branch_id);
        $this->assertSame('2026-10-30', $closure->closed_at_date->toDateString());
    }

    public function test_the_website_preview_follows_the_same_rule(): void
    {
        Carbon::setTestNow('2026-10-31 01:15:00');
        $this->assertSame('2026-10-30', $this->webPreviewDate());

        Carbon::setTestNow('2026-10-31 18:00:00');
        $this->assertSame('2026-10-31', $this->webPreviewDate());
    }

    public function test_an_explicit_date_still_wins(): void
    {
        Carbon::setTestNow('2026-10-31 01:15:00');

        $date = $this->actingAs($this->owner)
            ->getJson(route('day-close.preview', ['branch_id' => $this->branch->id, 'date' => '2026-10-31']))
            ->assertOk()
            ->json('date');

        $this->assertSame('2026-10-31', $date);
    }

    /**
     * The dashboard's closed/not-closed card must look at the same day the button
     * closes. Keyed to the calendar date, it read "Not closed" right after the night
     * was closed, and pressing Close Day again hit "Day already closed".
     */
    public function test_the_dashboard_reports_last_night_as_closed_after_midnight(): void
    {
        Carbon::setTestNow('2026-10-31 01:10:00');
        Sanctum::actingAs($this->cashier);
        $this->postJson('/api/v1/day-close', [
            'closed_at_date' => $this->ipadPreviewDate(),
            'counted_cash' => 1200,
        ])->assertSuccessful();

        $response = $this->actingAs($this->owner)->get(route('dashboard'))->assertOk();

        $row = collect($response->viewData('branchClosures'))->firstWhere('branch_id', $this->branch->id);
        $this->assertNotNull($row['closure']);
        $response->assertDontSee('Close Day Now');
    }

    public function test_the_dashboard_offers_to_close_last_night_until_it_is_closed(): void
    {
        Carbon::setTestNow('2026-10-31 01:10:00');

        $response = $this->actingAs($this->owner)->get(route('dashboard'))->assertOk();

        $row = collect($response->viewData('branchClosures'))->firstWhere('branch_id', $this->branch->id);
        $this->assertNull($row['closure']);
        $response->assertSee('Close Day Now');
    }

    /**
     * The dashboard drawer sends its own default date rather than leaving it to the
     * server, so the rule has to reach the page as well.
     */
    public function test_the_dashboard_drawer_defaults_to_yesterday_after_midnight(): void
    {
        Carbon::setTestNow('2026-10-31 01:15:00');

        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertOk()
            ->assertSee("closeDate: '2026-10-30'", false);
    }
}
