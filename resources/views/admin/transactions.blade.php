@extends('layouts.admin')

@section('content')
<div class="mb-8">
    <h2 class="text-2xl font-bold mb-4">Historial Global de Transacciones</h2>
    <p id="realtime-status" class="mb-4 text-sm text-slate-600" data-config-url="{{ route('admin.realtime') }}">Conectando actualizaciones…</p>
    <button id="realtime-refresh" type="button" hidden class="mb-4 bg-blue-600 text-white p-2 rounded">Hay nuevos movimientos. Actualizar historial</button>
    <script src="{{ asset('admin-realtime.js') }}" defer></script>

    <div class="bg-white p-6 rounded shadow mb-6">
        <form method="GET" action="{{ route('admin.transactions') }}" class="flex flex-wrap gap-4 items-end">
            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2">Sucursal / Cajero</label>
                <select name="nodo_id" class="border p-2 rounded">
                    <option value="">Todos los nodos</option>
                    @foreach($nodes as $node)
                        <option value="{{ $node->id }}" @selected(request('nodo_id') === $node->id)>{{ $node->nombre }} ({{ $node->tipo }})</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2">Cuenta</label>
                <input type="text" name="cuenta" value="{{ request('cuenta') }}" class="border p-2 rounded">
            </div>
            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2">Fecha Inicio</label>
                <input type="date" name="fecha_inicio" value="{{ request('fecha_inicio') }}" class="border p-2 rounded">
            </div>
            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2">Fecha Fin</label>
                <input type="date" name="fecha_fin" value="{{ request('fecha_fin') }}" class="border p-2 rounded">
            </div>
            <button type="submit" class="bg-blue-600 text-white font-bold py-2 px-4 rounded hover:bg-blue-700">Filtrar</button>
            <a href="{{ route('admin.transactions') }}" class="bg-gray-400 text-white font-bold py-2 px-4 rounded hover:bg-gray-500">Limpiar</a>
            <button type="submit" name="export" value="csv" class="bg-green-600 text-white font-bold py-2 px-4 rounded hover:bg-green-700">Exportar CSV</button>
        </form>
    </div>

    <div class="bg-white p-6 rounded shadow">
        <table class="w-full text-left border-collapse">
            <thead>
                <tr>
                    <th class="border-b p-2">ID</th>
                    <th class="border-b p-2">Fecha</th>
                    <th class="border-b p-2">Nodo</th>
                    <th class="border-b p-2">Tipo</th>
                    <th class="border-b p-2">Origen</th>
                    <th class="border-b p-2">Destino</th>
                    <th class="border-b p-2">Monto</th>
                </tr>
            </thead>
            <tbody>
                @forelse($transactions as $tx)
                <tr>
                    <td class="border-b p-2 text-sm text-gray-500">{{ $tx->id }}</td>
                    <td class="border-b p-2">{{ \Illuminate\Support\Carbon::parse($tx->created_at)->setTimezone('America/Mexico_City')->format('Y-m-d H:i:s') }}</td>
                    <td class="border-b p-2">{{ $tx->nodo_nombre }} ({{ $tx->nodo_tipo }})</td>
                    <td class="border-b p-2 font-semibold uppercase">{{ $tx->tipo }}</td>
                    <td class="border-b p-2">{{ $tx->cuenta_origen ?? '-' }}</td>
                    <td class="border-b p-2">{{ $tx->cuenta_destino ?? '-' }}</td>
                    <td class="border-b p-2 text-green-600 font-bold">${{ number_format($tx->monto, 2) }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="border-b p-4 text-center text-gray-500">No hay transacciones registradas.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
        <div class="mt-4">
            {{ $transactions->links() }}
        </div>
    </div>
</div>
@endsection
