<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
    
    public function test_create_account_and_deposit_initial_balance()
    {
        $response = $this->withHeader('X-API-KEY', $this->nodeKey)
            ->postJson('/api/accounts', [
                'numero_cuenta' => 'ACC-001',
                'nombre_titular' => 'Juan Perez',
                'saldo_inicial' => 1000,
                'idempotency_key' => 'idemp-1'
            ]);
            
        $response->assertStatus(201);
        $this->assertDatabaseHas('users_accounts', [
            'numero_cuenta' => 'ACC-001',
            'saldo_global' => 1000
        ]);
        
        $this->assertDatabaseHas('transactions', [
            'cuenta_destino' => 'ACC-001',
            'monto' => 1000,
            'tipo' => 'apertura'
        ]);
    }
    
    public function test_withdrawal_success()
    {
        DB::table('users_accounts')->insert([
            'numero_cuenta' => 'ACC-002',
            'nombre_titular' => 'Maria',
            'saldo_global' => 1000,
            'estado' => 'activa'
        ]);
        
        $response = $this->withHeader('X-API-KEY', $this->nodeKey)
            ->postJson('/api/transactions', [
                'tipo' => 'retiro',
                'cuenta_origen' => 'ACC-002',
                'monto' => 300,
                'idempotency_key' => 'idemp-tx-1'
            ]);
            
        $response->assertStatus(201);
        $this->assertDatabaseHas('users_accounts', [
            'numero_cuenta' => 'ACC-002',
            'saldo_global' => 700
        ]);
    }
    
    public function test_insufficient_funds()
    {
        DB::table('users_accounts')->insert([
            'numero_cuenta' => 'ACC-003',
            'nombre_titular' => 'Pedro',
            'saldo_global' => 100,
            'estado' => 'activa'
        ]);
        
        $response = $this->withHeader('X-API-KEY', $this->nodeKey)
            ->postJson('/api/transactions', [
                'tipo' => 'retiro',
                'cuenta_origen' => 'ACC-003',
                'monto' => 300,
                'idempotency_key' => 'idemp-tx-2'
            ]);
            
        $response->assertStatus(400);
    }
    
    public function test_idempotency_prevents_duplicate_withdrawal()
    {
        DB::table('users_accounts')->insert([
            'numero_cuenta' => 'ACC-004',
            'nombre_titular' => 'Ana',
            'saldo_global' => 1000,
            'estado' => 'activa'
        ]);
        
        $payload = [
            'tipo' => 'retiro',
            'cuenta_origen' => 'ACC-004',
            'monto' => 300,
            'idempotency_key' => 'idemp-tx-3'
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
            'saldo_global' => 700
        ]);
    }
}
