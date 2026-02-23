<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        if (!$request->user() || !$request->user()->role) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $userRole = $this->normalizeRole($request->user()->role->name);
        $allowed = array_map([$this, 'normalizeRole'], $roles);

        if (!in_array($userRole, $allowed, true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return $next($request);
    }

    private function normalizeRole(string $role): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim($role)));
    }
}
