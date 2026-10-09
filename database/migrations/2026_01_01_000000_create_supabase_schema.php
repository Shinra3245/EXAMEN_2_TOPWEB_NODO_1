<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

return new class extends Migration
{
    public function up(): void
    {
        // Solo para el entorno de pruebas, creamos el esquema auth simulado
        if (app()->environment('testing')) {
            DB::statement('CREATE SCHEMA IF NOT EXISTS auth;');
            DB::statement('CREATE TABLE IF NOT EXISTS auth.users (id UUID PRIMARY KEY);');
        }

        // Ejecutar el schema real
        $sql = File::get(base_path('supabase/001_schema.sql'));
        
        // Remover los GRANT y REVOKE relacionados a roles de supabase que no existen en el test DB local
        $sql = preg_replace('/REVOKE ALL ON.*?;/s', '', $sql);
        $sql = preg_replace('/GRANT .*?;/s', '', $sql);
        $sql = preg_replace('/ALTER TABLE .*? ENABLE ROW LEVEL SECURITY;/s', '', $sql);
        
        DB::unprepared($sql);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS public.transactions CASCADE;');
        DB::statement('DROP TABLE IF EXISTS public.users_accounts CASCADE;');
        DB::statement('DROP TABLE IF EXISTS public.bank_nodes CASCADE;');
        DB::statement('DROP TABLE IF EXISTS public.bank_admins CASCADE;');
        
        if (app()->environment('testing')) {
            DB::statement('DROP TABLE IF EXISTS auth.users CASCADE;');
            DB::statement('DROP SCHEMA IF EXISTS auth CASCADE;');
        }
    }
};
