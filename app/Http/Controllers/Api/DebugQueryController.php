<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReadonlyQueryRunner;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use PDOException;

/**
 * POST /api/debug/query — read-only SQL against production, for debugging.
 *
 * Off (404) unless DEBUG_QUERY_KEY is set. The key travels in the X-Debug-Key header,
 * never the URL, so it stays out of access logs. It runs only on a separate
 * SELECT-only database user, created by database/debug_readonly_user.sql without the
 * secret tables and columns; see ReadonlyQueryRunner for the checks in front of it.
 */
class DebugQueryController extends Controller
{
    private const MAX_QUERY_LENGTH = 10000;

    public function __invoke(Request $request, ReadonlyQueryRunner $runner): JsonResponse
    {
        $key = (string) config('services.debug_query.key');
        abort_if($key === '', 404);

        if (! hash_equals($key, (string) $request->header('X-Debug-Key'))) {
            Log::warning('debug-query: refused key', ['ip' => $request->ip()]);

            return $this->fail('Unauthorized.', 401);
        }

        $sql = $request->input('query');
        if (! is_string($sql) || trim($sql) === '' || strlen($sql) > self::MAX_QUERY_LENGTH) {
            return $this->fail('Send a "query" string of at most '.self::MAX_QUERY_LENGTH.' characters.', 422);
        }

        $connectionName = (string) config('services.debug_query.connection');
        if ($problem = $this->readonlyConnectionProblem($connectionName)) {
            return $this->fail($problem, 503);
        }

        $connection = DB::connection($connectionName);

        try {
            $result = $runner->run(
                $connection,
                $sql,
                (int) config('services.debug_query.max_rows'),
                (int) config('services.debug_query.timeout_ms'),
                (int) config('services.debug_query.max_cell_length')
            );
        } catch (InvalidArgumentException $e) {
            Log::info('debug-query: refused', ['ip' => $request->ip(), 'query' => $sql, 'reason' => $e->getMessage()]);

            return $this->fail($e->getMessage(), 422);
        } catch (QueryException $e) {
            Log::info('debug-query: failed', ['ip' => $request->ip(), 'query' => $sql]);

            // The driver's own message only: Laravel's adds the host, port and database.
            return $this->fail((string) ($e->errorInfo[2] ?? 'The query failed.'), 400);
        } catch (PDOException $e) {
            // Raised while reading rows (a time limit hit mid-result), outside Laravel's wrapper.
            Log::info('debug-query: failed', ['ip' => $request->ip(), 'query' => $sql]);

            return $this->fail((string) ($e->errorInfo[2] ?? 'The query failed.'), 400);
        } finally {
            // The reader streams results unbuffered; a half-read result would block the
            // next statement on this connection, so it is never reused.
            if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
                DB::disconnect($connectionName);
            }
        }

        Log::info('debug-query', ['ip' => $request->ip(), 'query' => $sql, 'rows' => $result['row_count']]);

        return response()->json(['success' => true, 'data' => $result, 'error' => null]);
    }

    /**
     * The endpoint must never run on the app's own database user, which can write.
     */
    private function readonlyConnectionProblem(string $name): ?string
    {
        $username = config("database.connections.{$name}.username");
        if (! is_array(config("database.connections.{$name}")) || blank($username)) {
            return 'Set DB_READONLY_USERNAME and DB_READONLY_PASSWORD to a SELECT-only database user.';
        }

        $appUsername = config('database.connections.'.config('database.default').'.username');
        if ($username === $appUsername) {
            return 'The read-only user must be a separate SELECT-only user, not the app\'s own.';
        }

        return null;
    }

    private function fail(string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'data' => null, 'error' => $message], $status);
    }
}
