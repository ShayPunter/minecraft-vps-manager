<?php

namespace App\Jobs;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use phpseclib3\Net\SSH2;

class StartServer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    private $servername = '';

    private $ip = '';

    public function __construct($ip, $servername)
    {
        $this->servername = $servername;
        $this->ip = $ip;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // Bind to IP and connect to the server
        $opts = [
            'socket' => [
                'bindto' => $this->ip,
            ],
        ];
        $context = stream_context_create($opts);
        $socket = stream_socket_client('tcp://'.$this->ip.':22', $errno, $errstr, ini_get('default_socket_timeout'), STREAM_CLIENT_CONNECT, $context);

        $ssh = new SSH2($socket);

        if (! $ssh->login('root', env('LINODE_PASS'))) {
            throw new \Exception('Login failed');
        }

        sleep(2);

        // Check if volume has been mounted (to verify)
        $ssh->exec('cd ../mnt/Minecraft/', function ($callback) {
            if ($callback == 'bash: cd: ../mnt/Minecraft/atm8: No such file or directory') {
                sleep(30);
            } // todo: update status & reschedule job to run in 1 minute.
        });

        $server = Server::where('server_id', '=', $this->servername)->get()->first();
        $server->status = 'startup';
        $server->save();

        // Run commands and start up the server
        $ssh->exec('screen -dmS '.$this->servername);
        $ssh->exec('screen -S '.$this->servername.' -X stuff \'cd ../mnt/Minecraft/'.$this->servername.'\n\'');
        sleep(1);
        $ssh->exec('screen -S '.$this->servername.' -X stuff \'./run.sh\n\'');
    }
}
