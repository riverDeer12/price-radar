<x-layouts.app title="Proizvodi i cijene — Price Radar">
    <div x-data="{ tab: 'search' }">
        <div class="mb-6">
            <h1 class="text-2xl font-semibold text-slate-900">Proizvodi i cijene</h1>
        </div>

        <div class="mb-6 flex gap-2 border-b border-slate-200">
            <button
                type="button"
                @click="tab = 'search'"
                :class="tab === 'search' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-slate-500 hover:text-slate-700'"
                class="border-b-2 px-4 py-2 text-sm font-medium transition"
            >
                Pretraga
            </button>
            <button
                type="button"
                @click="tab = 'scan'; $nextTick(() => window.initBarcodeScanner && window.initBarcodeScanner())"
                :class="tab === 'scan' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-slate-500 hover:text-slate-700'"
                class="border-b-2 px-4 py-2 text-sm font-medium transition"
            >
                Skeniraj barkod
            </button>
        </div>

        <div x-show="tab === 'search'">
            <livewire:product-search />
        </div>

        <div x-show="tab === 'scan'">
            <livewire:barcode-scan />
        </div>
    </div>
</x-layouts.app>
