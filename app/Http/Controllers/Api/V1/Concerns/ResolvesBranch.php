<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Branch scoping for the mobile API.
 *
 * `branch_id` is honoured from the request ONLY for owners. A non-owner who
 * asks for somebody else's branch is REFUSED (403), not quietly re-scoped to
 * their own: answering a question nobody asked hides the attempt from the
 * caller and from the logs, and a client that reads the `branch_id` back out
 * of the payload would silently cache the wrong branch's rows. The mobile
 * client never sends the key at all, so this only ever fires on tampering.
 */
trait ResolvesBranch
{
    protected function resolveBranchId(Request $request): int
    {
        $user = $request->user();
        $requested = $request->filled('branch_id') ? $request->integer('branch_id') : null;

        if ($user->hasRole('owner')) {
            return $requested ?? $this->ownBranchId($user);
        }

        $ownBranchId = $this->ownBranchId($user);
        abort_if(
            $requested !== null && $requested !== $ownBranchId,
            403,
            'You cannot access inventory from another branch.'
        );

        return $ownBranchId;
    }

    private function ownBranchId(User $user): int
    {
        $branchId = $user->resolveBranchId();
        abort_unless($branchId, 403, 'User is not linked to any branch.');

        return $branchId;
    }

    /**
     * Guard a route-model-bound record. Owners may touch any branch; everybody
     * else is confined to their own.
     */
    protected function authorizeBranchId(
        Request $request,
        int $branchId,
        string $message = 'You cannot access an ingredient from another branch.',
    ): void {
        $user = $request->user();

        if ($user->hasRole('owner')) {
            return;
        }

        $ownBranchId = $user->resolveBranchId();
        abort_unless($ownBranchId, 403, 'User is not linked to any branch.');
        abort_unless($branchId === $ownBranchId, 403, $message);
    }
}
