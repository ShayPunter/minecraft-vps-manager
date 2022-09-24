<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Server;
use App\Models\ServerProgress;
use phpseclib3\Net\SSH2;
use Symfony\Component\HttpClient\HttpClient;

class StartServer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    private $servername = "";
    public function __construct($servername)
    {
        $this->servername = $servername;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {

        $server_exists = ServerProgress::where('server_id', '=', $this->servername)->get()->first();
        if (!empty($server_exists)) {
            return 'server already exists';
        }

        $serverprogress = new ServerProgress();
        $serverprogress->server_id = $this->servername;
        $serverprogress->progress = 1;
        $serverprogress->save();

        // Http client
        $client = HttpClient::createForBaseUri('https://api.linode.com/', [
            'auth_bearer' => env('LINODE_API'),
        ]);

        // Send request to setup a Linode 8GB Dedicated
        $response = $client->request('POST', 'https://api.linode.com/v4/linode/instances', [
            'json' => ['backups_enabled' => false,
                'image' => 'linode/ubuntu22.04',
                'region' => 'eu-central',
                'root_pass' => env('LINODE_PASS'),
                'type' => 'g6-dedicated-4',
                'stackscript_id' => 1024018,
                'label' => 'mc-' . $this->servername . '-' . $this->generateRandomString()]
        ]);

        // Convert request to array
        $data = $response->toArray();

        // Send request to assign Volume to the new Linode
        $client->request('POST', 'https://api.linode.com/v4/volumes/410636/attach', [
            'json' => ['linode_id' => $data['id'] ]
        ]);

        // Store the server in the database
        $server = new Server();
        $server->id = $data['id'];
        $server->server_id = $this->servername;
        $server->status = 'starting_up';
        $server->ip_address = $data['ipv4'][0];
        $server->last_activity = 1;
        $server->save();

        $serverprogress->progress = 10;
        $serverprogress->ip_address = $data['ipv4'][0];
        $serverprogress->save();

        // Navigate and startup the minecraft server
        sleep(30);
        $serverprogress->progress = 20;
        $serverprogress->save();
        sleep(30);
        $serverprogress->progress = 30;
        $serverprogress->save();
        sleep(30);
        $serverprogress->progress = 35;
        $serverprogress->save();
        sleep(10);
        $serverprogress->progress = 37;
        $serverprogress->save();
        sleep(10);
        $serverprogress->progress = 40;
        $serverprogress->save();
        sleep(10);
        $serverprogress->progress = 42;
        $serverprogress->save();
        sleep(30);
        $serverprogress->progress = 50;
        $serverprogress->save();
        sleep(20);
        $serverprogress->progress = 55;
        $serverprogress->save();


        // Bind to IP and connect to the server
        $opts = array(
            'socket' => array(
                'bindto' => $server->ip_address,
            ),
        );
        $context = stream_context_create($opts);
        $socket = stream_socket_client('tcp://' . $server->ip_address . ':22', $errno, $errstr, ini_get('default_socket_timeout'), STREAM_CLIENT_CONNECT, $context);

        $ssh = new SSH2($socket);

        if (!$ssh->login('root', env('LINODE_PASS')))
            throw new \Exception('Login failed');

        sleep(2);
        $serverprogress->progress = 60;
        $serverprogress->save();

        // Check if correct java version is installed (to verify)
        $ssh->exec('java --version', function($callback) {
            //echo $callback;
        });

        // Check if volume has been mounted (to verify)
        $ssh->exec('cd ../mnt/Minecraft/', function($callback) {
            //echo $callback;
        });

        $serverprogress->progress = 65;
        $serverprogress->save();

        // Run commands and start up the server
        $ssh->exec('screen -dmS ' . $this->servername);
        $ssh->exec('screen -S '. $this->servername .' -X stuff \'cd ../mnt/Minecraft/' . $this->servername . '\n\'');
        sleep(1);
        $ssh->exec('screen -S '. $this->servername .' -X stuff \'./run.sh\n\'');

        $serverprogress->progress = 70;
        $serverprogress->save();

        sleep(90);
        $serverprogress->progress = 85;
        $serverprogress->save();
        sleep(90);
        $serverprogress->progress = 100;
        $serverprogress->save();

        sleep(10);
        $server->status = 'online';
        $server->save();
    }

    private function generateRandomString($length = 8) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyz';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }
}