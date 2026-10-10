<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AtmContractTest extends TestCase
{
    use RefreshDatabase;

    private function nodes(string $cash = '1500.00'): array
    {
        $branch = (string) Str::uuid();
        $atm = (string) Str::uuid();
        foreach ([$branch => ['sucursal', 'branch-test-key', '0.00'], $atm => ['cajero', 'atm-test-key', $cash]] as $id => [$type, $key, $money]) {
            DB::table('bank_nodes')->insert(['id' => $id, 'nombre' => $type, 'tipo' => $type, 'responsable' => 'Prueba', 'api_key_hash' => hash('sha256', $key), 'efectivo_disponible' => $money]);
        }
        DB::table('users_accounts')->insert(['numero_cuenta' => '00001', 'nombre_titular' => 'Prueba', 'sucursal_id' => $branch, 'saldo_global' => '1000.00']);

        return [$branch, $atm];
    }

    private function withdrawal(string $key = '13cf9711-ef0a-4b58-b991-c1bdfd69cf53', string $amount = '300.00'): array
    {
        return ['tipo' => 'retiro', 'cuenta_origen' => '00001', 'monto' => $amount, 'idempotency_key' => $key];
    }

    public function test_identity_returns_current_cash_without_secrets_and_supports_both_node_types(): void
    {
        [$branch, $atm] = $this->nodes();

        $this->withHeader('X-API-KEY', 'atm-test-key')->getJson('/api/nodes/me')->assertOk()
            ->assertExactJson(['data' => ['id' => $atm, 'nombre' => 'cajero', 'tipo' => 'cajero', 'activo' => true, 'efectivo_disponible' => '1500.00']]);
        $this->withHeader('X-API-KEY', 'branch-test-key')->getJson('/api/nodes/me')->assertOk()->assertJsonPath('data.id', $branch)->assertJsonPath('data.tipo', 'sucursal');
        DB::table('bank_nodes')->where('id', $atm)->update(['activo' => false]);
        $this->withHeader('X-API-KEY', 'atm-test-key')->getJson('/api/nodes/me')->assertUnauthorized()->assertJson(['error' => 'Invalid or inactive API Key']);
    }

    public function test_withdrawal_and_deposit_return_original_receipts_on_retry_and_recovery(): void
    {
        [$branch, $atm] = $this->nodes();
        $payload = $this->withdrawal();
        $payload['nodo_id'] = $branch;

        $receipt = $this->withHeader('X-API-KEY', 'atm-test-key')->postJson('/api/transactions', $payload)->assertCreated()
            ->assertJsonPath('status', 'succeeded')->assertJsonPath('saldo_global', '700.00')->assertJsonPath('efectivo_disponible', '1200.00')
            ->assertJsonPath('transaction.nodo_id', $atm)->assertJsonPath('transaction.monto', '300.00')
            ->assertJsonStructure(['message', 'transaction' => ['id', 'created_at', 'idempotency_key']])->json();
        $this->assertNotEmpty($receipt['transaction']['created_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T.*Z$/', $receipt['transaction']['created_at']);
        $this->withHeader('X-API-KEY', 'atm-test-key')->postJson('/api/transactions', ['tipo' => 'deposito', 'cuenta_destino' => '00001', 'monto' => '50.15', 'idempotency_key' => 'deposit-new'])->assertCreated()
            ->assertJsonPath('saldo_global', '750.15')->assertJsonPath('efectivo_disponible', '1250.15');
        $this->withHeader('X-API-KEY', 'atm-test-key')->getJson('/api/nodes/me')->assertOk()->assertJsonPath('data.efectivo_disponible', '1250.15');
        $this->withHeader('X-API-KEY', 'atm-test-key')->postJson('/api/transactions', $payload)->assertOk()->assertExactJson($receipt);
        $this->withHeader('X-API-KEY', 'atm-test-key')->getJson('/api/transactions/by-idempotency-key/'.$payload['idempotency_key'])->assertOk()->assertExactJson($receipt);

        $this->assertDatabaseHas('users_accounts', ['numero_cuenta' => '00001', 'saldo_global' => '750.15']);
        $this->assertDatabaseHas('bank_nodes', ['id' => $atm, 'efectivo_disponible' => '1250.15']);
        $this->assertDatabaseHas('bank_nodes', ['id' => $branch, 'efectivo_disponible' => '0.00']);
        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseCount('atm_operation_results', 2);
    }

    public static function rejectionCases(): array
    {
        return [
            'saldo insuficiente' => ['1000.00', '1500.00', 'activa', '00001', '1000.01', 'INSUFFICIENT_FUNDS'],
            'efectivo insuficiente' => ['1000.00', '299.99', 'activa', '00001', '300.00', 'INSUFFICIENT_CASH'],
            'cuenta bloqueada' => ['1000.00', '1500.00', 'bloqueada', '00001', '300.00', 'ACCOUNT_BLOCKED'],
            'cuenta ausente' => ['1000.00', '1500.00', 'activa', 'absent', '300.00', 'ACCOUNT_NOT_FOUND'],
            'importe inválido' => ['1000.00', '1500.00', 'activa', '00001', '0.001', 'VALIDATION_ERROR'],
        ];
    }

    #[DataProvider('rejectionCases')]
    public function test_rejected_operations_return_422_and_remain_recoverable_without_financial_changes(string $balance, string $cash, string $state, string $number, string $amount, string $code): void
    {
        [, $atm] = $this->nodes($cash);
        DB::table('users_accounts')->where('numero_cuenta', '00001')->update(['saldo_global' => $balance, 'estado' => $state]);
        $payload = $this->withdrawal(amount: $amount);
        $payload['cuenta_origen'] = $number;

        $receipt = $this->withHeader('X-API-KEY', 'atm-test-key')->postJson('/api/transactions', $payload)->assertUnprocessable()
            ->assertJsonPath('status', 'rejected')->assertJsonPath('error.code', $code)->assertJsonPath('idempotency_key', $payload['idempotency_key'])->json();
        $this->withHeader('X-API-KEY', 'atm-test-key')->getJson('/api/transactions/by-idempotency-key/'.$payload['idempotency_key'])->assertOk()->assertExactJson($receipt);
        $this->withHeader('X-API-KEY', 'atm-test-key')->postJson('/api/transactions', $payload)->assertUnprocessable()->assertExactJson($receipt);

        $this->assertDatabaseHas('users_accounts', ['numero_cuenta' => '00001', 'saldo_global' => $balance, 'estado' => $state]);
        $this->assertDatabaseHas('bank_nodes', ['id' => $atm, 'efectivo_disponible' => $cash]);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('atm_operation_results', 1);
    }

    public function test_changed_intents_and_other_nodes_return_409_without_revealing_receipts(): void
    {
        $this->nodes();
        $payload = $this->withdrawal();
        $this->withHeader('X-API-KEY', 'atm-test-key')->postJson('/api/transactions', $payload)->assertCreated();

        $this->withHeader('X-API-KEY', 'atm-test-key')->postJson('/api/transactions', [...$payload, 'monto' => '301.00'])->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT')->assertJsonStructure(['message']);
        $this->withHeader('X-API-KEY', 'branch-test-key')->postJson('/api/transactions', $payload)->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT')->assertJsonMissingPath('error');
        $this->withHeader('X-API-KEY', 'branch-test-key')->getJson('/api/transactions/by-idempotency-key/'.$payload['idempotency_key'])->assertNotFound()->assertJsonPath('error.code', 'OPERATION_NOT_FOUND');
        $this->withHeader('X-API-KEY', 'atm-test-key')->getJson('/api/transactions/by-idempotency-key/absent')->assertNotFound()->assertExactJson(['error' => ['code' => 'OPERATION_NOT_FOUND', 'message' => 'Operación no encontrada']]);

        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseHas('users_accounts', ['numero_cuenta' => '00001', 'saldo_global' => '700.00']);
    }

    public function test_rejected_key_cannot_be_reused_by_account_opening_or_after_cash_changes(): void
    {
        [, $atm] = $this->nodes('0.00');
        $payload = $this->withdrawal();
        $this->withHeader('X-API-KEY', 'atm-test-key')->postJson('/api/transactions', $payload)->assertUnprocessable();
        DB::table('bank_nodes')->where('id', $atm)->update(['efectivo_disponible' => '1500.00']);

        $this->withHeader('X-API-KEY', 'atm-test-key')->postJson('/api/transactions', $payload)->assertUnprocessable()->assertJsonPath('error.code', 'INSUFFICIENT_CASH');
        $this->withHeader('X-API-KEY', 'branch-test-key')->postJson('/api/accounts', ['numero_cuenta' => 'NEW', 'nombre_titular' => 'Prueba', 'saldo_inicial' => 1000, 'idempotency_key' => $payload['idempotency_key']])->assertUnprocessable()->assertJsonStructure(['errors' => ['idempotency_key']]);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('users_accounts', 1);
    }

    public function test_legacy_receipts_remain_pending_and_never_repeat_financial_effects(): void
    {
        [, $atm] = $this->nodes();
        $payload = $this->withdrawal();
        DB::table('transactions')->insert(['nodo_id' => $atm, ...$payload]);

        $this->withHeader('X-API-KEY', 'atm-test-key')->postJson('/api/transactions', $payload)->assertAccepted()->assertExactJson(['status' => 'pending']);
        $this->withHeader('X-API-KEY', 'atm-test-key')->getJson('/api/transactions/by-idempotency-key/'.$payload['idempotency_key'])->assertAccepted()->assertExactJson(['status' => 'pending']);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseHas('users_accounts', ['numero_cuenta' => '00001', 'saldo_global' => '1000.00']);
    }

    public function test_branch_opening_zero_prefix_keys_and_paginated_history_remain_compatible(): void
    {
        [$branch] = $this->nodes();
        $opening = ['numero_cuenta' => '00002', 'nombre_titular' => 'Sucursal', 'saldo_inicial' => 1000, 'idempotency_key' => 'sucursal:1234567890abcdef:attempt'];

        $this->withHeader('X-API-KEY', 'branch-test-key')->postJson('/api/accounts', $opening)->assertCreated()->assertJsonPath('account.saldo_global', '1000.00');
        $this->withHeader('X-API-KEY', 'branch-test-key')->postJson('/api/accounts', $opening)->assertUnprocessable()->assertJsonStructure(['errors']);
        $this->withHeader('X-API-KEY', 'branch-test-key')->postJson('/api/accounts', [...$opening, 'numero_cuenta' => '00003', 'saldo_inicial' => 0, 'idempotency_key' => 'sucursal:1234567890abcdef:zero'])->assertCreated()->assertJsonPath('account.saldo_global', '0.00');
        $this->assertDatabaseCount('transactions', 1);
        $this->withHeader('X-API-KEY', 'atm-test-key')->getJson('/api/accounts/00002')->assertOk()->assertJsonPath('saldo_global', '1000.00')->assertJsonMissingPath('data');
        for ($i = 0; $i < 50; $i++) {
            DB::table('transactions')->insert(['nodo_id' => $branch, 'tipo' => 'deposito', 'cuenta_destino' => '00002', 'monto' => '0.01', 'idempotency_key' => 'page-'.$i]);
        }
        $page = $this->withHeader('X-API-KEY', 'branch-test-key')->getJson('/api/transactions?page=1')->assertOk()->assertJsonCount(50, 'data')->assertJsonPath('last_page', 2)->assertJsonStructure(['next_page_url', 'data' => [['created_at', 'idempotency_key']]]);
        $this->withHeader('X-API-KEY', 'branch-test-key')->getJson($page->json('next_page_url'))->assertOk()->assertJsonCount(1, 'data');
        $this->withHeader('X-API-KEY', 'atm-test-key')->getJson('/api/transactions')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_deposit_overflow_is_durable_validation_rejection(): void
    {
        [, $atm] = $this->nodes('9999999999999.99');

        $this->withHeader('X-API-KEY', 'atm-test-key')->postJson('/api/transactions', ['tipo' => 'deposito', 'cuenta_destino' => '00001', 'monto' => '0.01', 'idempotency_key' => 'overflow'])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertDatabaseHas('bank_nodes', ['id' => $atm, 'efectivo_disponible' => '9999999999999.99']);
        $this->assertDatabaseHas('users_accounts', ['numero_cuenta' => '00001', 'saldo_global' => '1000.00']);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_immutable_operation_results_reject_update_delete_and_truncate(): void
    {
        $this->nodes();
        $this->withHeader('X-API-KEY', 'atm-test-key')->postJson('/api/transactions', $this->withdrawal())->assertCreated();

        foreach (['UPDATE public.atm_operation_results SET status = \'rejected\'', 'DELETE FROM public.atm_operation_results', 'TRUNCATE public.atm_operation_results'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail('El comprobante debe ser inmutable.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('P0001', $exception->getMessage());
            }
        }

        $this->assertDatabaseCount('atm_operation_results', 1);
    }

    public function test_key_rotation_preserves_node_identity_cash_and_original_receipt(): void
    {
        [, $atm] = $this->nodes();
        $payload = $this->withdrawal();
        $receipt = $this->withHeader('X-API-KEY', 'atm-test-key')->postJson('/api/transactions', $payload)->assertCreated()->json();

        $this->withSession(['admin_authenticated' => true])->post('/admin/nodes/'.$atm.'/rotate')->assertRedirect()->assertSessionHas('success');
        $newKey = Str::after(session('success'), 'LA NUEVA CLAVE API ES: ');
        $this->withHeader('X-API-KEY', 'atm-test-key')->getJson('/api/transactions/by-idempotency-key/'.$payload['idempotency_key'])->assertUnauthorized();
        $this->withHeader('X-API-KEY', $newKey)->getJson('/api/nodes/me')->assertOk()->assertJsonPath('data.id', $atm)->assertJsonPath('data.efectivo_disponible', '1200.00');
        $this->withHeader('X-API-KEY', $newKey)->getJson('/api/transactions/by-idempotency-key/'.$payload['idempotency_key'])->assertOk()->assertExactJson($receipt);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_receipt_storage_failure_returns_500_and_rolls_back_cash_balance_and_ledger(): void
    {
        [, $atm] = $this->nodes();
        $payload = $this->withdrawal();
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION public.test_receipt_failure() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION 'Fallo aislado al guardar comprobante'; END; $$;
            CREATE TRIGGER test_receipt_failure BEFORE INSERT ON public.atm_operation_results
                FOR EACH ROW EXECUTE FUNCTION public.test_receipt_failure();
            SQL);

        $this->withHeader('X-API-KEY', 'atm-test-key')->postJson('/api/transactions', $payload)->assertInternalServerError();
        $this->assertDatabaseHas('users_accounts', ['numero_cuenta' => '00001', 'saldo_global' => '1000.00']);
        $this->assertDatabaseHas('bank_nodes', ['id' => $atm, 'efectivo_disponible' => '1500.00']);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('atm_operation_results', 0);
        $this->withHeader('X-API-KEY', 'atm-test-key')->getJson('/api/transactions/by-idempotency-key/'.$payload['idempotency_key'])->assertNotFound()->assertJsonPath('error.code', 'OPERATION_NOT_FOUND');
        DB::statement('DROP TRIGGER test_receipt_failure ON public.atm_operation_results');
        DB::statement('DROP FUNCTION public.test_receipt_failure()');
        $this->withHeader('X-API-KEY', 'atm-test-key')->postJson('/api/transactions', $payload)->assertCreated()->assertJsonPath('saldo_global', '700.00')->assertJsonPath('efectivo_disponible', '1200.00');
        $this->assertDatabaseCount('transactions', 1);
    }
}
