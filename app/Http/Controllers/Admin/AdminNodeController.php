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
        
        $transactions = $query->orderBy('created_at', 'desc')->paginate(20);
        
        return view('admin.transactions', compact('transactions'));
    }
}
