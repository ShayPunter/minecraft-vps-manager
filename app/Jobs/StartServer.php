<?php

namespace App\Jobs;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use phpseclib3\Net\SSH2;
use phpseclib3\Net\SFTP;

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
     */
    public function handle(): void
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

        // Update the server and install required pieces
        $ssh->exec('apt update');

        sleep(30);

        $server = Server::where('server_id', '=', $this->servername)->get()->first();
        $server->status = 'startup';
        $server->save();

        // Fetch the file contents from the server
        $fileContents = file_get_contents($server->file);

        // Define the directory path where you want to create the new directory
        $remoteDirectory = '/home/minecraft';

        // Command to create a new directory
        $ssh->exec("mkdir -p " . escapeshellarg($remoteDirectory));

        $sftp = new SFTP($this->ip);
        if (!$sftp->login('root', env('LINODE_PASS'))) {
            throw new \Exception('SFTP login failed');
        }

        $ssh->exec('apt install unzip tmux -y');

        sleep(10);

        // upload the file
        $remoteFilePath = '/home/minecraft/' . basename($server->file);
        if (!$sftp->put($remoteFilePath, $fileContents)) {
            throw new \Exception('File upload failed');
        }

        // Unzip the file on the remote server
        $ssh->exec('unzip -o ' . escapeshellarg($remoteFilePath) . ' -d /home/minecraft');

        $ssh->exec('cd /home/minecraft && rm ' . basename($server->file));

        // Run commands and start up the server
//        $ssh->exec('screen -dmS '.$this->servername);
//        $ssh->exec('screen -S '.$this->servername.' -X stuff \'cd ../mnt/Minecraft/'.$this->servername.'\n\'');
//        sleep(1);
//        $ssh->exec('screen -S '.$this->servername.' -X stuff \'./run.sh\n\'');
    }
}
