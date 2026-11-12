<?php

namespace App\Sync\Middleware;

use App\Sync\Models\PrismAthletePublication;
use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets through only the dedicated publications read token. A full-access (`*`) token is refused,
 * so a normal user token cannot read publications.
 *
 * Implements AuthenticatesRequests so the middleware priority list runs it with `auth:sanctum`,
 * before route-model binding: a refused token gets 403, never a 404 that reveals a row.
 */
class EnsureAthletePublicationsReadToken implements AuthenticatesRequests
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->tokenCan(PrismAthletePublication::READ_ABILITY) || $user->tokenCan('*')) {
            abort(403, 'This route needs the publications read token.');
        }

        return $next($request);
    }
}
