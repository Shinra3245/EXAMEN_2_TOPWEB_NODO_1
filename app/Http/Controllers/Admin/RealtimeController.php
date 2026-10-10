<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class RealtimeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        abort_unless(DB::table('bank_admins')->where('user_id', $request->session()->get('admin_user_id'))->where('activo', true)->exists(), 403);
        if (! $request->session()->has('supabase_access_token')) {
            abort(401);
        }
        if ($request->session()->get('supabase_expires_at', 0) <= now()->timestamp + 60) {
            $response = Http::timeout(10)->withHeaders(['apikey' => config('services.supabase.publishable_key')])
                ->post(config('services.supabase.url').'/auth/v1/token?grant_type=refresh_token', [
                    'refresh_token' => $request->session()->get('supabase_refresh_token'),
                ]);
            abort_unless($response->successful() && $response->json('user.id') === $request->session()->get('admin_user_id'), 401);
            $request->session()->put([
                'supabase_access_token' => $response->json('access_token'),
                'supabase_refresh_token' => $response->json('refresh_token'),
                'supabase_expires_at' => now()->timestamp + (int) $response->json('expires_in', 3600),
            ]);
        }

        return response()->json([
            'url' => config('services.supabase.url'),
            'publishable_key' => config('services.supabase.publishable_key'),
            'access_token' => $request->session()->get('supabase_access_token'),
            'expires_at' => $request->session()->get('supabase_expires_at'),
            'topic' => 'bank-admin',
        ])->header('Cache-Control', 'private, no-store');
    }
}
