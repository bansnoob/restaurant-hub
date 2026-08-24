<?php

declare(strict_types=1);

namespace App\Support\Inventory;

use Illuminate\Support\Str;

/**
 * The ONE derivation of an ingredient category's slug, shared by the API
 * controller, the web controller, the inline-create resolver and the default
 * seeder. Two hand-maintained copies of a slug rule drift; this one cannot.
 *
 * Why not bare Str::slug: it returns the EMPTY STRING for any name with no
 * latin characters — Str::slug('醤油') === '' — and this is a ramen kitchen.
 * With a bare slug the SECOND non-latin category would collide on the
 * (branch_id, slug) unique key and 422 as "already exists", and worse, the
 * inline-create path would firstOrCreate onto the '' row and file the
 * ingredient under a completely unrelated shelf with a cheerful 201.
 *
 * The fallback is a hash of the normalised name, so it is DETERMINISTIC (the
 * same name still resolves to the same category, which is what makes
 * create-or-reuse work) while two different names can never collide.
 */
final class CategorySlug
{
    private const FALLBACK_PREFIX = 'cat-';

    private const FALLBACK_LENGTH = 12;

    public static function for(string $name): string
    {
        $slug = Str::slug($name);

        if ($slug !== '') {
            return $slug;
        }

        return self::FALLBACK_PREFIX.substr(hash('sha256', self::normalise($name)), 0, self::FALLBACK_LENGTH);
    }

    /** Case-insensitive, trimmed, internal whitespace runs collapsed. */
    private static function normalise(string $name): string
    {
        return Str::lower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }
}
