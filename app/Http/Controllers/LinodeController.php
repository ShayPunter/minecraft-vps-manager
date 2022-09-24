<?php

namespace App\Http\Controllers;

use App\Models\Server;
use phpseclib3\Net\SSH2;
use Symfony\Component\HttpClient\HttpClient;

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
                'label' => 'mc-' . $this->generateRandomString()]
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
        $server->server_id = $servername;
        $server->status = 'starting_up';
        $server->ip_address = $data['ipv4'][0];
        $server->last_activity = 1;
        $server->save();

        // Navigate and startup the minecraft server
        sleep(180);

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

        $ssh->exec('java --version', function($callback) {
            echo $callback;
        });

        $ssh->exec('cd ../mnt/Minecraft/', function($callback) {
            echo $callback;
        });

        $ssh->exec('screen -dmS server');
        $ssh->exec('screen -S server -X stuff \'cd ../mnt/Minecraft/' . $servername . '\n\'');
        sleep(1);
        $ssh->exec('screen -S server -X stuff \'./run.sh\n\'');

        sleep(2);

        echo $server->ip_address;
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