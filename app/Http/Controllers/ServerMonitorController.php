<?php

namespace App\Http\Controllers;

use App\Models\Server;
use phpseclib3\Net\SSH2;
use Symfony\Component\HttpClient\HttpClient;
use xPaw\MinecraftPing;
use xPaw\MinecraftPingException;

class ServerMonitorController extends Controller
{
    /**
     * Check to see if the server is online or not & update database accordingly
     *
     * @return void
     */
    public function check_minecraft_status($server_ip)
    {
        $server = Server::where('server_ip', '=', $server_ip)->get()->first();

        try {
            $query = new MinecraftPing($server_ip, 25565);
            $query->Query();

            $server->status = 'online';
            $server->save();
        } catch (MinecraftPingException $e) {
            $server->status = 'starting_up';
            $server->save();
        }

    }

    /**
     * Check if the servers have any players on and update activity accordingly so
     *
     * @return void
     */
    public function check_if_servers_active()
    {
        $servers = Server::all();

        foreach ($servers as $server) {

            try {
                $query = new MinecraftPing($server->ip_address, 25565);

                if ($query->Query()['players']['online'] == 0) {
                    $server->last_activity = $server->last_activity++;
                    $server->save();
                } else {
                    $server->last_activity = 0;
                    $server->save();
                }

                if ($server->last_activity >= 15) {
                    $server->status = 'shuttingdown';
                    $server->save();

                    $opts = [
                        'socket' => [
                            'bindto' => $server->ip_address,
                        ],
                    ];
                    $context = stream_context_create($opts);
                    $socket = stream_socket_client('tcp://'.$server->ip_address.':22', $errno, $errstr, ini_get('default_socket_timeout'), STREAM_CLIENT_CONNECT, $context);

                    $ssh = new SSH2($socket);

                    if (! $ssh->login('root', env('LINODE_PASS'))) {
                        throw new \Exception('Login failed');
                    }

                    sleep(2);
                    $ssh->exec('screen -S server -X stuff \'stop\n\'');
                    sleep(60);

                    // Http client
                    $client = HttpClient::createForBaseUri('https://api.linode.com/', [
                        'auth_bearer' => env('LINODE_API'),
                    ]);

                    // Send request to setup a Linode 8GB Dedicated
                    $response = $client->request('DELETE', 'https://api.linode.com/v4/linode/instances/'.$server->id);

                    $server->delete();
                }

            } catch (MinecraftPingException $e) {
                $server->last_activity = $server->last_activity++;
                $server->save();
            }

        }
    }
}
