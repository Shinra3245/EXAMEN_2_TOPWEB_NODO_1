<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeploymentCompatibilityTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    use RefreshDatabase;

    public function test_admin_login_uses_the_existing_supabase_schema(): void
    {
        $userId = '11111111-1111-4111-8111-111111111111';
        DB::table('auth.users')->insert(['id' => $userId]);
        DB::table('bank_admins')->insert(['user_id' => $userId, 'nombre' => 'Administrador de prueba']);
        config(['services.supabase.url' => 'https://supabase.example.test', 'services.supabase.publishable_key' => 'test-key']);
        Http::preventStrayRequests();
        Http::fake(['https://supabase.example.test/auth/v1/token?grant_type=password' => Http::response([
            'user' => ['id' => $userId], 'access_token' => 'test-access', 'refresh_token' => 'test-refresh',
        ])]);

        $response = $this->post('/admin/login', ['email' => 'admin@example.test', 'password' => 'test-password']);

        $response->assertRedirect(route('admin.dashboard'))->assertSessionHas('admin_name', 'Administrador de prueba');
        Http::assertSent(fn ($request): bool => $request->header('apiKey') === ['test-key']);
    }

    public function test_admin_can_create_a_node_and_assign_cash(): void
    {
        $response = $this->withSession(['admin_authenticated' => true])->post('/admin/nodes', [
            'nombre' => 'Cajero de prueba', 'tipo' => 'cajero', 'responsable' => 'Responsable de prueba',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('bank_nodes', ['nombre' => 'Cajero de prueba', 'efectivo_disponible' => '0.00']);
        $nodeId = DB::table('bank_nodes')->where('nombre', 'Cajero de prueba')->value('id');
        $this->withSession(['admin_authenticated' => true])->post('/admin/nodes/'.$nodeId.'/cash', ['efectivo_asignado' => '1500.00', 'efectivo_anterior' => '0.00', 'confirmar_sin_pendientes' => '1'])->assertRedirect();
        $this->assertDatabaseHas('bank_nodes', ['id' => $nodeId, 'efectivo_disponible' => '1500.00']);
        $this->withSession(['admin_authenticated' => true])->get('/admin')->assertOk()->assertSee('1500.00');
    }

    public function test_a_changed_retry_does_not_change_the_balance(): void
    {
        $nodeId = (string) Str::uuid();
        $key = 'isolated-test-node-key';
        DB::table('bank_nodes')->insert(['id' => $nodeId, 'nombre' => 'Sucursal de prueba', 'tipo' => 'sucursal', 'responsable' => 'Prueba', 'api_key_hash' => hash('sha256', $key)]);
        DB::table('users_accounts')->insert(['numero_cuenta' => 'RENDER-TEST', 'nombre_titular' => 'Prueba', 'sucursal_id' => $nodeId, 'saldo_global' => 1000]);
        $payload = ['tipo' => 'retiro', 'cuenta_origen' => 'RENDER-TEST', 'monto' => 300, 'idempotency_key' => 'retry-test'];

        $this->withHeader('X-API-KEY', $key)->postJson('/api/transactions', $payload)->assertCreated();
        $payload['monto'] = 301;
        $this->withHeader('X-API-KEY', $key)->postJson('/api/transactions', $payload)->assertConflict();

        $this->assertDatabaseHas('users_accounts', ['numero_cuenta' => 'RENDER-TEST', 'saldo_global' => '700.00']);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_login_form_keeps_https_behind_the_render_proxy(): void
    {
        $response = $this->withHeaders(['X-Forwarded-Proto' => 'https'])->get('http://banco.example.test/admin/login');

        $response->assertOk()->assertSee('https://banco.example.test/admin/login', false);
    }

    public function test_transfer_updates_both_accounts_and_records_the_node(): void
    {
        $nodeId = (string) Str::uuid();
        $key = 'transfer-test-key';
        DB::table('bank_nodes')->insert(['id' => $nodeId, 'nombre' => 'Sucursal', 'tipo' => 'sucursal', 'responsable' => 'Prueba', 'api_key_hash' => hash('sha256', $key)]);
        foreach (['ORIGEN' => 1000, 'DESTINO' => 0] as $number => $balance) {
            DB::table('users_accounts')->insert(['numero_cuenta' => $number, 'nombre_titular' => 'Prueba', 'sucursal_id' => $nodeId, 'saldo_global' => $balance]);
        }

        $this->withHeader('X-API-KEY', $key)->postJson('/api/transactions', ['tipo' => 'transferencia', 'cuenta_origen' => 'ORIGEN', 'cuenta_destino' => 'DESTINO', 'monto' => '300.15', 'idempotency_key' => 'transfer-test'])->assertCreated();

        $this->assertDatabaseHas('users_accounts', ['numero_cuenta' => 'ORIGEN', 'saldo_global' => '699.85']);
        $this->assertDatabaseHas('users_accounts', ['numero_cuenta' => 'DESTINO', 'saldo_global' => '300.15']);
        $this->assertDatabaseHas('transactions', ['nodo_id' => $nodeId, 'tipo' => 'transferencia', 'monto' => '300.15']);
    }

    public function test_the_ledger_rejects_changes_after_a_deposit(): void
    {
        $nodeId = (string) Str::uuid();
        $key = 'immutable-test-key';
        DB::table('bank_nodes')->insert(['id' => $nodeId, 'nombre' => 'Sucursal', 'tipo' => 'sucursal', 'responsable' => 'Prueba', 'api_key_hash' => hash('sha256', $key)]);
        DB::table('users_accounts')->insert(['numero_cuenta' => 'INMUTABLE', 'nombre_titular' => 'Prueba', 'sucursal_id' => $nodeId]);
        $this->withHeader('X-API-KEY', $key)->postJson('/api/transactions', ['tipo' => 'deposito', 'cuenta_destino' => 'INMUTABLE', 'monto' => '0.01', 'idempotency_key' => 'immutable-test'])->assertCreated();

        foreach (['update', 'delete', 'truncate'] as $operation) {
            try {
                DB::transaction(function () use ($operation): void {
                    $query = DB::table('transactions');
                    match ($operation) {
                        'update' => $query->update(['monto' => '5.00']),
                        'delete' => $query->delete(),
                        'truncate' => $query->truncate(),
                    };
                });
                $this->fail('El ledger aceptó '.$operation);
            } catch (QueryException $exception) {
                $this->assertStringContainsString('no permite modificaciones ni borrados', $exception->getMessage());
            }
        }

        $this->assertDatabaseHas('transactions', ['monto' => '0.01', 'idempotency_key' => 'immutable-test']);
        $this->assertDatabaseCount('transactions', 1);
    }
}
