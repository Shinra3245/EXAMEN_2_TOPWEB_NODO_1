@extends('layouts.admin')

@section('content')
<div class="mb-8">
    <h2 class="text-2xl font-bold mb-4">Gestión de Nodos (Sucursales y Cajeros)</h2>
    
    <div class="bg-white p-6 rounded shadow mb-8">
        <h3 class="text-xl font-semibold mb-4">Crear Nuevo Nodo</h3>
        <form action="{{ route('admin.nodes.store') }}" method="POST" class="flex flex-wrap gap-4 items-end">
            @csrf
            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2">Nombre / Identificador</label>
                <input type="text" name="nombre" class="border p-2 rounded" required>
            </div>
            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2">Tipo</label>
                <select name="tipo" class="border p-2 rounded" required>
                    <option value="sucursal">Sucursal</option>
                    <option value="cajero">Cajero (ATM)</option>
                </select>
            </div>
            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2">Responsable</label>
                <input type="text" name="responsable" class="border p-2 rounded" required>
            </div>
            <button type="submit" class="bg-green-600 text-white font-bold py-2 px-4 rounded hover:bg-green-700">Registrar</button>
        </form>
    </div>

    <div class="bg-white p-6 rounded shadow">
        <h3 class="text-xl font-semibold mb-4">Nodos Registrados</h3>
        <table class="w-full text-left border-collapse">
            <thead>
                <tr>
                    <th class="border-b p-2">Nombre</th>
                    <th class="border-b p-2">Tipo</th>
                    <th class="border-b p-2">Responsable</th>
                    <th class="border-b p-2">Efectivo Asignado</th>
                    <th class="border-b p-2">Estado</th>
                    <th class="border-b p-2">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @foreach($nodes as $node)
                <tr>
                    <td class="border-b p-2">{{ $node->nombre }}</td>
                    <td class="border-b p-2">{{ ucfirst($node->tipo) }}</td>
                    <td class="border-b p-2">{{ $node->responsable }}</td>
                    <td class="border-b p-2">
                        <form action="{{ route('admin.nodes.cash', $node->id) }}" method="POST" class="flex gap-2 items-center">
                            @csrf
                            <input type="hidden" name="efectivo_anterior" value="{{ $node->efectivo_disponible }}">
                            @if($node->tipo === 'cajero')
                            <label class="text-xs"><input type="checkbox" name="confirmar_sin_pendientes" value="1" required> Coordinado con el cajero, sin operaciones pendientes</label>
                            @endif
                            $<input type="number" step="0.01" min="0" name="efectivo_asignado" value="{{ $node->efectivo_disponible }}" class="border p-1 rounded w-24">
                            <button class="bg-blue-600 text-white px-2 py-1 rounded text-sm hover:bg-blue-700">Guardar</button>
                        </form>
                    </td>
                    <td class="border-b p-2">
                        @if($node->activo)
                            <span class="text-green-600 font-bold">Activo</span>
                        @else
                            <span class="text-red-600 font-bold">Inactivo</span>
                        @endif
                    </td>
                    <td class="border-b p-2">
                        @if($node->activo)
                        <form action="{{ route('admin.nodes.rotate', $node->id) }}" method="POST" class="inline">
                            @csrf
                            <button class="bg-yellow-500 text-white px-2 py-1 rounded text-sm hover:bg-yellow-600">Rotar Clave</button>
                        </form>
                        <form action="{{ route('admin.nodes.disable', $node->id) }}" method="POST" class="inline">
                            @csrf
                            <button class="bg-red-500 text-white px-2 py-1 rounded text-sm hover:bg-red-600" onclick="return confirm('¿Desactivar este nodo?')">Desactivar</button>
                        </form>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
