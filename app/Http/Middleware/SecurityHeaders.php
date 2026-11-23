<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every response (#5533, the same shape as MDG's), sent by the app:
 * Forge's nginx adds X-Frame-Options and nosniff to 2xx/3xx responses only, and no HSTS.
 * Global middleware, so error pages and the routes outside the web group (/version, the
 * run-log intake) get them too.
 *
 * - frame-ancestors 'none': nothing frames this app, its own pages included. No
 *   X-Frame-Options here: nginx already sends SAMEORIGIN and frame-ancestors wins over it.
 * - No full CSP: the layout's dark-mode script is inline, so script-src would need a nonce
 *   or hash on every page. Only the directives that need no allow-list are set.
 * - A page that sets its own Referrer-Policy keeps it (the share page sends no-referrer, so
 *   its token never leaves in a Referer).
 */
class SecurityHeaders
{
    public const CONTENT_SECURITY_POLICY = "base-uri 'self'; object-src 'none'; frame-ancestors 'none'";

    public const PERMISSIONS_POLICY = 'camera=(), microphone=(), geolocation=(), payment=(), usb=()';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // A year, no includeSubDomains and no preload yet (#5533). Browsers ignore it on
        // plain-http responses, so local dev is unaffected.
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        $response->headers->set('Content-Security-Policy', self::CONTENT_SECURITY_POLICY);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Permissions-Policy', self::PERMISSIONS_POLICY);

        if (! $response->headers->has('Referrer-Policy')) {
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }

        return $response;
    }
}
