<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

class AdminAPI
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        if ($request->api_token != null) {
            $user = User::where('api_token', '=', $request->api_token)->get()->first();

            if ($user == null || $user->role != 'admin') {
                return response()->json(['error' => 'Not Authorized', 'a' => $request->api_token], 403);
            }

            return $next($request);
        }

        return response()->json(['error' => 'Not Authorized', 'a' => $request->api_token], 403);
    }
}
