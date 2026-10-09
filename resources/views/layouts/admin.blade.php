<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Banco Central</title>
    <!-- Tailwind CSS (CDN para rapidez en examen) -->
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">

    <nav class="bg-blue-800 p-4 text-white flex justify-between items-center">
        <h1 class="text-xl font-bold">Banco Central - Panel Administrativo</h1>
        @if(session('admin_authenticated'))
        <div>
            <a href="{{ route('admin.dashboard') }}" class="mr-4 hover:underline">Nodos</a>
            <a href="{{ route('admin.transactions') }}" class="mr-4 hover:underline">Historial Global</a>
            <form action="{{ route('admin.logout') }}" method="POST" class="inline">
                @csrf
                <button type="submit" class="hover:underline">Cerrar Sesión</button>
            </form>
        </div>
        @endif
    </nav>

    <div class="container mx-auto p-4 mt-6">
        @if(session('success'))
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                {{ session('success') }}
            </div>
        @endif

        @if($errors->any())
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <ul>
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('content')
    </div>

</body>
</html>
