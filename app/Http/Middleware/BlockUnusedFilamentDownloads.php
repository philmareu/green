<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Filament registers download routes for its export and import actions, which
 * Green doesn't use. They look up tables Green doesn't have, so every request
 * to one, signed in or not, would otherwise write a database error to the log.
 */
class BlockUnusedFilamentDownloads
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $prefix = trim((string) config('filament.system_route_prefix', 'filament'), '/');

        abort_if($request->is("{$prefix}/exports/*", "{$prefix}/imports/*"), 404);

        return $next($request);
    }
}
