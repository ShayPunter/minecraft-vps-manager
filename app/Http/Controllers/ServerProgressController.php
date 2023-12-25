<?php

namespace App\Http\Controllers;

use App\Models\ServerProgress;

class ServerProgressController extends Controller
{
    public function server_progress($servername)
    {
        $progress = ServerProgress::where('server_id', '=', $servername)->get()->first();

        return response()->json(['ticker', $progress->progress]);
    }
}
