<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * Runs one read-only SQL statement for the debug query endpoint.
 *
 * The SELECT-only database user is what really stops writes. Everything here is a
 * line in front of it, so that a leaked key can neither change data nor read the
 * secrets the app stores (password hashes, session payloads, API tokens).
 */
class ReadonlyQueryRunner
{
    public const REDACTED = '[redacted]';

    private const READ_STATEMENT = '/^\s*(select|with|show|describe|desc|explain)\b/i';

    // Whole words only, checked after quoted values are blanked: created_at is not
    // CREATE, and WHERE name = 'delete me' is not a DELETE.
    private const WRITE_OR_SIDE_EFFECT = '/\b(insert|update|delete|replace|drop|alter|create|truncate|rename|grant|revoke|set|call|do|handler|load|lock|unlock|outfile|dumpfile|load_file|sleep|benchmark|get_lock|release_lock|release_all_locks|is_free_lock|is_used_lock)\b|\bfor\s+update\b|\bshare\s+mode\b/i';

    // SHOW only for the schema. Other forms read server settings, grants and the
    // live process list.
    private const SCHEMA_SHOW = '/^\s*show\s+(full\s+)?(tables|columns|fields|index|indexes|keys|create\s+table)\b/i';

    // Tables and columns that hold secrets. Refused by name, so they cannot be aliased.
    private const SECRET_NAMES = '/\b(password|remember_token|sessions|password_reset_tokens|personal_access_tokens|cache|cache_locks)\b/i';

    // Result columns blanked even when they arrive through SELECT *.
    private const SECRET_COLUMNS = ['password', 'remember_token', 'token', 'payload'];

    /**
     * @return array{columns: array<int, string>, rows: array<int, array<string, mixed>>, row_count: int, truncated: bool}
     *
     * @throws InvalidArgumentException when the statement is not a single safe read
     */
    public function run(ConnectionInterface $connection, string $sql, int $maxRows, int $timeoutMs, int $maxCellLength): array
    {
        $this->assertSafeRead($sql);
        $this->restrictSession($connection, $timeoutMs);

        $rows = [];
        $truncated = false;
        foreach ($connection->cursor($sql) as $row) {
            if (count($rows) === $maxRows) {
                $truncated = true;
                break;
            }
            $rows[] = $this->shorten($this->redact((array) $row), $maxCellLength);
        }

        return [
            'columns' => $rows === [] ? [] : array_keys($rows[0]),
            'rows' => $rows,
            'row_count' => count($rows),
            'truncated' => $truncated,
        ];
    }

    private function assertSafeRead(string $sql): void
    {
        if (! preg_match(self::READ_STATEMENT, $sql)) {
            throw new InvalidArgumentException('Only SELECT, WITH, SHOW, DESCRIBE and EXPLAIN are allowed.');
        }

        // MySQL runs the inside of /*! … */, and /*+ … */ hints can lift the time limit.
        if (preg_match('/\/\*[!+]/', $sql)) {
            throw new InvalidArgumentException('Executable comments and optimizer hints are not allowed.');
        }

        $bare = $this->withoutQuotedValues($sql);

        if (str_contains(rtrim($bare, " \t\n\r;"), ';')) {
            throw new InvalidArgumentException('Send one statement at a time.');
        }

        $isShow = (bool) preg_match('/^\s*show\b/i', $bare);
        if ($isShow && ! preg_match(self::SCHEMA_SHOW, $bare)) {
            throw new InvalidArgumentException('SHOW is limited to TABLES, COLUMNS, INDEX and CREATE TABLE.');
        }
        if (! $isShow && preg_match(self::WRITE_OR_SIDE_EFFECT, $bare, $match)) {
            throw new InvalidArgumentException('Refused: "'.strtoupper($match[0]).'" is not allowed in a read-only query.');
        }

        if (preg_match(self::SECRET_NAMES, $bare, $match)) {
            throw new InvalidArgumentException('Refused: "'.$match[0].'" holds secrets and cannot be queried.');
        }
    }

    /**
     * Blank the contents of '…' and "…" so words inside values are not judged as SQL.
     * Backticked identifiers are kept: those are table and column names.
     */
    private function withoutQuotedValues(string $sql): string
    {
        return (string) preg_replace(["/'(?:[^'\\\\]|\\\\.|'')*'/s", '/"(?:[^"\\\\]|\\\\.|"")*"/s'], "''", $sql);
    }

    /**
     * A second guard behind the user's grants: the session refuses writes, and a
     * runaway SELECT is stopped instead of loading the server the POS runs on.
     */
    private function restrictSession(ConnectionInterface $connection, int $timeoutMs): void
    {
        $driver = $connection->getDriverName();

        if ($driver === 'mysql') {
            $connection->statement('SET SESSION TRANSACTION READ ONLY');
            $connection->statement('SET SESSION MAX_EXECUTION_TIME = '.$timeoutMs);
        } elseif ($driver === 'mariadb') {
            $connection->statement('SET SESSION TRANSACTION READ ONLY');
            $connection->statement('SET SESSION max_statement_time = '.($timeoutMs / 1000));
        }
    }

    /**
     * Cut long values so one wide text column cannot swell the response.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function shorten(array $row, int $maxCellLength): array
    {
        foreach ($row as $column => $value) {
            if (is_string($value) && mb_strlen($value) > $maxCellLength) {
                $row[$column] = mb_substr($value, 0, $maxCellLength).'…';
            }
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function redact(array $row): array
    {
        foreach ($row as $column => $value) {
            if (in_array(strtolower((string) $column), self::SECRET_COLUMNS, true)) {
                $row[$column] = self::REDACTED;
            }
        }

        return $row;
    }
}
