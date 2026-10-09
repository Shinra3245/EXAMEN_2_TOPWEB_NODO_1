<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\UserAccount;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Transaction::query();
        if ($request->filled('cuenta')) {
            $query->where(function ($query) use ($request): void {
                $query->where('cuenta_origen', $request->cuenta)
                    ->orWhere('cuenta_destino', $request->cuenta);
            });
        }

        return response()->json($query->orderBy('created_at', 'desc')->paginate(50));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tipo' => 'required|in:deposito,retiro,transferencia',
            'monto' => 'required|numeric|min:0.01|max:9999999999999.99|decimal:0,2',
            'idempotency_key' => 'required|string|max:255',
            'cuenta_origen' => 'required_if:tipo,retiro,transferencia|prohibited_if:tipo,deposito|string|nullable',
            'cuenta_destino' => 'required_if:tipo,deposito,transferencia|prohibited_if:tipo,retiro|string|nullable|different:cuenta_origen',
        ]);
        $node = $request->attributes->get('authenticated_node');

        try {
            $result = DB::transaction(function () use ($data, $node): array {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$data['idempotency_key']]);
                $existing = Transaction::where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    $sameRequest = $existing->nodo_id === $node->id
                        && $existing->tipo === $data['tipo']
                        && $existing->cuenta_origen === ($data['cuenta_origen'] ?? null)
                        && $existing->cuenta_destino === ($data['cuenta_destino'] ?? null)
                        && bccomp((string) $existing->monto, (string) $data['monto'], 2) === 0;
                    if (! $sameRequest) {
                        throw new DomainException('La clave de idempotencia pertenece a otra operación.', 409);
                    }

                    return ['transaction' => $existing, 'repeated' => true];
                }

                $numbers = array_filter([$data['cuenta_origen'] ?? null, $data['cuenta_destino'] ?? null]);
                $accounts = UserAccount::whereIn('numero_cuenta', $numbers)
                    ->orderBy('numero_cuenta')->lockForUpdate()->get()->keyBy('numero_cuenta');
                foreach ($numbers as $number) {
                    if (! isset($accounts[$number]) || $accounts[$number]->estado !== 'activa') {
                        throw new DomainException('Cuenta no encontrada o inactiva.', 400);
                    }
                }

                $amount = (string) $data['monto'];
                if (isset($data['cuenta_origen'])) {
                    $source = $accounts[$data['cuenta_origen']];
                    if (bccomp((string) $source->saldo_global, $amount, 2) < 0) {
                        throw new DomainException('Fondos insuficientes.', 400);
                    }
                    $source->saldo_global = bcsub((string) $source->saldo_global, $amount, 2);
                    $source->save();
                }
                if (isset($data['cuenta_destino'])) {
                    $destination = $accounts[$data['cuenta_destino']];
                    $destination->saldo_global = bcadd((string) $destination->saldo_global, $amount, 2);
                    $destination->save();
                }

                $transaction = Transaction::create([
                    'nodo_id' => $node->id,
                    'cuenta_origen' => $data['cuenta_origen'] ?? null,
                    'cuenta_destino' => $data['cuenta_destino'] ?? null,
                    'monto' => $amount,
                    'tipo' => $data['tipo'],
                    'idempotency_key' => $data['idempotency_key'],
                ]);

                return ['transaction' => $transaction, 'repeated' => false];
            });

            return response()->json([
                'message' => $result['repeated'] ? 'Transacción procesada previamente (Idempotente)' : 'Transacción exitosa',
                'transaction' => $result['transaction'],
            ], $result['repeated'] ? 200 : 201);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->getCode());
        }
    }
}
