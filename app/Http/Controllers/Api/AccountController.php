<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserAccount;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    /**
     * Crear una nueva cuenta (Apertura por sucursales).
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'numero_cuenta' => 'required|string|max:255|unique:users_accounts,numero_cuenta',
            'nombre_titular' => 'required|string|max:255',
            'saldo_inicial' => 'required|numeric|min:0',
            'idempotency_key' => 'required|string|max:255|unique:transactions,idempotency_key',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $result = DB::transaction(function () use ($request) {
                // 1. Crear la cuenta
                $account = UserAccount::create([
                    'numero_cuenta' => $request->numero_cuenta,
                    'nombre_titular' => $request->nombre_titular,
                    'saldo_global' => $request->saldo_inicial,
                    'estado' => 'activa',
                ]);

                // 2. Registrar el movimiento si el saldo inicial es mayor a 0
                if ($request->saldo_inicial > 0) {
                    Transaction::create([
                        'cuenta_destino' => $account->numero_cuenta,
                        'monto' => $request->saldo_inicial,
                        'tipo' => 'apertura',
                        'idempotency_key' => $request->idempotency_key,
                        // El nodo que crea la cuenta, viene del middleware
                        // Aquí asumimos que el middleware pone el nodo en los atributos de request si hiciera falta
                    ]);
                }

                return $account;
            });

            return response()->json([
                'message' => 'Cuenta creada exitosamente',
                'account' => $result
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al crear la cuenta',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Consulta del saldo central.
     */
    public function show($numero_cuenta)
    {
        $account = UserAccount::where('numero_cuenta', $numero_cuenta)->first();

        if (!$account) {
            return response()->json(['message' => 'Cuenta no encontrada'], 404);
        }

        return response()->json([
            'numero_cuenta' => $account->numero_cuenta,
            'nombre_titular' => $account->nombre_titular,
            'saldo_global' => $account->saldo_global,
            'estado' => $account->estado
        ]);
    }
}
