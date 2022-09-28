<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::middleware('isadminapi')->get('/users', function() {
    return response()->json(User::all());
})->name('api-get-users');

Route::middleware('isadminapi')->get('/users/{id}', function($id) {
    return response()->json(User::where('id', '=', $id)->get()->first());
})->name('api-get-user');

Route::middleware('isadminapi')->post('/users/{id}/update', function(Request $request) {
    if ($request->name == null || $request->email == null || $request->role == null)
        return response()->json(['error' => 'Missing required form fields']);

    $user = User::where('id', '=', $request->id)->get()->first();
    $user->name = $request->name;
    $user->email = $request->email;
    $user->role = $request->role;
    if ($request->password == null) {
        $user->save();
        return response()->json('success');
    }
    $user->password = Hash::make($request->password);
    $user->save();

    return response()->json('success');
})->name('api-edit-user');

Route::middleware('isadminapi')->post('/users/create', function(Request $request) {
    if ($request->name == null)
        return response()->json(['error' => 'Missing name form field']);

    if ($request->email == null)
        return response()->json(['error' => 'Missing email form field']);

    if ($request->role == null)
        return response()->json(['error' => 'Missing role form field']);

    if ($request->password == null)
        return response()->json(['error' => 'Missing password form field']);

    $user = new User();
    $user->name = $request->name;
    $user->email = $request->email;
    $user->role = $request->role;
    $user->password = Hash::make($request->password);
    $user->api_token = Str::random(60);
    $user->save();

    return response()->json('success');
})->name('api-create-user');

Route::middleware('isadminapi')->delete('/users/{id}', function($id) {
    User::where('id', '=', $id)->get()->first()->destroy();

    return response()->json('success');
})->name('api-delete-user');
