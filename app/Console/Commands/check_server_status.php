<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Server;
use App\Models\ServerProgress;
use xPaw\MinecraftPing;
use xPaw\MinecraftPingException;
use phpseclib3\Net\SSH2;
use Symfony\Component\HttpClient\HttpClient;

class check_server_status extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'server:heartbeat';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Checks the servers heartbeats';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $servers = Server::all();

        foreach ($servers as $server) {

            try {
                $query = new MinecraftPing($server->ip_address, 25565);

                if ($server->status != 'online') {
                    $server->status = 'online';
                    $server->save();

                    $serverprogress = ServerProgress::where('server_id', '=', $server->server_id)->get()->first();
                    $serverprogress->progress = 100;
                    $serverprogress->save();
                }

                if ($query->Query()['players']['online'] == 0) {
                    $server->last_activity = $server->last_activity + 1;
                    $server->save();
                    $this->info('no players online');
                } else {

                    if ($server->status != 'online') {
                        $server->status = 'online';
                        $server->save();

                        $serverprogress = ServerProgress::where('server_id', '=', $server->server_id)->get()->first();
                        $serverprogress->progress = 100;
                        $serverprogress->save();
                    }

                    $server->last_activity = 1;
                    $server->save();
                    $this->info('players online');
                }

                if ($server->last_activity >= 15) {
                    $server->status = "shuttingdown";
                    $server->save();

                    $opts = array(
                        'socket' => array(
                            'bindto' => $server->ip_address,
                        ),
                    );
                    $context = stream_context_create($opts);
                    $socket = stream_socket_client('tcp://'. $server->ip_address . ':22', $errno, $errstr, ini_get('default_socket_timeout'), STREAM_CLIENT_CONNECT, $context);

                    $ssh = new SSH2($socket);

                    if (!$ssh->login('root', env('LINODE_PASS')))
                        throw new \Exception('Login failed');

                    sleep(2);
                    $ssh->exec('screen -S server -X stuff \'stop\n\'');
                    sleep(60);

                    // Http client
                    $client = HttpClient::createForBaseUri('https://api.linode.com/', [
                        'auth_bearer' => env('LINODE_API'),
                    ]);

                    // Send request to setup a Linode 8GB Dedicated
                    $response = $client->request('DELETE', 'https://api.linode.com/v4/linode/instances/' . $server->id);

                    Server::destroy($server->id);
                }

            } catch (MinecraftPingException $e) {
                $server->last_activity = $server->last_activity + 1;
                $server->save();
                $this->info('an error: HB: ' . $server->last_activity);
            }
        }
    }
}