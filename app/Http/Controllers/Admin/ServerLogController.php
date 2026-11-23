<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerRun;
use App\Models\ServerRunLog;
use App\Servers\RunLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Servers → Log (#5367): a run's log, live while it starts or runs, and kept after
 * the VPS is gone. Admins only (the route group): Minecraft logs player names and IPs.
 */
class ServerLogController extends Controller
{
    private const SHOWN_LINES = 1000;

    private const RUNS_LISTED = 10;

    public function show(Request $request, Server $server): Response
    {
        $runs = $server->runs()->orderByDesc('id')->limit(self::RUNS_LISTED)->get();
        $run = $this->pickRun($request, $server, $runs->first());

        $lines = $run === null ? [] : ServerRunLog::query()
            ->where('server_run_id', $run->id)
            ->orderByDesc('id')
            ->limit(self::SHOWN_LINES)
            ->get(['id', 'source', 'line', 'created_at'])
            ->reverse()
            ->values()
            ->map(fn (ServerRunLog $line): array => self::line($line))
            ->all();

        return Inertia::render('admin/servers/Log', [
            'server' => ['name' => $server->name, 'slug' => $server->slug],
            'runs' => $runs->map(fn (ServerRun $r): array => [
                'id' => $r->id,
                'status' => $r->status->value,
                'status_label' => $r->status->label(),
                'started_at' => $r->created_at?->toIso8601String(),
                'ended_at' => $r->ended_at?->toIso8601String(),
            ])->all(),
            'run' => $run === null ? null : [
                'id' => $run->id,
                'status_label' => $run->status->label(),
                'active' => $run->ended_at === null,
                'error' => $run->error,
                'started_at' => $run->created_at?->toIso8601String(),
            ],
            'lines' => $lines,
            'retentionDays' => RunLog::RETENTION_DAYS,
            // The live view keeps at most this many lines, like the run itself (#5753).
            'maxLines' => RunLog::MAX_LINES,
        ]);
    }

    /**
     * New lines since `after`, for the page's polling.
     */
    public function lines(Request $request, Server $server, RunLog $log): JsonResponse
    {
        $run = $this->pickRun($request, $server, null);

        if ($run === null) {
            abort(404);
        }

        return response()->json([
            'lines' => $log->after($run, max(0, $request->integer('after')))
                ->map(fn (ServerRunLog $line): array => self::line($line))->all(),
            'active' => $run->ended_at === null,
            'status_label' => $run->status->label(),
            'error' => $run->error,
        ]);
    }

    private function pickRun(Request $request, Server $server, ?ServerRun $default): ?ServerRun
    {
        if (! $request->filled('run')) {
            return $default;
        }

        $run = $server->runs()->whereKey($request->integer('run'))->first();

        return $run ?? abort(404);
    }

    /**
     * @return array{id: int, source: string, line: string, at: string|null}
     */
    private static function line(ServerRunLog $line): array
    {
        return ['id' => $line->id, 'source' => $line->source, 'line' => $line->line, 'at' => $line->created_at?->toIso8601String()];
    }
}
