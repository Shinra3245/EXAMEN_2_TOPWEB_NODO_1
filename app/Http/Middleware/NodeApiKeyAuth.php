<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\BankNode;
use Symfony\Component\HttpFoundation\Response;

class NodeApiKeyAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('X-API-KEY');

        if (!$apiKey) {
            return response()->json(['error' => 'Missing X-API-KEY header'], 401);
        }

        $hash = hash('sha256', $apiKey);

        $node = BankNode::where('api_key_hash', $hash)
            ->where('activo', true)
            ->first();

        if (!$node) {
            return response()->json(['error' => 'Invalid or inactive API Key'], 401);
        }

        // Add the authenticated node to the request
        $request->attributes->add(['authenticated_node' => $node]);

        return $next($request);
    }
}
