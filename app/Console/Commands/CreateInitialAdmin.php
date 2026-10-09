<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class CreateInitialAdmin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:create-initial {email} {password}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create the initial administrator for the Bank node';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->argument('email');
        $password = $this->argument('password');

        $supabaseUrl = config('services.supabase.url');
        $serviceRoleKey = config('services.supabase.secret_key');

        if (! $supabaseUrl || ! $serviceRoleKey) {
            $this->error('Faltan variables de entorno SUPABASE_URL o SUPABASE_SECRET_KEY.');

            return;
        }

        $this->info('Creando usuario en Supabase Auth...');

        $response = Http::withHeaders([
            'apiKey' => $serviceRoleKey,
            'Content-Type' => 'application/json',
        ])->post($supabaseUrl.'/auth/v1/admin/users', [
            'email' => $email,
            'password' => $password,
            'email_confirm' => true, // Auto-confirm for admin
        ]);

        if ($response->failed()) {
            $this->error('Error de Supabase: '.$response->body());

            return;
        }

        $userData = $response->json();
        $userId = $userData['id'];

        $this->info("Insertando en bank_admins (user_id: {$userId})...");

        // Insert in bank_admins table using DB facade (bypasses RLS because it uses DB_CONNECTION that has full access, or wait, does the connection have full access?)
        // The current DB_CONNECTION is pgsql which connects to Supabase as postgres, so it bypasses RLS.

        DB::table('bank_admins')->updateOrInsert(
            ['user_id' => $userId],
            ['nombre' => $email, 'activo' => true]
        );

        $this->info('Administrador inicial creado y asignado exitosamente.');
    }
}
