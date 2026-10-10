<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankNode;
use App\Models\Transaction;
use App\Models\UserAccount;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AtmOperationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $node = $request->attributes->get('authenticated_node');
        $validator = Validator::make($request->all(), [
            'tipo' => 'required|in:deposito,retiro',
            'monto' => ['required', 'numeric', 'min:0.01', 'max:9999999999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'idempotency_key' => 'required|string|max:255',
            'cuenta_origen' => 'required_if:tipo,retiro|prohibited_if:tipo,deposito|string|max:255|nullable',
            'cuenta_destino' => 'required_if:tipo,deposito|prohibited_if:tipo,retiro|string|max:255|nullable',
        ]);
        $invalid = $validator->fails();
        $key = $request->input('idempotency_key');
        if (! is_string($key) || $key === '' || mb_strlen($key) > 255) {
            return response()->json(['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'Se requiere una clave de idempotencia válida.']], 422);
        }
        $data = $request->only(['tipo', 'monto', 'cuenta_origen', 'cuenta_destino']);
        $intent = [
            'tipo' => $data['tipo'] ?? null,
            'cuenta_origen' => $data['cuenta_origen'] ?? null,
            'cuenta_destino' => $data['cuenta_destino'] ?? null,
            'monto' => $invalid ? ($data['monto'] ?? null) : bcadd((string) $data['monto'], '0', 2),
        ];
        $fingerprint = hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR));

        try {
            $result = DB::transaction(function () use ($node, $key, $data, $invalid, $fingerprint): array {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
                $stored = DB::table('atm_operation_results')->where('idempotency_key', $key)->first();
                if ($stored) {
                    if ($stored->nodo_id !== $node->id || $stored->request_hash !== $fingerprint) {
                        throw new DomainException('La clave de idempotencia pertenece a otra operación.', 409);
                    }

                    return [json_decode($stored->response, true, 512, JSON_THROW_ON_ERROR), $stored->status === 'rejected' ? 422 : 200];
                }
                $legacy = Transaction::where('idempotency_key', $key)->first();
                if ($legacy) {
                    if ($invalid || $legacy->nodo_id !== $node->id || $legacy->tipo !== $data['tipo']
                        || $legacy->cuenta_origen !== ($data['cuenta_origen'] ?? null)
                        || $legacy->cuenta_destino !== ($data['cuenta_destino'] ?? null)
                        || bccomp((string) $legacy->monto, (string) $data['monto'], 2) !== 0) {
                        throw new DomainException('La clave de idempotencia pertenece a otra operación.', 409);
                    }

                    return [['status' => 'pending'], 202];
                }
                $cashNode = BankNode::whereKey($node->id)->lockForUpdate()->firstOrFail();
                if (! $cashNode->activo || $cashNode->api_key_hash !== $node->api_key_hash) {
                    throw new DomainException('Invalid or inactive API Key', 401);
                }
                if ($invalid) {
                    return $this->reject($node->id, $key, $fingerprint, 'VALIDATION_ERROR', 'Datos del movimiento inválidos.');
                }
                $number = $data['cuenta_origen'] ?? $data['cuenta_destino'];
                $account = UserAccount::where('numero_cuenta', $number)->lockForUpdate()->first();
                if (! $account) {
                    return $this->reject($node->id, $key, $fingerprint, 'ACCOUNT_NOT_FOUND', 'Cuenta no encontrada.');
                }
                if ($account->estado !== 'activa') {
                    return $this->reject($node->id, $key, $fingerprint, 'ACCOUNT_BLOCKED', 'Cuenta bloqueada.');
                }
                $amount = bcadd((string) $data['monto'], '0', 2);
                if ($data['tipo'] === 'retiro') {
                    if (bccomp($account->saldo_global, $amount, 2) < 0) {
                        return $this->reject($node->id, $key, $fingerprint, 'INSUFFICIENT_FUNDS', 'Fondos insuficientes.');
                    }
                    if (bccomp($cashNode->efectivo_disponible, $amount, 2) < 0) {
                        return $this->reject($node->id, $key, $fingerprint, 'INSUFFICIENT_CASH', 'Efectivo insuficiente.');
                    }
                    $balance = bcsub($account->saldo_global, $amount, 2);
                    $cash = bcsub($cashNode->efectivo_disponible, $amount, 2);
                } else {
                    $balance = bcadd($account->saldo_global, $amount, 2);
                    $cash = bcadd($cashNode->efectivo_disponible, $amount, 2);
                    if (bccomp($balance, '9999999999999.99', 2) > 0 || bccomp($cash, '9999999999999.99', 2) > 0) {
                        return $this->reject($node->id, $key, $fingerprint, 'VALIDATION_ERROR', 'El saldo o efectivo excede el límite permitido.');
                    }
                }
                $account->saldo_global = $balance;
                $account->save();
                $cashNode->efectivo_disponible = $cash;
                $cashNode->save();
                $transaction = Transaction::create([
                    'nodo_id' => $node->id,
                    'tipo' => $data['tipo'],
                    'cuenta_origen' => $data['cuenta_origen'] ?? null,
                    'cuenta_destino' => $data['cuenta_destino'] ?? null,
                    'monto' => $amount,
                    'idempotency_key' => $key,
                ])->refresh();
                $receiptTransaction = $transaction->toArray();
                $receiptTransaction['created_at'] = Carbon::parse($transaction->created_at)->utc()->toISOString();
                $body = [
                    'message' => 'Transacción exitosa', 'status' => 'succeeded',
                    'transaction' => $receiptTransaction, 'saldo_global' => $balance, 'efectivo_disponible' => $cash,
                ];
                $this->persist($node->id, $key, $fingerprint, $body);

                return [$body, 201];
            }, attempts: 3);

            return response()->json($result[0], $result[1]);
        } catch (DomainException $exception) {
            if ($exception->getCode() === 401) {
                return response()->json(['error' => $exception->getMessage()], 401);
            }

            return response()->json(['message' => $exception->getMessage(), 'error' => ['code' => 'IDEMPOTENCY_CONFLICT', 'message' => $exception->getMessage()]], 409);
        }
    }

    public function show(Request $request, string $key): JsonResponse
    {
        $nodeId = $request->attributes->get('authenticated_node')->id;

        return DB::transaction(function () use ($nodeId, $key): JsonResponse {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
            $stored = DB::table('atm_operation_results')->where('idempotency_key', $key)->where('nodo_id', $nodeId)->first();
            if ($stored) {
                return response()->json(json_decode($stored->response, true, 512, JSON_THROW_ON_ERROR));
            }
            if (Transaction::where('idempotency_key', $key)->where('nodo_id', $nodeId)->exists()) {
                return response()->json(['status' => 'pending'], 202);
            }

            return response()->json(['error' => ['code' => 'OPERATION_NOT_FOUND', 'message' => 'Operación no encontrada']], 404);
        });
    }

    private function reject(string $nodeId, string $key, string $fingerprint, string $code, string $message): array
    {
        $body = ['status' => 'rejected', 'idempotency_key' => $key, 'error' => ['code' => $code, 'message' => $message]];
        $this->persist($nodeId, $key, $fingerprint, $body);

        return [$body, 422];
    }

    private function persist(string $nodeId, string $key, string $fingerprint, array $body): void
    {
        DB::table('atm_operation_results')->insert([
            'idempotency_key' => $key, 'nodo_id' => $nodeId, 'request_hash' => $fingerprint,
            'status' => $body['status'], 'response' => json_encode($body, JSON_THROW_ON_ERROR),
        ]);
    }
}
