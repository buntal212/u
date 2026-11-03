<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureServerToServerAuthentication
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedToken = config('services.uih.server_token');

        // If no token is configured in environment, permit in local/testing only for safety
        if (empty($expectedToken) && ! app()->environment(['local', 'testing'])) {
            return response()->json([
                'message' => 'S2S authentication token is not configured on the server.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $authHeader = $request->header('Authorization');
        $bearerToken = null;

        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            $bearerToken = substr($authHeader, 7);
        }

        if (empty($bearerToken) || (! empty($expectedToken) && ! hash_equals($expectedToken, $bearerToken))) {
            return response()->json([
                'message' => 'Unauthenticated server-to-server request.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
