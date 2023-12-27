<?php

namespace App\Http\Controllers;

use App\Enums\LinodeRegion;
use App\Enums\LinodeType;
use App\Models\Node;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\HttpClient;

class LinodeController extends Controller
{
    /**
     * Create a new server in Linode with a StackScript
     *
     * @return void
     *
     * @throws \Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    public function create_server($server_id)
    {
        $server = Server::where('server_id', '=', $server_id)->get()->first();

        // Check to see if there is a server node existing
        $node = Node::where('ip_address', '=', $server->ip_address)->get()->first();

        // Check for duplicates
        if (! empty($node)) {
            return 'server already online.';
        }

        // Http client
        $client = HttpClient::createForBaseUri('https://api.linode.com/', [
            'auth_bearer' => env('LINODE_API'),
        ]);

        // Send request to setup a Linode 8GB Dedicated
        // Note: to make configurable in the future

        error_log($server->server_size);

        try {
            $response = $client->request('POST', 'https://api.linode.com/v4/linode/instances', [
                'json' => ['backups_enabled' => false,
                    'image' => 'linode/ubuntu22.04',
                    'region' => LinodeRegion::EU_CENTRAL,
                    'root_pass' => env('LINODE_PASS'),
                    'type' => $server->server_size,
                    'label' => $server_id],
            ]);
        } catch (ClientException $e) {
            error_log($e);
        }

        // Convert request to array
        $data = $response->toArray();

        // Store the node in the database and update server with new IP
        $new_node = new Node();
        $new_node->node_id = $data['id'];
        $new_node->ip_address = $data['ipv4'][0];
        $new_node->provider = 'linode';
        $new_node->save();

        $server->status = 'provisioning';
        $server->ip_address = $data['ipv4'][0];
        $server->last_activity = 1;
        $server->save();
    }

    /**
     * Gets the server from the database and returns to the frontend
     */
    public function get_server($servername): JsonResponse
    {
        $server = Server::where('server_id', '=', $servername)->get()->first();

        if ($server == null) {
            return response()->json(['error' => 'server not found']);
        }

        return response()->json($server);
    }

    // Generates a random string
    private function generateRandomString($length = 8)
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyz';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }

        return $randomString;
    }

    public function getLinodeTypes()
    {
        $types = [];
        foreach (LinodeType::cases() as $case) {
            $types[] = [
                'id' => $case->value,
                'name' => $case->name,
                // You can also add additional info like CPU, RAM, etc. here
            ];
        }

        return response()->json($types);
    }
}
