<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('realtime.messages')) {
            if (app()->environment('testing')) {
                return;
            }
            throw new RuntimeException('Supabase Realtime no está disponible. Revisar antes de migrar.');
        }
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION public.is_active_bank_admin() RETURNS boolean
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = ''
            AS $$ SELECT EXISTS (SELECT 1 FROM public.bank_admins WHERE user_id = auth.uid() AND activo); $$;
            REVOKE ALL ON FUNCTION public.is_active_bank_admin() FROM PUBLIC, anon;
            GRANT EXECUTE ON FUNCTION public.is_active_bank_admin() TO authenticated;
            CREATE POLICY bank_admin_receive ON realtime.messages FOR SELECT TO authenticated
                USING (extension = 'broadcast' AND (SELECT realtime.topic()) = 'bank-admin' AND public.is_active_bank_admin());
            CREATE FUNCTION public.broadcast_bank_ledger_insert() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = '' AS $$
            BEGIN
                PERFORM realtime.send('{}'::jsonb, 'transactions_changed', 'bank-admin', true);
                RETURN NULL;
            END; $$;
            REVOKE ALL ON FUNCTION public.broadcast_bank_ledger_insert() FROM PUBLIC, anon, authenticated;
            CREATE TRIGGER bank_ledger_realtime AFTER INSERT ON public.transactions
                FOR EACH ROW EXECUTE FUNCTION public.broadcast_bank_ledger_insert();
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('realtime.messages')) {
            return;
        }
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS bank_ledger_realtime ON public.transactions;
            DROP FUNCTION IF EXISTS public.broadcast_bank_ledger_insert();
            DROP POLICY IF EXISTS bank_admin_receive ON realtime.messages;
            DROP FUNCTION IF EXISTS public.is_active_bank_admin();
            SQL);
    }
};
