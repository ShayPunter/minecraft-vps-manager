<?php

namespace App\Http\Controllers;

use App\Jobs\StartServer;

class LinodeController extends Controller
{

    /**
     * Create a new server in Linode with a StackScript
     *
     * @return void
     * @throws \Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    public function create_server($servername) {
        StartServer::dispatch($servername)->onQueue('server_start');
    }
}