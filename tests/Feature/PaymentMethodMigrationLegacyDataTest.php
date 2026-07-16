<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A CHECK constraint is enforced during SQLite's table rewrite, so the SQLite realignment
 * migration must widen the enum before moving legacy values across and only then narrow it.
 * Rebuild the genuine pre-rename shape — old CHECKs plus legacy rows — and drive the real
 * migration over it.
 */
class PaymentMethodMigrationLegacyDataTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('This migration only does work on SQLite.');
        }

        $this->file = sys_get_temp_dir().'/rh_legacy_'.uniqid().'.sqlite';
        touch($this->file);

        config(['database.connections.legacy_probe' => [
            'driver' => 'sqlite',
            'database' => $this->file,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);

        DB::purge('legacy_probe');
        DB::setDefaultConnection('legacy_probe');

        DB::statement("CREATE TABLE sales (
            id integer primary key autoincrement not null,
            grand_total numeric not null default '0',
            order_type varchar check (order_type in ('dine_in', 'takeout', 'delivery')) not null default 'dine_in',
            status varchar check (status in ('open', 'completed', 'voided', 'refunded')) not null default 'completed',
            payment_method varchar check (payment_method in ('cash', 'card', 'e_wallet', 'mixed', 'unpaid')) not null default 'cash'
        )");

        DB::statement("CREATE TABLE expenses (
            id integer primary key autoincrement not null,
            amount numeric not null default '0',
            status varchar check (status in ('draft', 'approved', 'voided')) not null default 'approved',
            payment_method varchar check (payment_method in ('cash', 'bank_transfer', 'card', 'e_wallet', 'other')) not null default 'cash'
        )");
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('legacy_probe');
        @unlink($this->file);

        parent::tearDown();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_07_16_000000_align_payment_method_enums_for_sqlite.php');
    }

    public function test_migration_moves_legacy_values_without_violating_the_check_constraint(): void
    {
        DB::table('sales')->insert([
            ['payment_method' => 'e_wallet', 'grand_total' => 41.05],
            ['payment_method' => 'card', 'grand_total' => 10.00],
            ['payment_method' => 'cash', 'grand_total' => 5.00],
        ]);
        DB::table('expenses')->insert([
            ['payment_method' => 'e_wallet', 'amount' => 7.00],
            ['payment_method' => 'card', 'amount' => 3.00],
        ]);

        $this->migration()->up();

        $this->assertSame(
            ['cash', 'cash', 'gcash'],
            DB::table('sales')->orderBy('payment_method')->pluck('payment_method')->all(),
            'e_wallet should become gcash and card should become cash.'
        );
        $this->assertSame(
            ['gcash', 'other'],
            DB::table('expenses')->orderBy('payment_method')->pluck('payment_method')->all()
        );

        // The narrowed constraint must now be live.
        $sql = (string) DB::selectOne("SELECT sql FROM sqlite_master WHERE type='table' AND name='sales'")->sql;
        $this->assertStringContainsString("'cash', 'gcash', 'mixed', 'unpaid'", $sql);
        $this->assertStringNotContainsString('e_wallet', $sql);
    }

    public function test_migration_is_reversible_from_its_own_post_up_state(): void
    {
        DB::table('sales')->insert([['payment_method' => 'e_wallet', 'grand_total' => 41.05]]);

        $migration = $this->migration();
        $migration->up();
        $migration->down();

        $this->assertSame('e_wallet', DB::table('sales')->value('payment_method'));

        $sql = (string) DB::selectOne("SELECT sql FROM sqlite_master WHERE type='table' AND name='sales'")->sql;
        $this->assertStringContainsString('e_wallet', $sql);
    }

    public function test_up_preserves_the_neighbouring_enum_constraints_on_a_populated_table(): void
    {
        DB::table('sales')->insert([['payment_method' => 'e_wallet', 'grand_total' => 41.05]]);

        $this->migration()->up();

        $sql = (string) DB::selectOne("SELECT sql FROM sqlite_master WHERE type='table' AND name='sales'")->sql;
        $this->assertStringContainsString("'open', 'completed', 'voided', 'refunded'", $sql);
        $this->assertStringContainsString("'dine_in', 'takeout', 'delivery'", $sql);
        $this->assertTrue(Schema::hasColumn('sales', 'payment_method'));
    }
}
