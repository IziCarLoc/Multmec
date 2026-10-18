<!DOCTYPE html>
<html lang="pt-BR">
<head>
    @include('layouts.partials.head')
</head>
<body class="min-h-screen bg-plate text-gray-900 dark:text-gray-100 font-sans antialiased">
    {{ $slot }}
    @include('layouts.partials.scripts')
    @livewireScripts
</body>
</html>
