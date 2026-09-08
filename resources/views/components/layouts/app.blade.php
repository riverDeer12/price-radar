<!DOCTYPE html>
<html lang="hr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>{{ $title ?? config('app.name') }}</title>

        <script src="https://cdn.tailwindcss.com"></script>

        @livewireStyles
    </head>
    <body class="bg-slate-50 text-slate-900 antialiased">
        <nav class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3 sm:px-6">
                <a href="{{ route('dashboard') }}" class="flex items-center gap-2 font-semibold text-slate-800">
                    <span class="text-lg">📡</span>
                    <span>Price Radar</span>
                </a>
                <div class="flex items-center gap-4 text-sm font-medium text-slate-600">
                    <a href="{{ route('dashboard') }}" class="hover:text-slate-900 {{ request()->routeIs('dashboard') ? 'text-indigo-600' : '' }}">Nadzorna ploča</a>
                    <a href="{{ route('products') }}" class="hover:text-slate-900 {{ request()->routeIs('products') ? 'text-indigo-600' : '' }}">Proizvodi i cijene</a>
                    <a href="{{ route('shopping-list') }}" class="hover:text-slate-900 {{ request()->routeIs('shopping-list') ? 'text-indigo-600' : '' }}">Popis za kupovinu</a>
                    <a href="{{ route('scan') }}" class="hover:text-slate-900 {{ request()->routeIs('scan') ? 'text-indigo-600' : '' }}">Skeniraj barkod</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="hover:text-slate-900">Odjava</button>
                    </form>
                </div>
            </div>
        </nav>

        <main class="mx-auto max-w-6xl px-4 py-8 sm:px-6">
            {{ $slot }}
        </main>

        @livewireScripts
    </body>
</html>
