<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEngineeringReferenceEndpointIsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array(config('app.env'), ['local', 'testing'], true)) {
            abort(404, 'Not Found.');
        }

        return $next($request);
    }
}
