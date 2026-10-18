<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Reading list-page filters from the query string without a 500.
 *
 * The pages' own filter forms only ever send plain strings and real dates, but a
 * hand-edited or garbled URL can send `?branch_id[]=1` (an array, which breaks the
 * moment it is cast to a string or printed back into the form) or `?date=2026-13-45`
 * (which Carbon refuses to parse). Either way the filter is ignored, not fatal.
 */
trait ReadsQueryFilters
{
    /** A query-string value, or $default when it is missing or not a plain string. */
    protected function queryValue(Request $request, string $key, string $default = ''): string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : $default;
    }

    /** A query-string date as Y-m-d, or $fallback when it is missing or not a date. */
    protected function queryDate(Request $request, string $key, string $fallback): string
    {
        return $this->dateOr($this->queryValue($request, $key), $fallback);
    }

    /** $value as a Y-m-d date, or $fallback when it is empty or not a date. */
    protected function dateOr(string $value, string $fallback): string
    {
        if ($value === '') {
            return $fallback;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
