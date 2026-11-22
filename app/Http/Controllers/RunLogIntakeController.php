<?php

namespace App\Http\Controllers;

use App\Models\ServerRun;
use App\Servers\RunLog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /runs/{run}/log (#5367): the log shipper on a run's VPS sends new lines as plain
 * text, with the run's own bearer token. Outside the web middleware (no session, no CSRF),
 * throttled per run.
 *
 *   204 kept · 404 unknown run or wrong token (the same answer, so a token can't be
 *   guessed run by run) · 410 the run is over (the shipper stops) · 413 too big
 */
class RunLogIntakeController extends Controller
{
    public function __invoke(Request $request, string $run, RunLog $log): Response
    {
        $model = ServerRun::query()->find((int) $run);

        if ($model === null || ! $log->tokenMatches($model, (string) $request->bearerToken())) {
            return response('', 404);
        }

        if ($model->ended_at !== null) {
            return response('', 410);
        }

        $body = $request->getContent();

        if (strlen($body) > RunLog::MAX_BATCH_BYTES) {
            return response('', 413);
        }

        $source = $request->header('X-MVM-Source') === 'setup' ? 'setup' : 'minecraft';
        $log->append($model, $source, $body);

        return response('', 204);
    }
}
