<?php

namespace App\Http\Controllers;

use App\Models\ServerProgress;
use Illuminate\Http\JsonResponse;

class ServerProgressController extends Controller
{
    public function server_progress($servername): JsonResponse
    {
        $progress = ServerProgress::where('server_id', '=', $servername)->get()->first();

        return response()->json(['ticker', $progress->progress]);
    }
}
