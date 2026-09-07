<!DOCTYPE html>
<html lang="hr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Prijava — Price Radar</title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="flex min-h-screen items-center justify-center bg-slate-50 text-slate-900 antialiased">
        <div class="w-full max-w-sm rounded-xl border border-slate-200 bg-white p-8 shadow-sm">
            <div class="mb-6 flex items-center gap-2 text-lg font-semibold text-slate-800">
                <span>📡</span>
                <span>Price Radar</span>
            </div>

            @if ($errors->any())
                <div class="mb-4 rounded-lg bg-rose-50 px-4 py-2 text-sm text-rose-600">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}" class="space-y-4">
                @csrf

                <div>
                    <label for="email" class="mb-1 block text-sm font-medium text-slate-700">E-mail</label>
                    <input
                        id="email"
                        type="email"
                        name="email"
                        value="{{ old('email') }}"
                        required
                        autofocus
                        class="w-full rounded-lg border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    >
                </div>

                <div>
                    <label for="password" class="mb-1 block text-sm font-medium text-slate-700">Lozinka</label>
                    <input
                        id="password"
                        type="password"
                        name="password"
                        required
                        class="w-full rounded-lg border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    >
                </div>

                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" name="remember" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                    Zapamti me
                </label>

                <button
                    type="submit"
                    class="w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-500"
                >
                    Prijava
                </button>
            </form>
        </div>
    </body>
</html>
