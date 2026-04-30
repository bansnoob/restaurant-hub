<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@yield('title') — Restaurant Hub</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=source-serif-4:400,500,600,700|dm-mono:400,500|manrope:400,500,600,700&display=swap" rel="stylesheet" />
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="rh-landing">

        <nav class="rh-store-nav">
            <a href="{{ route('welcome') }}" class="rh-store-nav-back">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M19 12H5M5 12l7-7M5 12l7 7"/>
                </svg>
                All Stores
            </a>
            <span class="rh-store-nav-name">@yield('store-name')</span>
            <a href="@yield('nav-action-href', '#contact')" class="rh-store-nav-action">@yield('nav-action-label', 'Visit Us')</a>
        </nav>

        @yield('content')

        <footer class="rh-store-footer">
            <span class="rh-store-footer-brand">Restaurant Hub</span>
            <a href="{{ route('welcome') }}" class="rh-store-footer-link">← All Stores</a>
        </footer>

    </body>
</html>
