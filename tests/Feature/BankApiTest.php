<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BankApiTest extends TestCase
{
    use RefreshDatabase;

    protected $nodeKey;

    protected $nodeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->nodeId = Str::uuid();
        $rawKey = Str::random(60);
        $this->nodeKey = $rawKey;

        DB::table('bank_nodes')->insert([
            'id' => $this->nodeId,
            'nombre' => 'Nodo Test',
            'tipo' => 'sucursal',
            'api_key_hash' => hash('sha256', $rawKey),
            'responsable' => 'Test User',
            'activo' => true,
        ]);
    }

    public function test_api_rejects_unauthorized()
    {
        $response = $this->postJson('/api/accounts', [
            'numero_cuenta' => '123',
        ]);

        $response->assertStatus(401);
    }

    public function test_invalid_api_key_returns_401(): void
    {
        $response = $this->withHeader('X-API-KEY', 'invalid-test-key')->getJson('/api/transactions');

        $response->assertUnauthorized()->assertJson(['error' => 'Invalid or inactive API Key']);
    }

    public static function historyScopes(): array
    {
        return [
            'historial completo del nodo' => ['', 2],
            'cuenta como origen y destino' => ['?cuenta=HISTORY-LOCAL', 2],
            'cuenta usada solo por otro nodo' => ['?cuenta=HISTORY-OTHER', 0],
        ];
    }

    #[DataProvider('historyScopes')]
    public function test_history_only_returns_transactions_processed_by_the_authenticated_node(string $query, int $count): void
    {
        $otherNodeId = (string) Str::uuid();
        DB::table('bank_nodes')->insert([
            'id' => $otherNodeId, 'nombre' => 'Otro nodo', 'tipo' => 'cajero',
            'responsable' => 'Prueba', 'api_key_hash' => hash('sha256', 'other-test-key'),
        ]);
        foreach (['HISTORY-LOCAL', 'HISTORY-OTHER'] as $number) {
            DB::table('users_accounts')->insert([
                'numero_cuenta' => $number, 'nombre_titular' => 'Prueba', 'sucursal_id' => $this->nodeId,
            ]);
        }
        foreach ([$this->nodeId, $otherNodeId] as $nodeId) {
            DB::table('transactions')->insert([
                'nodo_id' => $nodeId, 'tipo' => 'deposito', 'cuenta_destino' => 'HISTORY-LOCAL', 'monto' => 100,
            ]);
            DB::table('transactions')->insert([
                'nodo_id' => $nodeId, 'tipo' => 'retiro', 'cuenta_origen' => 'HISTORY-LOCAL', 'monto' => 10,
            ]);
        }
        DB::table('transactions')->insert([
            'nodo_id' => $otherNodeId, 'tipo' => 'deposito', 'cuenta_destino' => 'HISTORY-OTHER', 'monto' => 50,
        ]);

        $response = $this->withHeader('X-API-KEY', $this->nodeKey)->getJson('/api/transactions'.$query);

        $response->assertOk()->assertJsonCount($count, 'data')->assertJsonPath('total', $count)
            ->assertJsonMissing(['nodo_id' => $otherNodeId]);
    }

    public function test_account_history_includes_other_nodes_and_paginates_without_exposing_idempotency_keys(): void
    {
        $atmId = (string) Str::uuid();
        DB::table('bank_nodes')->insert([
            'id' => $atmId, 'nombre' => 'Cajero prueba', 'tipo' => 'cajero',
            'responsable' => 'Prueba', 'api_key_hash' => hash('sha256', 'history-atm-key'),
        ]);
        foreach (['ACCOUNT-HISTORY', 'UNRELATED'] as $number) {
            DB::table('users_accounts')->insert([
                'numero_cuenta' => $number, 'nombre_titular' => 'Prueba', 'sucursal_id' => $this->nodeId,
            ]);
        }
        DB::table('transactions')->insert([
            'nodo_id' => $this->nodeId, 'tipo' => 'deposito', 'cuenta_destino' => 'ACCOUNT-HISTORY',
            'monto' => 1000, 'idempotency_key' => 'private-opening-key', 'created_at' => '2026-10-09 20:00:00+00',
        ]);
        for ($i = 0; $i < 50; $i++) {
            DB::table('transactions')->insert([
                'nodo_id' => $atmId, 'tipo' => 'retiro', 'cuenta_origen' => 'ACCOUNT-HISTORY',
                'monto' => 1, 'idempotency_key' => 'private-atm-key-'.$i, 'created_at' => '2026-10-09 21:00:00+00',
            ]);
        }
        DB::table('transactions')->insert([
            'nodo_id' => $atmId, 'tipo' => 'deposito', 'cuenta_destino' => 'UNRELATED', 'monto' => 99,
        ]);

        $first = $this->withHeader('X-API-KEY', $this->nodeKey)->getJson('/api/accounts/ACCOUNT-HISTORY/transactions');
        $second = $this->getJson('/api/accounts/ACCOUNT-HISTORY/transactions?page=2');

        $first->assertOk()->assertJsonCount(50, 'data')->assertJsonPath('total', 51)
            ->assertJsonPath('data.0.nodo_nombre', 'Cajero prueba')->assertJsonPath('data.0.nodo_tipo', 'cajero')
            ->assertJsonMissingPath('data.0.idempotency_key')->assertJsonMissing(['cuenta_destino' => 'UNRELATED']);
        $second->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.monto', '1000.00')
            ->assertJsonPath('data.0.nodo_id', (string) $this->nodeId)->assertJsonPath('data.0.nodo_tipo', 'sucursal');
        $this->assertDatabaseCount('transactions', 52);
    }

    public static function accountHistoryAuthentication(): array
    {
        return [
            'sin clave' => [null, 'Missing X-API-KEY header'],
            'clave falsa' => ['invalid-history-key', 'Invalid or inactive API Key'],
        ];
    }

    #[DataProvider('accountHistoryAuthentication')]
    public function test_account_history_requires_authentication(?string $key, string $message): void
    {
        if ($key !== null) {
            $this->withHeader('X-API-KEY', $key);
        }

        $this->getJson('/api/accounts/ACCOUNT-HISTORY/transactions')
            ->assertUnauthorized()->assertJson(['error' => $message]);
    }

    public function test_account_history_rejects_atm_role_with_403(): void
    {
        DB::table('bank_nodes')->where('id', $this->nodeId)->update(['tipo' => 'cajero']);

        $this->withHeader('X-API-KEY', $this->nodeKey)->getJson('/api/accounts/ACCOUNT-HISTORY/transactions')
            ->assertForbidden()->assertJson(['message' => 'Solo una sucursal puede consultar el historial completo de sus cuentas.']);
    }

    public function test_account_history_rejects_inactive_node_with_401(): void
    {
        DB::table('bank_nodes')->where('id', $this->nodeId)->update(['activo' => false]);

        $this->withHeader('X-API-KEY', $this->nodeKey)->getJson('/api/accounts/ACCOUNT-HISTORY/transactions')
            ->assertUnauthorized()->assertJson(['error' => 'Invalid or inactive API Key']);
    }

    public function test_account_history_does_not_expose_another_branch_account(): void
    {
        $otherBranchId = (string) Str::uuid();
        DB::table('bank_nodes')->insert([
            'id' => $otherBranchId, 'nombre' => 'Otra sucursal', 'tipo' => 'sucursal',
            'responsable' => 'Prueba', 'api_key_hash' => hash('sha256', 'other-branch-key'),
        ]);
        DB::table('users_accounts')->insert([
            'numero_cuenta' => 'OTHER-BRANCH', 'nombre_titular' => 'Cliente de otra sucursal', 'sucursal_id' => $otherBranchId,
        ]);

        $this->withHeader('X-API-KEY', $this->nodeKey)->getJson('/api/accounts/OTHER-BRANCH/transactions')
            ->assertNotFound()->assertExactJson(['message' => 'Cuenta no encontrada en esta sucursal.']);
    }

    public function test_account_history_returns_404_for_missing_account(): void
    {
        $this->withHeader('X-API-KEY', $this->nodeKey)->getJson('/api/accounts/MISSING/transactions')
            ->assertNotFound()->assertExactJson(['message' => 'Cuenta no encontrada en esta sucursal.']);
    }

    public function test_existing_account_without_movements_has_empty_complete_history(): void
    {
        DB::table('users_accounts')->insert([
            'numero_cuenta' => 'EMPTY-HISTORY', 'nombre_titular' => 'Prueba', 'sucursal_id' => $this->nodeId,
        ]);

        $this->withHeader('X-API-KEY', $this->nodeKey)->getJson('/api/accounts/EMPTY-HISTORY/transactions')
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('total', 0);
    }

    public function test_create_account_and_deposit_initial_balance()
    {
        $response = $this->withHeader('X-API-KEY', $this->nodeKey)
            ->postJson('/api/accounts', [
                'numero_cuenta' => 'ACC-001',
                'nombre_titular' => 'Juan Perez',
                'saldo_inicial' => 1000,
                'idempotency_key' => 'idemp-1',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users_accounts', [
            'numero_cuenta' => 'ACC-001',
            'saldo_global' => 1000,
        ]);

        $this->assertDatabaseHas('transactions', [
            'cuenta_destino' => 'ACC-001',
            'monto' => 1000,
            'tipo' => 'deposito',
            'nodo_id' => $this->nodeId,
        ]);
    }

    public function test_withdrawal_success()
    {
        DB::table('users_accounts')->insert([
            'numero_cuenta' => 'ACC-002',
            'sucursal_id' => $this->nodeId,
            'nombre_titular' => 'Maria',
            'saldo_global' => 1000,
            'estado' => 'activa',
        ]);

        $response = $this->withHeader('X-API-KEY', $this->nodeKey)
            ->postJson('/api/transactions', [
                'tipo' => 'retiro',
                'cuenta_origen' => 'ACC-002',
                'monto' => 300,
                'idempotency_key' => 'idemp-tx-1',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users_accounts', [
            'numero_cuenta' => 'ACC-002',
            'saldo_global' => 700,
        ]);
    }

    public function test_insufficient_funds()
    {
        DB::table('users_accounts')->insert([
            'numero_cuenta' => 'ACC-003',
            'sucursal_id' => $this->nodeId,
            'nombre_titular' => 'Pedro',
            'saldo_global' => 100,
            'estado' => 'activa',
        ]);

        $response = $this->withHeader('X-API-KEY', $this->nodeKey)
            ->postJson('/api/transactions', [
                'tipo' => 'retiro',
                'cuenta_origen' => 'ACC-003',
                'monto' => 300,
                'idempotency_key' => 'idemp-tx-2',
            ]);

        $response->assertStatus(400);
        $this->assertDatabaseHas('users_accounts', ['numero_cuenta' => 'ACC-003', 'saldo_global' => '100.00']);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_idempotency_prevents_duplicate_withdrawal()
    {
        DB::table('users_accounts')->insert([
            'numero_cuenta' => 'ACC-004',
            'sucursal_id' => $this->nodeId,
            'nombre_titular' => 'Ana',
            'saldo_global' => 1000,
            'estado' => 'activa',
        ]);

        $payload = [
            'tipo' => 'retiro',
            'cuenta_origen' => 'ACC-004',
            'monto' => 300,
            'idempotency_key' => 'idemp-tx-3',
        ];

        // Primera vez
        $res1 = $this->withHeader('X-API-KEY', $this->nodeKey)->postJson('/api/transactions', $payload);
        $res1->assertStatus(201);

        // Segunda vez
        $res2 = $this->withHeader('X-API-KEY', $this->nodeKey)->postJson('/api/transactions', $payload);
        $res2->assertStatus(200);

        // El saldo debe ser 700, no 400
        $this->assertDatabaseHas('users_accounts', [
            'numero_cuenta' => 'ACC-004',
            'saldo_global' => 700,
        ]);
    }
}
