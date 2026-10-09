<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

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
            'password' => 'required',
        ]);

        $url = config('services.supabase.url').'/auth/v1/token?grant_type=password';
        $apiKey = config('services.supabase.publishable_key');

        $response = Http::withHeaders([
            'apiKey' => $apiKey,
            'Content-Type' => 'application/json',
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

        if (! $admin || ! $admin->activo) {
            return back()->withErrors(['email' => 'No tienes autorización administrativa o tu cuenta está inactiva.']);
        }

        // Guardar sesión
        $request->session()->regenerate();
        session([
            'admin_authenticated' => true,
            'supabase_access_token' => $data['access_token'],
            'supabase_refresh_token' => $data['refresh_token'],
            'admin_user_id' => $userId,
            'admin_name' => $admin->nombre,
        ]);

        return redirect()->route('admin.dashboard');
    }

    public function logout(Request $request)
    {
        // Invalidar en Supabase opcionalmente (con el token)
        $url = config('services.supabase.url').'/auth/v1/logout';
        $token = session('supabase_access_token');
        if ($token) {
            Http::withHeaders([
                'apiKey' => config('services.supabase.publishable_key'),
                'Authorization' => 'Bearer '.$token,
            ])->post($url);
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
