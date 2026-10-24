<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Models\PayrollEntry;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Confines a branch admin to their own branch on the website. Everyone else
 * passes straight through.
 *
 *  - Any record named in the URL ({expense}, {sale}, {dayClosure}...) must belong to
 *    the admin's branch, or the request is a 404. A record with no branch (company-
 *    wide overhead, a shared category) is the super admin's.
 *  - Whatever branch the request names, query string or form, it becomes the
 *    admin's own. Every page's existing branch filter and every form's branch field
 *    therefore just work, without each controller knowing about admins.
 *
 * Records named in a form BODY rather than the URL (an attendance record_id, a
 * payroll employee_id) are checked in those controllers.
 */
class LockToOwnBranch
{
    private const BRANCH_KEYS = ['branch_id', 'close_branch_id'];

    public function handle(Request $request, Closure $next): Response
    {
        // Only branch admins. The super admin sees every branch; a cashier has already
        // been sent to the POS ('cashier.pos'); anyone else is stopped by the routes'
        // role checks, so their profile page still works.
        $user = $request->user();
        if ($user === null || ! $user->isBranchAdmin()) {
            // Set on every request, not only an admin's, so a value shared for one
            // request can never linger into the next.
            View::share('lockedBranch', null);

            return $next($request);
        }

        $branchId = $user->lockedBranchId();
        abort_unless($branchId, 403, 'Your account is not assigned to a branch. Ask the super admin to assign one.');

        // The views hide every branch dropdown when this is set.
        View::share('lockedBranch', Branch::find($branchId));

        foreach ($request->route()?->parameters() ?? [] as $value) {
            if ($value instanceof Model && ! $this->belongsTo($value, $branchId)) {
                abort(404);
            }
        }

        // As a string, like any real query or form value: the filter readers
        // (ReadsQueryFilters::queryValue) ignore anything that is not one.
        $forced = (string) $branchId;
        foreach (self::BRANCH_KEYS as $key) {
            if ($key === 'branch_id' || $request->query->has($key)) {
                $request->query->set($key, $forced);
            }
            if ($key === 'branch_id' || $request->request->has($key)) {
                $request->merge([$key => $forced]);
            }
        }

        return $next($request);
    }

    private function belongsTo(Model $model, int $branchId): bool
    {
        if ($model instanceof PayrollEntry) {
            return (int) $model->payrollPeriod?->branch_id === $branchId;
        }

        if (! array_key_exists('branch_id', $model->getAttributes())) {
            // Branch, User: those routes are the super admin's alone (role:owner).
            return false;
        }

        return $model->branch_id !== null && (int) $model->branch_id === $branchId;
    }
}
