<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    public function showLoginForm()
    {
        return view('admin.login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        $url = env('SUPABASE_URL') . '/auth/v1/token?grant_type=password';
        $apiKey = env('SUPABASE_PUBLISHABLE_KEY'); // Should use ANON_KEY for auth requests

        $response = Http::withHeaders([
            'apiKey' => $apiKey,
            'Content-Type' => 'application/json'
        ])->post($url, [
            'email' => $request->email,
            'password' => $request->password,
        ]);

        if ($response->failed()) {
            return back()->withErrors(['email' => 'Credenciales inválidas en Supabase.']);
        }

        $data = $response->json();
        $userId = $data['user']['id'];

        // Verificar autorización (debe estar en bank_admins y activo)
        $admin = DB::table('bank_admins')->where('user_id', $userId)->first();

        if (!$admin || !$admin->activo) {
            return back()->withErrors(['email' => 'No tienes autorización administrativa o tu cuenta está inactiva.']);
        }

        // Guardar sesión
        session([
            'admin_authenticated' => true,
            'supabase_access_token' => $data['access_token'],
            'supabase_refresh_token' => $data['refresh_token'],
            'admin_user_id' => $userId,
            'admin_role' => $admin->rol,
        ]);

        return redirect()->route('admin.dashboard');
    }

    public function logout(Request $request)
    {
        // Invalidar en Supabase opcionalmente (con el token)
        $url = env('SUPABASE_URL') . '/auth/v1/logout';
        $token = session('supabase_access_token');
        if ($token) {
            Http::withHeaders([
                'apiKey' => env('SUPABASE_PUBLISHABLE_KEY'),
                'Authorization' => 'Bearer ' . $token,
            ])->post($url);
        }

        $request->session()->flush();
        return redirect()->route('admin.login');
    }
}
