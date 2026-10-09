<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\Transaction;

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
            'efectivo_asignado' => 0,
            'activo' => true,
            'created_at' => now(),
        ]);

        return back()->with('success', 'Nodo creado. LA CLAVE API ES: ' . $rawApiKey . ' (Cópiala, no se volverá a mostrar).');
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

        return back()->with('success', 'Clave rotada para el nodo. LA NUEVA CLAVE API ES: ' . $rawApiKey);
    }
    
    public function updateCash(Request $request, $id)
    {
        $request->validate([
            'efectivo_asignado' => 'required|numeric|min:0'
        ]);

        DB::table('bank_nodes')->where('id', $id)->update([
            'efectivo_asignado' => $request->efectivo_asignado
        ]);

        return back()->with('success', 'Efectivo asignado actualizado.');
    }

    public function transactions(Request $request)
    {
        $query = Transaction::query();
        
        if ($request->filled('cuenta')) {
            $query->where(function($q) use ($request) {
                $q->where('cuenta_origen', $request->cuenta)
                  ->orWhere('cuenta_destino', $request->cuenta);
            });
        }
        
        if ($request->filled('fecha_inicio') && $request->filled('fecha_fin')) {
            $query->whereBetween('created_at', [$request->fecha_inicio, $request->fecha_fin]);
        }
        
        if ($request->has('export') && $request->export == 'csv') {
            $transactions = $query->orderBy('created_at', 'desc')->get();
            $csvFileName = 'reporte_transacciones_' . now()->format('Ymd_His') . '.csv';
            
            $headers = [
                "Content-type"        => "text/csv",
                "Content-Disposition" => "attachment; filename=$csvFileName",
                "Pragma"              => "no-cache",
                "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
                "Expires"             => "0"
            ];
            
            $callback = function() use($transactions) {
                $file = fopen('php://output', 'w');
                fputcsv($file, ['ID', 'Fecha', 'Tipo', 'Cuenta Origen', 'Cuenta Destino', 'Monto', 'Idempotency Key']);
                foreach ($transactions as $tx) {
                    fputcsv($file, [$tx->id, $tx->created_at, $tx->tipo, $tx->cuenta_origen, $tx->cuenta_destino, $tx->monto, $tx->idempotency_key]);
                }
                fclose($file);
            };
            
            return response()->stream($callback, 200, $headers);
        }
        
        $transactions = $query->orderBy('created_at', 'desc')->paginate(20);
        
        return view('admin.transactions', compact('transactions'));
    }
}
