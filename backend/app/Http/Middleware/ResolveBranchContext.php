<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\BranchAuthorizationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveBranchContext
{
    public function __construct(private readonly BranchAuthorizationService $branches) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() instanceof User) {
            return response()->json(['message' => $request->user() ? 'Token ini tidak berwenang untuk endpoint ini.' : 'Unauthenticated.'], $request->user() ? 403 : 401);
        }

        $request->attributes->set('branch_id', $this->branches->fromRequest($request));

        return $next($request);
    }
}
