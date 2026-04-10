<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', 'Restaurant Hub') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=cormorant-garamond:400,500,600,700|manrope:400,500,600,700" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="rh-landing">
        <header class="rh-header">
            <div class="rh-logo">Restaurant Hub</div>
            <nav class="rh-nav">
                <a href="{{ route('login') }}">Sign in</a>
            </nav>
        </header>

        @php
            $stores = [
                [
                    'name' => 'Ramen Naijiro',
                    'description' => 'Savour rich, made-to-order ramen bowls paired with unlimited chicken wings in four preparations — comfort food done right, without limits on the wings.',
                    'image' => asset('img/ramen-naijiro.jpg'),
                    'url' => route('stores.ramen-naijiro'),
                ],
                [
                    'name' => 'Marugo Takoyaki',
                    'description' => 'Discover signature takoyaki prepared fresh to order, paired with refreshing beverages for a quick, satisfying stop that balances flavor and value.',
                    'image' => asset('img/marugo-takoyaki.jpg'),
                    'url' => route('stores.marugo-takoyaki'),
                ],
                [
                    'name' => 'Assignature',
                    'description' => 'Experience unlimited premium samgyupsal served in a vibrant, modern setting designed for long gatherings, bold flavors, and memorable shared dining.',
                    'image' => asset('img/assignature.jpg'),
                    'url' => route('stores.assignature'),
                ],
            ];
        @endphp

        <main class="rh-showcase" id="stores">
            @foreach ($stores as $store)
                <a class="rh-store-card" href="{{ $store['url'] }}" style="background-image: url('{{ $store['image'] }}')">
                    <div class="rh-store-overlay"></div>
                    <div class="rh-store-content">
                        <p class="rh-store-name">{{ $store['name'] }}</p>
                        <p class="rh-store-description">{{ $store['description'] }}</p>
                        <span class="rh-card-link">Explore Store</span>
                    </div>
                </a>
            @endforeach
        </main>
    </body>
</html>
