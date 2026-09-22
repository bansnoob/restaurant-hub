<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\GcashEntryStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SCRATCH VERIFICATION ONLY - adversarial check of the "data-forensics" adjacency claim.
 *
 * Reproduces the six production GCash Out rows (branch 1, 2026-08-22..2026-09-20) with the
 * same id ORDERING as production (438 < 439 < 454 < 455 < 456 < 457) and renders the real
 * gcash_report page, then reads the GCash Out table back out of the HTML.
 */
class GcashOutAdjacencyClaimTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Branch $branch;

    /** @var array<string,Expense> */
    private array $rows = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-20 23:35:00');

        Role::findOrCreate('owner');
        $this->branch = Branch::factory()->create(['name' => 'Main']);
        $this->owner = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->owner->assignRole('owner');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Created in ascending order so sqlite ids mirror production's id ordering. */
    private function seedScene(string $date438): void
    {
        $spec = [
            // key      date          description                        amount
            '438' => [$date438,     'GCash out',                         500.00],
            '439' => ['2026-09-12', 'GCash out',                         1123.00],
            '454' => ['2026-09-11', 'Payment (Gloves)',                  478.00],
            '455' => ['2026-09-11', 'Payment',                           500.00],
            '456' => ['2026-09-14', 'Payment (Chicken Powder & Gloves)', 1123.00],
            '457' => ['2026-09-14', 'Payment (Tissue)',                  790.00],
        ];

        foreach ($spec as $key => [$date, $desc, $amount]) {
            $this->rows[$key] = Expense::factory()->gcash()->create([
                'branch_id' => $this->branch->id,
                'expense_category_id' => null,   // claim: both render an em dash
                'recorded_by_user_id' => $this->owner->id,
                'expense_date' => $date,
                'description' => $desc,
                'amount' => $amount,
                'vendor_name' => null,
                'reference_no' => null,
                'notes' => null,
                'status' => 'approved',
            ]);
        }

        // Both 438 and 439 carry an accepted wallet review in production.
        foreach (['438', '439'] as $key) {
            GcashEntryStatus::create([
                'entry_type' => GcashEntryStatus::TYPE_EXPENSE,
                'entry_id' => $this->rows[$key]->id,
                'status' => GcashEntryStatus::ACCEPTED,
                'reviewed_by_user_id' => $this->owner->id,
                'reviewed_at' => '2026-09-12 17:08:08',
            ]);
        }
    }

    /**
     * Pull the GCash Out table out of the page and return one entry per rendered <tr>:
     * ['id' => int, 'cells' => [<td> inner text, ...]].
     *
     * @return array<int,array{id:int,cells:array<int,string>}>
     */
    private function gcashOutRows(string $html): array
    {
        $start = strpos($html, 'GCash Out');
        $this->assertNotFalse($start, 'GCash Out section missing.');
        $end = strpos($html, 'Adjustments', $start);
        $section = substr($html, $start, ($end !== false ? $end - $start : null));

        preg_match('/<tbody>(.*?)<\/tbody>/s', $section, $tbody);
        $this->assertNotEmpty($tbody, 'GCash Out tbody missing.');

        preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/s', $tbody[1], $trs);

        $out = [];
        foreach ($trs[1] as $tr) {
            preg_match_all('/<td\b[^>]*>(.*?)<\/td>/s', $tr, $tds);
            $cells = array_map(
                fn ($c) => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($c)))),
                $tds[1]
            );
            preg_match('/\\\\u0022id\\\\u0022:(\d+)/', $tr, $idm);
            $out[] = ['id' => (int) ($idm[1] ?? 0), 'cells' => $cells];
        }

        return $out;
    }

    private function render(array $query = []): string
    {
        return $this->actingAs($this->owner)
            ->get(route('gcash-report.index', $query))
            ->assertOk()
            ->getContent();
    }

    /**
     * CLAIM PART A - with 438 on 2026-09-12 the two rows are adjacent under the
     * DEFAULT range, and differ only in the amount cell.
     */
    public function test_after_state_adjacency_and_cell_by_cell_difference(): void
    {
        $this->seedScene('2026-09-12');

        // No date params at all: exercise DEFAULT_RANGE_DAYS = 29 with "today" = 2026-09-20.
        $rows = $this->gcashOutRows($this->render());

        fwrite(STDERR, "\n--- DEFAULT RANGE (2026-08-22..2026-09-20), rendered order ---\n");
        foreach ($rows as $i => $r) {
            fwrite(STDERR, sprintf("  %d. id=%-3d | %s\n", $i + 1, $r['id'], implode(' | ', $r['cells'])));
        }

        $order = array_column($rows, 'id');
        $pos438 = array_search($this->rows['438']->id, $order, true);
        $pos439 = array_search($this->rows['439']->id, $order, true);

        $this->assertNotFalse($pos438);
        $this->assertNotFalse($pos439);
        $this->assertSame($pos439 + 1, $pos438, '439 should sit immediately above 438.');

        $c438 = $rows[$pos438]['cells'];
        $c439 = $rows[$pos439]['cells'];

        fwrite(STDERR, "\n--- CELL-BY-CELL: 439 (above) vs 438 (below) ---\n");
        $differing = [];
        foreach ($c439 as $i => $cell) {
            $same = ($cell === ($c438[$i] ?? null));
            if (! $same) {
                $differing[] = $i;
            }
            fwrite(STDERR, sprintf(
                "  td[%d] %-5s  439=%-28s 438=%s\n",
                $i, $same ? 'SAME' : 'DIFF', '"'.$cell.'"', '"'.($c438[$i] ?? '<missing>').'"'
            ));
        }
        fwrite(STDERR, '  => differing cell indexes: ['.implode(',', $differing)."]\n");
        fwrite(STDERR, '  => rendered column count: '.count($c438)." (claim describes 5)\n\n");
    }

    /**
     * CLAIM PART B - the asserted "before" state: 438 on 2026-09-11, "four rows apart".
     * Measure the real gap.
     */
    public function test_before_state_gap_asserted_by_the_claim(): void
    {
        $this->seedScene('2026-09-11');

        $rows = $this->gcashOutRows($this->render());

        fwrite(STDERR, "\n--- HYPOTHETICAL BEFORE STATE (438 on 2026-09-11) ---\n");
        foreach ($rows as $i => $r) {
            fwrite(STDERR, sprintf("  %d. id=%-3d | %s\n", $i + 1, $r['id'], implode(' | ', $r['cells'])));
        }

        $order = array_column($rows, 'id');
        $pos438 = array_search($this->rows['438']->id, $order, true);
        $pos439 = array_search($this->rows['439']->id, $order, true);

        fwrite(STDERR, sprintf(
            "  => 439 at row %d, 438 at row %d, gap = %d rows (claim says \"four rows apart\")\n\n",
            $pos439 + 1, $pos438 + 1, $pos438 - $pos439
        ));

        $this->assertTrue(true);
    }
}
