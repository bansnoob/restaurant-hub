<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Read-only SQL over HTTP, for checking production data from a terminal.
 *
 * Production's real write protection is the SELECT-only database user the endpoint
 * insists on. These tests pin the layers in front of it: no endpoint without a key,
 * no run on the app's own user, one read statement at a time, and no secrets out.
 */
class DebugReadonlyQueryTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-debug-key';

    private const URL = '/api/debug/query';

    protected function setUp(): void
    {
        parent::setUp();

        // A second connection name over the same in-memory database, standing in for
        // the SELECT-only user's connection.
        config(['database.connections.readonly_probe' => array_merge(
            config('database.connections.sqlite'),
            ['username' => 'reader']
        )]);
        DB::connection('readonly_probe')->setPdo(DB::connection()->getPdo());

        config([
            'services.debug_query.key' => self::KEY,
            'services.debug_query.connection' => 'readonly_probe',
        ]);
    }

    private function query(string $sql, ?string $key = self::KEY)
    {
        return $this->postJson(self::URL, ['query' => $sql], $key === null ? [] : ['X-Debug-Key' => $key]);
    }

    public function test_the_endpoint_does_not_exist_until_a_key_is_set(): void
    {
        config(['services.debug_query.key' => null]);

        $this->query('SELECT 1')->assertNotFound();
    }

    public function test_a_missing_or_wrong_key_is_refused(): void
    {
        $this->query('SELECT 1', null)->assertUnauthorized();
        $this->query('SELECT 1', 'wrong')->assertUnauthorized()->assertJsonPath('success', false);
    }

    public function test_a_select_returns_its_rows(): void
    {
        $branch = Branch::factory()->create(['name' => 'Ramen Naijiro']);

        $this->query('SELECT id, name FROM branches')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('error', null)
            ->assertJsonPath('data.columns', ['id', 'name'])
            ->assertJsonPath('data.rows.0.name', 'Ramen Naijiro')
            ->assertJsonPath('data.rows.0.id', $branch->id)
            ->assertJsonPath('data.row_count', 1)
            ->assertJsonPath('data.truncated', false);
    }

    public function test_show_describe_and_explain_style_reads_are_allowed(): void
    {
        $this->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'branches'")
            ->assertOk()
            ->assertJsonPath('data.rows.0.name', 'branches');
        $this->query('EXPLAIN SELECT * FROM branches')->assertOk();
    }

    public static function writes(): array
    {
        return [
            'update' => ["UPDATE branches SET name = 'x'"],
            'delete' => ['DELETE FROM branches'],
            'insert' => ["INSERT INTO branches (name) VALUES ('x')"],
            'drop' => ['DROP TABLE branches'],
            'alter' => ['ALTER TABLE branches ADD COLUMN x INT'],
            'truncate' => ['TRUNCATE branches'],
            'create' => ['CREATE TABLE x (id INT)'],
            'set' => ['SET @x = 1'],
            'second statement' => ['SELECT 1; DELETE FROM branches'],
            'cte write' => ['WITH doomed AS (SELECT id FROM branches) DELETE FROM branches'],
            'into outfile' => ["SELECT * FROM branches INTO OUTFILE '/tmp/branches'"],
            'load_file' => ["SELECT LOAD_FILE('/etc/passwd')"],
            'executable comment' => ['SELECT 1 /*!50000 , 2 */'],
            'optimizer hint' => ['SELECT /*+ MAX_EXECUTION_TIME(999999) */ 1'],
            'row lock' => ['SELECT * FROM branches FOR UPDATE'],
            'sleep' => ['SELECT SLEEP(60)'],
            'named lock' => ["SELECT GET_LOCK('pos', 600)"],
        ];
    }

    #[DataProvider('writes')]
    public function test_anything_but_a_single_read_is_refused(string $sql): void
    {
        Branch::factory()->create(['name' => 'Kept']);

        $this->query($sql)->assertStatus(422)->assertJsonPath('success', false);

        $this->assertSame(['Kept'], Branch::pluck('name')->all());
    }

    /**
     * A substring blocklist refused created_at (CREATE) and updated_at (UPDATE) and
     * any value containing a keyword. Words are matched whole, outside quotes.
     */
    public function test_ordinary_columns_and_values_are_not_mistaken_for_writes(): void
    {
        Branch::factory()->create(['name' => 'Main']);

        $this->query('SELECT created_at, updated_at FROM branches')->assertOk();
        $this->query("SELECT name FROM branches WHERE name <> 'delete; drop table branches'")
            ->assertOk()
            ->assertJsonPath('data.rows.0.name', 'Main');
        $this->query('SELECT name FROM branches ORDER BY name DESC;')->assertOk();
    }

    public static function serverShows(): array
    {
        return [
            'variables' => ['SHOW VARIABLES'],
            'grants' => ['SHOW GRANTS'],
            'processlist' => ['SHOW PROCESSLIST'],
            'status' => ['SHOW GLOBAL STATUS'],
        ];
    }

    /** SHOW is limited to the schema: tables, columns, indexes, CREATE TABLE. */
    #[DataProvider('serverShows')]
    public function test_show_cannot_read_server_settings(string $sql): void
    {
        $this->query($sql)->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_long_values_are_cut_short(): void
    {
        config(['services.debug_query.max_cell_length' => 100]);

        $value = $this->query("SELECT '".str_repeat('x', 3000)."' AS long_value")
            ->assertOk()
            ->json('data.rows.0.long_value');

        $this->assertSame(str_repeat('x', 100).'…', $value);
    }

    public static function secrets(): array
    {
        return [
            'password' => ['SELECT password FROM users'],
            'aliased password' => ['SELECT `password` AS p FROM users'],
            'remember token' => ['SELECT remember_token FROM users'],
            'sessions' => ['SELECT * FROM sessions'],
            'api tokens' => ['SELECT * FROM personal_access_tokens'],
            'reset tokens' => ['SELECT * FROM password_reset_tokens'],
            'cache' => ['SELECT * FROM cache'],
        ];
    }

    #[DataProvider('secrets')]
    public function test_secrets_cannot_be_asked_for(string $sql): void
    {
        $this->query($sql)->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_select_star_on_users_redacts_the_secrets(): void
    {
        User::factory()->create(['email' => 'owner@example.com', 'remember_token' => 'abc123']);

        $row = $this->query('SELECT * FROM users')->assertOk()->json('data.rows.0');

        $this->assertSame('owner@example.com', $row['email']);
        $this->assertSame('[redacted]', $row['password']);
        $this->assertSame('[redacted]', $row['remember_token']);
    }

    public function test_results_are_capped(): void
    {
        config(['services.debug_query.max_rows' => 2]);
        Branch::factory()->count(3)->create();

        $this->query('SELECT id FROM branches')
            ->assertOk()
            ->assertJsonPath('data.row_count', 2)
            ->assertJsonPath('data.truncated', true);
    }

    public function test_a_failing_query_reports_the_database_error_not_a_500(): void
    {
        $this->query('SELECT * FROM no_such_table')
            ->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data', null);
    }

    public function test_it_will_not_run_without_a_separate_read_only_user(): void
    {
        config(['database.connections.readonly_probe.username' => null]);
        DB::purge('readonly_probe');
        $this->query('SELECT 1')->assertStatus(503);

        // The app's own user can write, so it is never accepted as the reader.
        config([
            'database.connections.readonly_probe.username' => 'app',
            'database.connections.sqlite.username' => 'app',
        ]);
        $this->query('SELECT 1')->assertStatus(503);
    }

    public function test_every_query_is_logged(): void
    {
        Log::spy();

        $this->query('SELECT 1 AS one')->assertOk();

        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message, array $context) => $message === 'debug-query' && $context['query'] === 'SELECT 1 AS one'
        );
    }

    public function test_requests_are_rate_limited(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->query('SELECT 1')->assertOk();
        }

        $this->query('SELECT 1')->assertStatus(429);
    }
}
