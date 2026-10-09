<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = ['bank_admins', 'bank_nodes', 'users_accounts', 'transactions'];
        $existing = array_filter($tables, fn (string $table): bool => Schema::hasTable($table));
        if (count($existing) === count($tables)) {
            return;
        }
        if ($existing !== []) {
            throw new RuntimeException('El esquema bancario está incompleto. Revisarlo antes de migrar.');
        }

        // Solo para el entorno de pruebas, creamos el esquema auth simulado
        if (app()->environment('testing')) {
            DB::statement('CREATE SCHEMA IF NOT EXISTS auth;');
            DB::statement('CREATE TABLE IF NOT EXISTS auth.users (id UUID PRIMARY KEY);');
            foreach (['anon', 'authenticated', 'service_role'] as $role) {
                if (! DB::selectOne('SELECT 1 FROM pg_roles WHERE rolname = ?', [$role])) {
                    DB::statement('CREATE ROLE '.$role);
                }
            }
        }

        // Ejecutar el schema real
        $sql = File::get(base_path('supabase/001_schema.sql'));

        if (app()->environment('testing')) {
            $sql = str_replace('CREATE FUNCTION public.reject_transaction_changes()', 'CREATE OR REPLACE FUNCTION public.reject_transaction_changes()', $sql);
        }

        $sql = preg_replace('/^\s*(BEGIN|COMMIT);\s*$/m', '', $sql);

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
