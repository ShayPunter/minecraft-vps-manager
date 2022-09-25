<?php

namespace App\Http\Controllers;

use App\Enums\LinodeRegion;
use App\Enums\LinodeType;
use App\Jobs\StartServer;
use App\Models\Server;
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
        // Check for duplicates
        $server_exists = Server::where('server_id', '=', $servername)->get()->first();
        if (!empty($server_exists)) {
            return 'server already exists';
        }

        // Http client
        $client = HttpClient::createForBaseUri('https://api.linode.com/', [
            'auth_bearer' => env('LINODE_API'),
        ]);

        // Send request to setup a Linode 8GB Dedicated
        // Note: to make configurable in the future
        $response = $client->request('POST', 'https://api.linode.com/v4/linode/instances', [
            'json' => ['backups_enabled' => false,
                'image' => 'linode/ubuntu22.04',
                'region' => LinodeRegion::EU_CENTRAL,
                'root_pass' => env('LINODE_PASS'),
                'type' => LinodeType::DEDICATED_LINODE_8GB,
                'stackscript_id' => 1024018,
                'label' => 'mc-' . $servername . '-' . $this->generateRandomString()]
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
        $server->status = 'provisioning';
        $server->ip_address = $data['ipv4'][0];
        $server->last_activity = 1;
        $server->save();
    }

    /**
     * Gets the server from the database and returns to the frontend
     */
    public function get_server($servername) {
        $server = Server::where('server_id', '=', $servername)->get()->first();

        if ($server == null) {
            return response()->json(['error' => 'server not found']);
        }

        return response()->json($server);
    }

    // Generates a random string
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