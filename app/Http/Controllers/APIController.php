<?php

namespace App\Http\Controllers;

use App\Models\Server;
use Illuminate\Http\Request;

class APIController extends Controller
{
    /**
     * Get all servers from the database and return as JSON
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function get_servers() {
        return response()->json(Server::all());
    }

    public function get_server_id($servername) {
        return response()->json(Server::where('server_id', '=', $servername)->get()->first());
    }
}