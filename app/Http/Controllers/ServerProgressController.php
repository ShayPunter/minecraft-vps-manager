<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use App\Models\ServerProgress;

class ServerProgressController extends Controller
{
    public function server_progress($servername): JsonResponse
    {
        $progress = ServerProgress::where('server_id', '=', $servername)->get()->first();

        return response()->json(['ticker', $progress->progress]);
    }
}
