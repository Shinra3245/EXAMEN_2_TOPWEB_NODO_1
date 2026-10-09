@extends('layouts.admin')

@section('content')
<div class="max-w-md mx-auto mt-12">
    <div class="bg-white p-8 border border-slate-200 rounded-lg shadow-xl relative overflow-hidden">
        <div class="absolute top-0 left-0 w-full h-2 bg-amber-500"></div>
        <div class="text-center mb-8">
            <h2 class="text-2xl font-serif font-bold text-slate-800">Acceso Autorizado</h2>
            <p class="text-sm text-slate-500 mt-2">Ingrese sus credenciales de administrador</p>
        </div>
        
        <form action="{{ route('admin.login.post') }}" method="POST">
            @csrf
            <div class="mb-5">
                <label class="block text-slate-700 font-semibold mb-2 text-sm">Correo Electrónico</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <svg class="h-5 w-5 text-slate-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                            <path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z" />
                            <path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z" />
                        </svg>
                    </div>
                    <input type="email" name="email" class="w-full border border-slate-300 pl-10 p-3 rounded focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500 transition-shadow" placeholder="ejemplo@banco.com" required>
                </div>
            </div>
            <div class="mb-6">
                <label class="block text-slate-700 font-semibold mb-2 text-sm">Contraseña</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <svg class="h-5 w-5 text-slate-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <input type="password" name="password" class="w-full border border-slate-300 pl-10 p-3 rounded focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500 transition-shadow" placeholder="••••••••" required>
                </div>
            </div>
            <button type="submit" class="w-full bg-slate-900 text-white font-bold py-3 px-4 rounded hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-900 focus:ring-offset-2 transition-all flex justify-center items-center">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path></svg>
                Ingresar al Sistema
            </button>
        </form>
    </div>
</div>
@endsection
