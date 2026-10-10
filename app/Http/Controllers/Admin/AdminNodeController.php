<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminNodeController extends Controller
{
    public function dashboard()
    {
        $nodes = DB::table('bank_nodes')->get();
        // Contar transacciones por nodo
        $stats = [];

        // Aquí podríamos hacer estadísticas globales, ejemplo: total de transacciones
        return view('admin.dashboard', compact('nodes'));
    }

    public function createNode(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:255',
            'tipo' => 'required|in:sucursal,cajero',
            'responsable' => 'required|string|max:255',
        ]);

        // Generar api_key
        $rawApiKey = Str::random(60);
        $hashedKey = hash('sha256', $rawApiKey);

        DB::table('bank_nodes')->insert([
            'id' => Str::uuid(),
            'nombre' => $request->nombre,
            'tipo' => $request->tipo,
            'api_key_hash' => $hashedKey,
            'responsable' => $request->responsable,
            'efectivo_disponible' => 0,
            'activo' => true,
            'created_at' => now(),
        ]);

        return back()->with('success', 'Nodo creado. LA CLAVE API ES: '.$rawApiKey.' (Cópiala, no se volverá a mostrar).');
    }

    public function disableNode($id)
    {
        DB::table('bank_nodes')->where('id', $id)->update(['activo' => false]);

        return back()->with('success', 'Nodo desactivado.');
    }

    public function rotateKey($id)
    {
        $rawApiKey = Str::random(60);
        $hashedKey = hash('sha256', $rawApiKey);

        DB::table('bank_nodes')->where('id', $id)->update(['api_key_hash' => $hashedKey]);

        return back()->with('success', 'Clave rotada para el nodo. LA NUEVA CLAVE API ES: '.$rawApiKey);
    }

    public function updateCash(Request $request, string $id): RedirectResponse
    {
        $request->validate([
            'efectivo_asignado' => 'required|numeric|min:0|max:9999999999999.99|decimal:0,2',
            'efectivo_anterior' => 'required|numeric|min:0|max:9999999999999.99|decimal:0,2',
        ]);

        DB::transaction(function () use ($request, $id): void {
            $node = DB::table('bank_nodes')->where('id', $id)->lockForUpdate()->first();
            abort_unless($node, 404);
            if ($node->tipo === 'cajero') {
                $request->validate(['confirmar_sin_pendientes' => 'accepted']);
            }
            if (bccomp((string) $node->efectivo_disponible, (string) $request->efectivo_anterior, 2) !== 0) {
                throw ValidationException::withMessages(['efectivo_asignado' => 'El efectivo cambió. Actualiza la página y coordina con el responsable del cajero antes de guardar.']);
            }
            DB::table('bank_nodes')->where('id', $id)->update(['efectivo_disponible' => $request->efectivo_asignado]);
        });

        return back()->with('success', 'Efectivo asignado actualizado.');
    }

    public function transactions(Request $request): View|StreamedResponse
    {
        $request->validate([
            'cuenta' => 'nullable|string|max:255',
            'nodo_id' => 'nullable|uuid|exists:bank_nodes,id',
            'fecha_inicio' => 'nullable|date_format:Y-m-d',
            'fecha_fin' => ['nullable', 'date_format:Y-m-d', ...($request->filled('fecha_inicio') ? ['after_or_equal:fecha_inicio'] : [])],
            'export' => 'nullable|in:csv',
        ]);
        $query = Transaction::query()->leftJoin('bank_nodes', 'bank_nodes.id', '=', 'transactions.nodo_id')
            ->select('transactions.*', 'bank_nodes.nombre as nodo_nombre', 'bank_nodes.tipo as nodo_tipo');

        if ($request->filled('nodo_id')) {
            $query->where('transactions.nodo_id', $request->nodo_id);
        }

        if ($request->filled('cuenta')) {
            $query->where(function (Builder $q) use ($request): void {
                $q->where('cuenta_origen', $request->cuenta)
                    ->orWhere('cuenta_destino', $request->cuenta);
            });
        }

        $timezone = 'America/Mexico_City';
        if ($request->filled('fecha_inicio')) {
            $query->where('transactions.created_at', '>=', Carbon::parse($request->fecha_inicio, $timezone)->startOfDay()->utc());
        }
        if ($request->filled('fecha_fin')) {
            $query->where('transactions.created_at', '<', Carbon::parse($request->fecha_fin, $timezone)->addDay()->startOfDay()->utc());
        }

        if ($request->has('export') && $request->export == 'csv') {
            $transactions = $query->orderBy('transactions.created_at', 'desc')->get();
            $csvFileName = 'reporte_transacciones_'.now()->format('Ymd_His').'.csv';

            $headers = [
                'Content-type' => 'text/csv',
                'Content-Disposition' => "attachment; filename=$csvFileName",
                'Pragma' => 'no-cache',
                'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
                'Expires' => '0',
            ];

            $callback = function () use ($transactions, $timezone): void {
                $file = fopen('php://output', 'w');
                fputcsv($file, ['ID', 'Fecha (America/Mexico_City)', 'Tipo', 'Cuenta Origen', 'Cuenta Destino', 'Monto', 'Idempotency Key', 'Nodo ID', 'Nodo', 'Tipo Nodo'], escape: '');
                foreach ($transactions as $tx) {
                    $cells = [$tx->id, Carbon::parse($tx->created_at)->setTimezone($timezone)->format('Y-m-d H:i:s'), $tx->tipo, $tx->cuenta_origen, $tx->cuenta_destino, $tx->monto, $tx->idempotency_key, $tx->nodo_id, $tx->nodo_nombre, $tx->nodo_tipo];
                    fputcsv($file, array_map(fn ($value) => is_string($value) && preg_match('/^[=+@\-\t\r]/', $value) ? "'".$value : $value, $cells), escape: '');
                }
                fclose($file);
            };

            return response()->stream($callback, 200, $headers);
        }

        $transactions = $query->orderBy('transactions.created_at', 'desc')->paginate(20)->withQueryString();
        $nodes = DB::table('bank_nodes')->orderBy('nombre')->get(['id', 'nombre', 'tipo']);

        return view('admin.transactions', compact('transactions', 'nodes'));
    }
}
