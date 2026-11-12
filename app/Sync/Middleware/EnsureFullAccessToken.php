<?php

namespace App\Sync\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a token without the `*` ability, so a narrow token (such as the publications read token)
 * reaches nothing but the route made for it.
 *
 * Implements AuthenticatesRequests so the middleware priority list runs it with `auth:sanctum`,
 * before route-model binding: a refused token gets 403, never a 404 that reveals a row.
 */
class EnsureFullAccessToken implements AuthenticatesRequests
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token !== null && $token->cant('*')) {
            abort(403, 'This token cannot access this route.');
        }

        return $next($request);
    }
}
