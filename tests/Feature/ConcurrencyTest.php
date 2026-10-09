<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Process;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConcurrencyTest extends TestCase
{
    /**
     * Prueba de concurrencia: intentamos hacer dos retiros simultáneos de 800
     * cuando el saldo es solo 1000. Uno debe fallar y el saldo quedar en 200.
     * 
     * NOTA: Esta prueba requiere que el servidor web esté corriendo localmente
     * (ej. php artisan serve) en http://localhost:8000 para poder recibir las 
     * peticiones HTTP de forma verdaderamente concurrente.
     */
    public function test_concurrent_withdrawals_prevent_negative_balance()
    {
        // 1. Setup
        $nodeId = Str::uuid();
        $rawKey = Str::random(60);
        $nodeKey = $rawKey;
        
        DB::table('bank_nodes')->insert([
            'id' => $nodeId,
            'nombre' => 'Nodo Concurrencia',
            'tipo' => 'cajero',
            'api_key_hash' => hash('sha256', $rawKey),
            'responsable' => 'Tester',
            'activo' => true,
        ]);

        $cuenta = 'ACC-CONC-001';
        DB::table('users_accounts')->insert([
            'numero_cuenta' => $cuenta,
            'nombre_titular' => 'Concurrencia Test',
            'saldo_global' => 1000,
            'estado' => 'activa'
        ]);

        $url = 'http://localhost:8000/api/transactions';
        
        $payload1 = json_encode([
            'tipo' => 'retiro',
            'cuenta_origen' => $cuenta,
            'monto' => 800,
            'idempotency_key' => 'idemp-c1-' . Str::random(5)
        ]);

        $payload2 = json_encode([
            'tipo' => 'retiro',
            'cuenta_origen' => $cuenta,
            'monto' => 800,
            'idempotency_key' => 'idemp-c2-' . Str::random(5)
        ]);

        // Asegurar que el servidor está corriendo en el puerto 8000 antes de probar,
        // de lo contrario la prueba se marcará como skipped.
        $serverUp = @file_get_contents('http://localhost:8000/up');
        if (!$serverUp) {
            $this->markTestSkipped('El servidor local no está corriendo en :8000. Ignorando prueba de concurrencia HTTP real.');
        }

        // Ejecutar concurrentemente
        $pool = Process::pool(function ($pool) use ($url, $payload1, $payload2, $nodeKey) {
            $pool->path(base_path())->command("curl -s -X POST $url -H 'Content-Type: application/json' -H 'X-API-KEY: $nodeKey' -d '$payload1'");
            $pool->path(base_path())->command("curl -s -X POST $url -H 'Content-Type: application/json' -H 'X-API-KEY: $nodeKey' -d '$payload2'");
        })->start()->wait();

        $results = $pool->collect();
        
        // Uno debió fallar por fondos insuficientes, el otro pasar.
        $successCount = 0;
        foreach ($results as $result) {
            $output = $result->output();
            if (str_contains($output, 'Transacción exitosa')) {
                $successCount++;
            }
        }

        $this->assertEquals(1, $successCount, 'Solo una transacción debió tener éxito');

        $saldoFinal = DB::table('users_accounts')->where('numero_cuenta', $cuenta)->value('saldo_global');
        $this->assertEquals(200, $saldoFinal, 'El saldo final debe ser 200');
    }
}
