<?php

namespace App\Http\Controllers;

use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ServerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // prolly don't need really
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('Admin/Servers/new');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        return Inertia::render('Admin/Servers/edit', ['id' => $id]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        // we need to check if the server is online, if it is, then we need to shutdown the minecraft server, download the files and upload them to
        // them into S3 in their appropriate storage place and then shutdown and delete the node
        // and then lastly schedule the deletion from the database within 24 hours
    }

    public function store(Request $request)
    {
        ini_set('upload_max_filesize', '10G');
        ini_set('post_max_size', '10G');

        $validatedData = $request->validate([
            'name' => 'required|string',
            'provider' => 'required|string',
            'server_size' => 'required|string',
            'is_public' => 'required',
            'file' => 'required|file',
            'server_icon' => 'nullable|file', // Use 'image' for validating image files
        ]);

        // Store the main file and get the URL
        $file_path = $request->file('file')->store('servers', 'public');
        $validatedData['file'] = Storage::disk('public')->url($file_path);

        // Store the server icon if present and get the URL
        if ($request->hasFile('server_icon')) {
            $icon_path = $request->file('server_icon')->store('server_icons', 'public');
            $validatedData['server_icon'] = Storage::disk('public')->url($icon_path);
        }

        // Generate a unique server ID
        $validatedData['server_id'] = $validatedData['name'] . '-' . $this->generateRandomString();

        // Create the server record
        $server = Server::create($validatedData);

        // Return the created server data as JSON
        return response()->redirectTo('dashboard');
    }

    public function update(Request $request, Server $server)
    {
        $validatedData = $request->validate([
            // Add your validation rules here
            'file' => 'required|file',
            'server_icon' => 'nullable|image',
        ]);

        if ($request->hasFile('file')) {
            // Delete the old file if necessary
            if ($server->file) {
                Storage::delete($server->file);
            }

            $validatedData['file'] = $request->file('file')->store('servers', 'public');
        }

        if ($request->hasFile('server_icon')) {
            // Delete the old server icon if necessary
            if ($server->server_icon) {
                Storage::delete($server->server_icon);
            }

            $validatedData['server_icon'] = $request->file('server_icon')->store('server_icons', 'public');
        }

        $server->update($validatedData);
        return redirect()->route('servers.index');
    }

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
}
