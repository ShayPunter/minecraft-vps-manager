<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Minecraft\Modrinth\ModrinthException;
use App\Minecraft\Modrinth\ModrinthSource;
use App\Minecraft\Modrinth\ModrinthVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The server form's Modrinth picker (#5352): search modpacks by name, then list a pack's
 * versions. Admin-only (the route group). Modrinth's own errors come back as a 502 with a
 * message the form shows.
 */
class ModrinthController extends Controller
{
    public function search(Request $request, ModrinthSource $modrinth): JsonResponse
    {
        $query = $request->query('q');

        try {
            return response()->json(['hits' => $modrinth->search(is_string($query) ? $query : '')]);
        } catch (ModrinthException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    public function versions(string $project, ModrinthSource $modrinth): JsonResponse
    {
        try {
            return response()->json([
                'versions' => array_map(fn (ModrinthVersion $v): array => $v->toArray(), $modrinth->versions($project)),
            ]);
        } catch (ModrinthException $e) {
            return response()->json(['message' => $e->getMessage()], $e->permanent ? 404 : 502);
        }
    }
}
