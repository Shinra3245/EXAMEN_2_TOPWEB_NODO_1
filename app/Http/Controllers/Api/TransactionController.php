<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserAccount;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TransactionController extends Controller
{
    /**
     * Realizar depósitos, retiros y transferencias.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'tipo' => 'required|in:deposito,retiro,transferencia',
            'monto' => 'required|numeric|min:0.01',
            'idempotency_key' => 'required|string|max:255',
            'cuenta_origen' => 'required_if:tipo,retiro,transferencia|string|nullable',
            'cuenta_destino' => 'required_if:tipo,deposito,transferencia|string|nullable|different:cuenta_origen',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Check for idempotency
        $existingTx = Transaction::where('idempotency_key', $request->idempotency_key)->first();
        if ($existingTx) {
            // Re-evaluar si la petición es la misma? Para simplificar, devolvemos success
            return response()->json([
                'message' => 'Transacción procesada previamente (Idempotente)',
                'transaction' => $existingTx
            ], 200);
        }

        try {
            $result = DB::transaction(function () use ($request) {
                $tx = null;

                if ($request->tipo === 'deposito') {
                    // Bloquear la fila destino
                    $cuentaDestino = UserAccount::where('numero_cuenta', $request->cuenta_destino)
                        ->where('estado', 'activa')
                        ->lockForUpdate()
                        ->first();

                    if (!$cuentaDestino) {
                        throw new \Exception("Cuenta destino no encontrada o inactiva.");
                    }

                    $cuentaDestino->saldo_global += $request->monto;
                    $cuentaDestino->save();

                    $tx = Transaction::create([
                        'cuenta_destino' => $request->cuenta_destino,
                        'monto' => $request->monto,
                        'tipo' => 'deposito',
                        'idempotency_key' => $request->idempotency_key
                    ]);

                } elseif ($request->tipo === 'retiro') {
                    $cuentaOrigen = UserAccount::where('numero_cuenta', $request->cuenta_origen)
                        ->where('estado', 'activa')
                        ->lockForUpdate()
                        ->first();

                    if (!$cuentaOrigen) {
                        throw new \Exception("Cuenta origen no encontrada o inactiva.");
                    }

                    if ($cuentaOrigen->saldo_global < $request->monto) {
                        throw new \Exception("Fondos insuficientes.");
                    }

                    $cuentaOrigen->saldo_global -= $request->monto;
                    $cuentaOrigen->save();

                    $tx = Transaction::create([
                        'cuenta_origen' => $request->cuenta_origen,
                        'monto' => $request->monto,
                        'tipo' => 'retiro',
                        'idempotency_key' => $request->idempotency_key
                    ]);

                } elseif ($request->tipo === 'transferencia') {
                    // Para evitar deadlocks, bloqueamos siempre en el mismo orden (ej. por id o por string)
                    $accounts = [$request->cuenta_origen, $request->cuenta_destino];
                    sort($accounts);

                    $acc1 = UserAccount::where('numero_cuenta', $accounts[0])->lockForUpdate()->first();
                    $acc2 = UserAccount::where('numero_cuenta', $accounts[1])->lockForUpdate()->first();

                    // Retrieve again in logical order
                    $cuentaOrigen = $acc1->numero_cuenta === $request->cuenta_origen ? $acc1 : $acc2;
                    $cuentaDestino = $acc1->numero_cuenta === $request->cuenta_destino ? $acc1 : $acc2;

                    if (!$cuentaOrigen || $cuentaOrigen->estado !== 'activa') {
                        throw new \Exception("Cuenta origen no encontrada o inactiva.");
                    }
                    if (!$cuentaDestino || $cuentaDestino->estado !== 'activa') {
                        throw new \Exception("Cuenta destino no encontrada o inactiva.");
                    }
                    if ($cuentaOrigen->saldo_global < $request->monto) {
                        throw new \Exception("Fondos insuficientes.");
                    }

                    $cuentaOrigen->saldo_global -= $request->monto;
                    $cuentaDestino->saldo_global += $request->monto;
                    $cuentaOrigen->save();
                    $cuentaDestino->save();

                    $tx = Transaction::create([
                        'cuenta_origen' => $request->cuenta_origen,
                        'cuenta_destino' => $request->cuenta_destino,
                        'monto' => $request->monto,
                        'tipo' => 'transferencia',
                        'idempotency_key' => $request->idempotency_key
                    ]);
                }

                return $tx;
            });

            return response()->json([
                'message' => 'Transacción exitosa',
                'transaction' => $result
            ], 201);
            
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al procesar la transacción',
                'error' => $e->getMessage()
            ], 400);
        }
    }
}
