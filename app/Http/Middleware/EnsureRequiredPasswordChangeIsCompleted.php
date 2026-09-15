<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wajib ganti password: while the signed-in user's `must_change_password`
 * is on (set by "Beri Akses" and by an admin password reset), refuses the
 * request with 403 and a code the client recognises, so it sends the user
 * to the change-password page.
 *
 * Runs on every API route, after authentication (see the priority list in
 * bootstrap/app.php); a route without a signed-in user passes untouched.
 * The own profile, the password change and logout opt out in routes/api.php.
 * super_admin is not exempt.
 */
class EnsureRequiredPasswordChangeIsCompleted
{
    public const ERROR_CODE = 'PASSWORD_CHANGE_REQUIRED';

    public const ERROR_MESSAGE = 'Anda harus mengganti password sebelum melanjutkan.';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password === true) {
            return response()->json([
                'success' => false,
                'message' => self::ERROR_MESSAGE,
                'code' => self::ERROR_CODE,
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
