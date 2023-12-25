<?php

namespace App\Http\Controllers;

use App\Models\Server;
use Illuminate\Http\JsonResponse;

class APIController extends Controller
{
    /**
     * Get all servers from the database and return as JSON
     */
    public function get_servers(): JsonResponse
    {
        return response()->json(Server::all());
    }

    public function get_server_id($servername): JsonResponse
    {
        return response()->json(Server::where('server_id', '=', $servername)->get()->first());
    }
}
