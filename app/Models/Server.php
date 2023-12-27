<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Server extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'ip_address', 'server_id', 'last_activity', 'status',
        'file', 'server_icon', 'is_public', 'provider', 'server_size'
    ];

    protected $attributes = [
        'status' => 'offline',
        'ip_address' => null,
        'is_public' => 'false',
    ];

    // If you have any specific logic for file paths, you can define it here
    public function getFileUrlAttribute()
    {
        return $this->file ? Storage::url($this->file) : null;
    }

    public function getServerIconUrlAttribute()
    {
        return $this->server_icon ? Storage::url($this->server_icon) : null;
    }
}
