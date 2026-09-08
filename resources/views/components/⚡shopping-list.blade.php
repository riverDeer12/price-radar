<?php

use App\Models\Store;
use App\Services\ShoppingListService;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    private const MAX_ITEMS = 40;

    public string $listText = '';

    public ?string $city = null;

    public ?array $results = null;

    public ?string $error = null;

    #[Computed]
    public function cities()
    {
        return Store::query()
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->distinct()
            ->orderBy('city')
            ->pluck('city');
    }

    public function search(ShoppingListService $service): void
    {
        $this->error = null;
        $this->results = null;

        if (! $this->city) {
            $this->error = 'Odaberi grad.';

            return;
        }

        $items = collect(preg_split('/\r\n|\r|\n/', $this->listText))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->take(self::MAX_ITEMS)
            ->values()
            ->all();

        if ($items === []) {
            $this->error = 'Upiši barem jednu stavku, jednu po retku.';

            return;
        }

        $this->results = $service->search($items, $this->city);
    }
};
?>

<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-semibold text-slate-900">Popis za kupovinu</h1>
        <p class="mt-1 text-sm text-slate-500">
            Upiši što ideš kupiti (jedna stavka po retku), odaberi grad, a mi ćemo pronaći gdje je najjeftinije.
        </p>
    </div>

    <div class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:grid-cols-3">
        <div class="sm:col-span-2">
            <label class="mb-1 block text-sm font-medium text-slate-700">Popis (jedna stavka po retku)</label>
            <textarea
                wire:model="listText"
                rows="8"
                placeholder="mlijeko&#10;kruh&#10;jaja 10kom&#10;čips"
                class="w-full rounded-lg border-slate-300 font-mono text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            ></textarea>
        </div>

        <div class="flex flex-col gap-4">
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Grad</label>
                <select wire:model="city" class="w-full rounded-lg border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="">— odaberi grad —</option>
                    @foreach ($this->cities as $c)
                        <option value="{{ $c }}">{{ $c }}</option>
                    @endforeach
                </select>
            </div>

            <button
                wire:click="search"
                wire:loading.attr="disabled"
                wire:target="search"
                class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:bg-slate-300"
            >
                <span wire:loading.remove wire:target="search">Pretraži</span>
                <span wire:loading wire:target="search">Tražim…</span>
            </button>

            @if ($error)
                <p class="text-sm text-rose-600">{{ $error }}</p>
            @endif
        </div>
    </div>

    @if ($results)
        <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-3">
                <h2 class="font-medium text-slate-900">Najbolje poslovnice za cijeli popis</h2>
                <p class="text-xs text-slate-500">Rangirano po broju pronađenih stavki, pa po ukupnoj cijeni.</p>
            </div>

            @if ($results['stores'] === [])
                <p class="px-5 py-6 text-center text-sm text-slate-400">Nema podataka za odabrani grad.</p>
            @else
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-2">Poslovnica</th>
                            <th class="px-5 py-2">Pokriveno stavki</th>
                            <th class="px-5 py-2 text-right">Ukupno</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($results['stores'] as $store)
                            <tr wire:key="store-{{ $store['store_id'] }}">
                                <td class="px-5 py-3">
                                    <div class="font-medium text-slate-800">{{ $store['chain_name'] }}</div>
                                    <div class="text-xs text-slate-400">{{ $store['address'] ?? $store['city'] }}</div>
                                </td>
                                <td class="px-5 py-3 text-slate-600">
                                    {{ $store['covered'] }} / {{ $store['total_items'] }}
                                    @if ($store['covered'] < $store['total_items'])
                                        <span class="text-amber-600">(nepotpuno)</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-right font-medium text-slate-800">
                                    {{ number_format($store['total'], 2, ',', '.') }} €
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-3">
                <h2 class="font-medium text-slate-900">Najjeftinije po stavci</h2>
            </div>

            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-5 py-2">Tražio/la si</th>
                        <th class="px-5 py-2">Pronađeno</th>
                        <th class="px-5 py-2">Najjeftinije u</th>
                        <th class="px-5 py-2 text-right">Cijena</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($results['items'] as $item)
                        <tr>
                            <td class="px-5 py-3 font-medium text-slate-800">{{ $item['term'] }}</td>
                            <td class="px-5 py-3 text-slate-600">
                                @if ($item['status'] === 'not_found')
                                    <span class="text-rose-600">nije pronađen sličan proizvod</span>
                                @elseif ($item['status'] === 'not_in_city')
                                    <span class="text-amber-600">{{ $item['matched_name'] }} — nije dostupno u ovom gradu</span>
                                @else
                                    {{ $item['matched_name'] }}
                                @endif
                            </td>
                            <td class="px-5 py-3 text-slate-600">
                                @if ($item['best'])
                                    {{ $item['best']->chain_name }} — {{ $item['best']->store_address ?? $item['best']->store_city }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-5 py-3 text-right font-medium text-slate-800">
                                @if ($item['best'])
                                    {{ number_format($item['best']->price, 2, ',', '.') }} €
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
