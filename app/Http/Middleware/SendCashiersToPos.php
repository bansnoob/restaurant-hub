<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A cashier's whole job is the POS (the iPad app at /pos/, which talks to the API).
 * The website is the owner's, so a cashier who lands on any page here is sent to
 * the POS. Signing out lives outside the routes this guards, so it still works.
 */
class SendCashiersToPos
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->hasRole('cashier') && ! $user->canManage()) {
            return redirect('/pos/');
        }

        return $next($request);
    }
}
