<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out an account that has been deactivated (Users page) on its next request,
 * wherever it was already signed in. Sign-in itself is refused separately
 * (LoginRequest, the API's AuthController).
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        // Only an explicit false: a user model built in memory, not yet re-read,
        // has no is_active at all, and the column defaults to active.
        $user = $request->user();
        if ($user === null || $user->is_active !== false) {
            return $next($request);
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            $user->currentAccessToken()?->delete();

            return response()->json(['message' => 'This account has been deactivated.'], 401);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors(['email' => 'This account has been deactivated.']);
    }
}
