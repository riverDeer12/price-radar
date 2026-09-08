<?php

use App\Models\Chain;
use App\Models\Price;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public ?int $chain = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedChain(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function chains()
    {
        return Chain::orderBy('name')->get();
    }

    #[Computed]
    public function results()
    {
        if (mb_strlen(trim($this->search)) < 2) {
            return null;
        }

        $term = '%'.trim($this->search).'%';

        return Price::query()
            ->join('products', 'products.id', '=', 'prices.product_id')
            ->join('stores', 'stores.id', '=', 'prices.store_id')
            ->join('chains', 'chains.id', '=', 'products.chain_id')
            ->when($this->chain, fn ($q) => $q->where('chains.id', $this->chain))
            ->where(function ($q) use ($term) {
                $q->where('products.name', 'ilike', $term)
                    ->orWhere('products.barcode', 'ilike', $term);
            })
            ->orderBy('products.name')
            ->orderBy('prices.price')
            ->select([
                'prices.id', 'prices.price', 'prices.special_price', 'prices.unit_price', 'prices.archive_date',
                'products.name', 'products.brand', 'products.unit', 'products.quantity',
                'chains.name as chain_name',
                'stores.city as store_city', 'stores.address as store_address',
            ])
            ->paginate(30);
    }
};
?>

<div class="space-y-6">
    <p class="text-sm text-slate-500">Pretraži trenutne cijene po svim uvezenim trgovačkim lancima.</p>

    <div class="flex flex-col gap-3 sm:flex-row">
        <input
            type="text"
            wire:model.live.debounce.400ms="search"
            placeholder="Naziv proizvoda ili barkod (min. 2 znaka)…"
            class="w-full rounded-lg border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:max-w-sm"
        >

        <select wire:model.live="chain" class="rounded-lg border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            <option value="">Svi lanci</option>
            @foreach ($this->chains as $c)
                <option value="{{ $c->id }}">{{ $c->name }}</option>
            @endforeach
        </select>
    </div>

    @if (is_null($this->results))
        <p class="text-sm text-slate-400">Upiši barem 2 znaka za pretragu.</p>
    @else
        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-5 py-2">Proizvod</th>
                        <th class="px-5 py-2">Lanac</th>
                        <th class="px-5 py-2">Poslovnica</th>
                        <th class="px-5 py-2 text-right">Cijena</th>
                        <th class="px-5 py-2 text-right">Akcijska</th>
                        <th class="px-5 py-2">Ažurirano</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($this->results as $row)
                        <tr wire:key="price-{{ $row->id }}">
                            <td class="px-5 py-3">
                                <div class="font-medium text-slate-800">{{ $row->name }}</div>
                                <div class="text-xs text-slate-400">{{ $row->brand }} @if($row->quantity) &middot; {{ $row->quantity }} @endif</div>
                            </td>
                            <td class="px-5 py-3 text-slate-600">{{ $row->chain_name }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ $row->store_city }}</td>
                            <td class="px-5 py-3 text-right font-medium text-slate-800">{{ number_format($row->price, 2, ',', '.') }} €</td>
                            <td class="px-5 py-3 text-right">
                                @if ($row->special_price && (float) $row->special_price > 0)
                                    <span class="text-rose-600">{{ number_format($row->special_price, 2, ',', '.') }} €</span>
                                @else
                                    <span class="text-slate-300">—</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-slate-500">{{ $row->archive_date->format('d.m.Y.') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-6 text-center text-slate-400">Nema rezultata.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $this->results->links() }}</div>
    @endif
</div>
