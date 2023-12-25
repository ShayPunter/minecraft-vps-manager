<?php

namespace App\Console\Commands;

use App\Jobs\StartServer;
use App\Models\Server;
use Illuminate\Console\Command;
use Symfony\Component\HttpClient\HttpClient;

class ServerProvisionStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'server:provision_status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Checks the status of a server to see if it has been deployed successfully';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $servers = Server::all();
        if (empty($servers)) {
            return 0;
        }

        foreach ($servers as $server) {
            // If linode is online, installing or starting up, continue to next in for loop.
            if ($server->status == 'online' || $server->status == 'installing' || $server->status == 'startup') {
                continue;
            }

            // Http client
            $client = HttpClient::createForBaseUri('https://api.linode.com/', [
                'auth_bearer' => env('LINODE_API'),
            ]);

            // Send request to get linode and its status
            $response = $client->request('GET', 'https://api.linode.com/v4/linode/instances/'.$server->id);
            $data = json_decode($response->getContent());

            // Update server status
            $server->status = $data->status;
            $server->save();

            // If status is running, update to new status and pass to job queue
            if ($data->status == 'running') {
                $server->status = 'installing';
                $server->save();

                StartServer::dispatch($server->ip_address, $server->server_id)->onQueue('server_start')->delay(now()->addMinutes(1));
            }
        }
    }
}
