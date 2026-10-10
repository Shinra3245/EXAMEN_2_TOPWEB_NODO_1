<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConcurrencyTest extends TestCase
{
    public function test_concurrent_atm_withdrawals_from_different_accounts_do_not_overdraw_shared_cash(): void
    {
        [$nodeId, $key, $accounts] = $this->atm('1000.00');
        $payloads = [];
        foreach ($accounts as $number) {
            $payloads[] = ['tipo' => 'retiro', 'cuenta_origen' => $number, 'monto' => '800.00', 'idempotency_key' => (string) Str::uuid()];
        }

        $results = $this->concurrentAtmRequests($key, $payloads);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['rejected', 'succeeded'], $statuses);
        $rejected = array_values(array_filter($results, fn (array $result): bool => $result['status'] === 'rejected'))[0];
        $this->assertSame('INSUFFICIENT_CASH', $rejected['error']['code']);
        $this->assertSame('200.00', DB::table('bank_nodes')->where('id', $nodeId)->value('efectivo_disponible'));
        $balances = DB::table('users_accounts')->whereIn('numero_cuenta', $accounts)->orderBy('saldo_global')->pluck('saldo_global')->all();
        $this->assertSame(['200.00', '1000.00'], $balances);
        $this->assertSame(1, DB::table('transactions')->where('nodo_id', $nodeId)->count());
        $this->assertSame(2, DB::table('atm_operation_results')->where('nodo_id', $nodeId)->count());
    }

    public function test_concurrent_atm_retries_return_the_same_receipt_and_change_cash_once(): void
    {
        [$nodeId, $key, $accounts] = $this->atm('1500.00');
        $payload = ['tipo' => 'retiro', 'cuenta_origen' => $accounts[0], 'monto' => '300.00', 'idempotency_key' => (string) Str::uuid()];

        $results = $this->concurrentAtmRequests($key, [$payload, $payload]);

        foreach ($results as &$result) {
            ksort($result);
            ksort($result['transaction']);
        }
        unset($result);
        $this->assertSame($results[0], $results[1]);
        $this->assertSame('succeeded', $results[0]['status']);
        $this->assertSame('1200.00', DB::table('bank_nodes')->where('id', $nodeId)->value('efectivo_disponible'));
        $this->assertSame('700.00', DB::table('users_accounts')->where('numero_cuenta', $accounts[0])->value('saldo_global'));
        $this->assertSame(1, DB::table('transactions')->where('nodo_id', $nodeId)->count());
        $this->assertSame(1, DB::table('atm_operation_results')->where('nodo_id', $nodeId)->count());
    }

    private function atm(string $cash): array
    {
        if (! getenv('BANK_CONCURRENCY_URL')) {
            $this->markTestSkipped('Falta servidor HTTP PostgreSQL aislado.');
        }
        $nodeId = (string) Str::uuid();
        $key = Str::random(60);
        DB::table('bank_nodes')->insert(['id' => $nodeId, 'nombre' => 'Cajero concurrente', 'tipo' => 'cajero', 'responsable' => 'Pruebas', 'api_key_hash' => hash('sha256', $key), 'efectivo_disponible' => $cash]);
        $numbers = ['CONC-A-'.Str::uuid(), 'CONC-B-'.Str::uuid()];
        foreach ($numbers as $number) {
            DB::table('users_accounts')->insert(['numero_cuenta' => $number, 'nombre_titular' => 'Prueba', 'sucursal_id' => $nodeId, 'saldo_global' => '1000.00']);
        }

        return [$nodeId, $key, $numbers];
    }

    private function concurrentAtmRequests(string $key, array $payloads): array
    {
        $url = rtrim(getenv('BANK_CONCURRENCY_URL'), '/').'/api/transactions';
        $pool = Process::pool(function ($pool) use ($url, $key, $payloads): void {
            foreach ($payloads as $payload) {
                $pool->command(['curl', '--fail-with-body', '-sS', '-X', 'POST', $url, '-H', 'Content-Type: application/json', '-H', 'X-API-KEY: '.$key, '-d', json_encode($payload, JSON_THROW_ON_ERROR)]);
            }
        })->start()->wait();
        $results = [];
        foreach ($pool->collect() as $result) {
            $this->assertContains($result->exitCode(), [0, 22], $result->errorOutput());
            $results[] = json_decode($result->output(), true, 512, JSON_THROW_ON_ERROR);
        }

        return $results;
    }

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
        $serverUrl = getenv('BANK_CONCURRENCY_URL');
        if (! $serverUrl) {
            $this->markTestSkipped('Falta BANK_CONCURRENCY_URL de un servidor conectado a PostgreSQL aislado.');
        }
        $url = rtrim($serverUrl, '/').'/api/transactions';

        // 1. Setup
        $nodeId = Str::uuid();
        $rawKey = Str::random(60);
        $nodeKey = $rawKey;

        DB::table('bank_nodes')->insert([
            'id' => $nodeId,
            'nombre' => 'Nodo Concurrencia',
            'tipo' => 'sucursal',
            'api_key_hash' => hash('sha256', $rawKey),
            'responsable' => 'Tester',
            'activo' => true,
        ]);

        $cuenta = 'ACC-CONC-'.Str::uuid();
        DB::table('users_accounts')->insert([
            'numero_cuenta' => $cuenta,
            'sucursal_id' => $nodeId,
            'nombre_titular' => 'Concurrencia Test',
            'saldo_global' => 1000,
            'estado' => 'activa',
        ]);

        $payload1 = json_encode([
            'tipo' => 'retiro',
            'cuenta_origen' => $cuenta,
            'monto' => 800,
            'idempotency_key' => 'idemp-c1-'.Str::random(5),
        ]);

        $payload2 = json_encode([
            'tipo' => 'retiro',
            'cuenta_origen' => $cuenta,
            'monto' => 800,
            'idempotency_key' => 'idemp-c2-'.Str::random(5),
        ]);

        // Asegurar que el servidor está corriendo en el puerto 8000 antes de probar,
        // de lo contrario la prueba se marcará como skipped.
        $serverUp = @file_get_contents(rtrim($serverUrl, '/').'/up');
        if (! $serverUp) {
            $this->markTestSkipped('El servidor local no está corriendo en :8000. Ignorando prueba de concurrencia HTTP real.');
        }

        // Ejecutar concurrentemente
        $pool = Process::pool(function ($pool) use ($url, $payload1, $payload2, $nodeKey) {
            $pool->path(base_path())->command(['curl', '-sS', '-X', 'POST', $url, '-H', 'Content-Type: application/json', '-H', 'X-API-KEY: '.$nodeKey, '-d', $payload1]);
            $pool->path(base_path())->command(['curl', '-sS', '-X', 'POST', $url, '-H', 'Content-Type: application/json', '-H', 'X-API-KEY: '.$nodeKey, '-d', $payload2]);
        })->start()->wait();

        $results = $pool->collect();

        // Uno debió fallar por fondos insuficientes, el otro pasar.
        $successCount = 0;
        foreach ($results as $result) {
            $this->assertSame(0, $result->exitCode(), $result->errorOutput());
            $output = json_decode($result->output(), true, 512, JSON_THROW_ON_ERROR);
            if (($output['message'] ?? null) === 'Transacción exitosa') {
                $successCount++;
            }
        }

        $this->assertEquals(1, $successCount, 'Solo una transacción debió tener éxito');

        $saldoFinal = DB::table('users_accounts')->where('numero_cuenta', $cuenta)->value('saldo_global');
        $this->assertEquals(200, $saldoFinal, 'El saldo final debe ser 200');
        $this->assertSame(1, DB::table('transactions')->where('nodo_id', $nodeId)->count());
    }
}
