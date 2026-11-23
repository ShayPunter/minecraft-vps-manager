<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Servers\ServerLauncher;
use App\Servers\ServerPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * A link-only server's share page (#5462): `/s/{token}` shows that one server to anyone who
 * has the link, signed in or not, and lets them start it. An unknown token, an old one after
 * "New link", or a server that's no longer link only is a 404, like a private server for guests.
 */
class SharedServerController extends Controller
{
    /** Server::fillShareToken() makes 40; the route refuses anything else early. */
    public const TOKEN_PATTERN = '[A-Za-z0-9]{40}';

    public function show(Request $request, string $token, ServerLauncher $launcher): Response
    {
        $server = $this->find($token);
        $launcher->advanceActive();
        $server->load(['activeRun', 'latestRun']);

        $response = Inertia::render('Shared', [
            'server' => ServerPresenter::one($server, $request->user()),
            'token' => $token,
        ])->toResponse($request);

        // The token is in the URL: keep it out of Referer headers and search engines.
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    public function start(Request $request, string $token, ServerLauncher $launcher): RedirectResponse
    {
        return ServerStartController::launch($request, $this->find($token), $launcher);
    }

    private function find(string $token): Server
    {
        return Server::findByShareToken($token) ?? abort(404);
    }
}
