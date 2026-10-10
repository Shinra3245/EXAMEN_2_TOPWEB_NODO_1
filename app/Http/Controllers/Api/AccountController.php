<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\UserAccount;
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
        $node = $request->attributes->get('authenticated_node');
        if ($node->tipo !== 'sucursal') {
            return response()->json(['message' => 'Solo una sucursal puede abrir cuentas.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'numero_cuenta' => 'required|string|max:255|unique:users_accounts,numero_cuenta',
            'nombre_titular' => 'required|string|max:255',
            'saldo_inicial' => 'required|numeric|min:0|max:9999999999999.99|decimal:0,2',
            'idempotency_key' => 'required|string|max:255|unique:transactions,idempotency_key|unique:atm_operation_results,idempotency_key',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $result = DB::transaction(function () use ($request, $node) {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$request->idempotency_key]);
                if (DB::table('atm_operation_results')->where('idempotency_key', $request->idempotency_key)->exists()
                    || Transaction::where('idempotency_key', $request->idempotency_key)->exists()) {
                    throw ValidationException::withMessages(['idempotency_key' => ['La clave de idempotencia ya fue utilizada.']]);
                }
                // 1. Crear la cuenta
                $account = UserAccount::create([
                    'numero_cuenta' => $request->numero_cuenta,
                    'nombre_titular' => $request->nombre_titular,
                    'saldo_global' => $request->saldo_inicial,
                    'estado' => 'activa',
                    'sucursal_id' => $node->id,
                ]);

                // 2. Registrar el movimiento si el saldo inicial es mayor a 0
                if ($request->saldo_inicial > 0) {
                    Transaction::create([
                        'nodo_id' => $node->id,
                        'cuenta_destino' => $account->numero_cuenta,
                        'monto' => $request->saldo_inicial,
                        'tipo' => 'deposito',
                        'idempotency_key' => $request->idempotency_key,
                    ]);
                }

                return $account;
            });

            return response()->json([
                'message' => 'Cuenta creada exitosamente',
                'account' => $result,
            ], 201);
        } catch (ValidationException $exception) {
            return response()->json(['errors' => $exception->errors()], 422);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al crear la cuenta',
            ], 500);
        }
    }

    /**
     * Consulta del saldo central.
     */
    public function show($numero_cuenta)
    {
        $account = UserAccount::where('numero_cuenta', $numero_cuenta)->first();

        if (! $account) {
            return response()->json(['message' => 'Cuenta no encontrada'], 404);
        }

        return response()->json([
            'numero_cuenta' => $account->numero_cuenta,
            'nombre_titular' => $account->nombre_titular,
            'saldo_global' => $account->saldo_global,
            'estado' => $account->estado,
        ]);
    }
}
