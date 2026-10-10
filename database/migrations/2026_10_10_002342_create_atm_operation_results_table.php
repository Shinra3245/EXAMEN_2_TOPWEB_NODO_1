<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('atm_operation_results', function (Blueprint $table): void {
            $table->string('idempotency_key', 255)->primary();
            $table->uuid('nodo_id')->references('id')->on('bank_nodes');
            $table->char('request_hash', 64);
            $table->string('status', 16);
            $table->jsonb('response');
            $table->timestampTz('created_at')->useCurrent();
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.atm_operation_results ADD CONSTRAINT atm_result_status CHECK (status IN ('succeeded', 'rejected'));
            ALTER TABLE public.atm_operation_results ENABLE ROW LEVEL SECURITY;
            REVOKE ALL ON public.atm_operation_results FROM PUBLIC, anon, authenticated;
            GRANT SELECT, INSERT ON public.atm_operation_results TO service_role;
            CREATE TRIGGER immutable_atm_results BEFORE UPDATE OR DELETE ON public.atm_operation_results
                FOR EACH ROW EXECUTE FUNCTION public.reject_transaction_changes();
            CREATE TRIGGER immutable_atm_results_truncate BEFORE TRUNCATE ON public.atm_operation_results
                FOR EACH STATEMENT EXECUTE FUNCTION public.reject_transaction_changes();
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('atm_operation_results');
    }
};
