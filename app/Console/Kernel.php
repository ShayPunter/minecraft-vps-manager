<?php

namespace App\Console;

use App\Http\Controllers\ServerMonitorController;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Models\Server;
use App\Models\ServerProgress;
use xPaw\MinecraftPing;
use xPaw\MinecraftPingException;
use phpseclib3\Net\SSH2;
use Symfony\Component\HttpClient\HttpClient;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->call(function() {
            $servers = Server::all();

        foreach ($servers as $server) {

            try {
                $query = new MinecraftPing($server->ip_address, 25565);

                if ($query->Query()['players']['online'] == 0) {
                    $server->last_activity = $server->last_activity + 1;
                    $server->save();
                } else {
                    $server->last_activity = 1;
                    $server->save();
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

                    $sprg = ServerProgress::where('server_id', '=', $server->server_id)->get()->first();
                    ServerProgress::destroy($sprg->id);
                    Server::destroy($server->id);
                }

            } catch (MinecraftPingException $e) {
                $server->last_activity = $server->last_activity + 1;
                $server->save();
            }
        }
        })->everyMinute();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}