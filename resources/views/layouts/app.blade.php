<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Restaurant Hub') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=cormorant-garamond:400,500,600,700|dm-mono:400,500|manrope:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Theme: apply before render to avoid flash -->
        <script>
            if (localStorage.getItem('rh-theme') === 'light') {
                document.documentElement.classList.add('light-mode');
            }
        </script>

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="rh-admin">

        <div class="rh-admin-shell">
            @include('layouts.navigation')

            <main class="rh-admin-main">
                @isset($header)
                    <div class="rh-page-header">
                        {{ $header }}
                    </div>
                @endisset

                {{ $slot }}
            </main>
        </div>

    </body>
</html>
