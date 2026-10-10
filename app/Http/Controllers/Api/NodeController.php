<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NodeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $node = $request->attributes->get('authenticated_node');

        return response()->json(['data' => $node->only([
            'id', 'nombre', 'tipo', 'activo', 'efectivo_disponible',
        ])]);
    }
}
