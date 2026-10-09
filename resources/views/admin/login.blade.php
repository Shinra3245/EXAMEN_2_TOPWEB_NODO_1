@extends('layouts.admin')

@section('content')
<div class="max-w-md mx-auto bg-white p-8 border rounded shadow">
    <h2 class="text-2xl font-bold mb-6 text-center">Iniciar Sesión</h2>
    <form action="{{ route('admin.login.post') }}" method="POST">
        @csrf
        <div class="mb-4">
            <label class="block text-gray-700 font-bold mb-2">Correo Electrónico</label>
            <input type="email" name="email" class="w-full border p-2 rounded" required>
        </div>
        <div class="mb-6">
            <label class="block text-gray-700 font-bold mb-2">Contraseña</label>
            <input type="password" name="password" class="w-full border p-2 rounded" required>
        </div>
        <button type="submit" class="w-full bg-blue-600 text-white font-bold py-2 px-4 rounded hover:bg-blue-700">Ingresar</button>
    </form>
</div>
@endsection
