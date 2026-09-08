<?php

use App\Models\Store;
use App\Services\BarcodeLookupService;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $manualBarcode = '';

    public ?string $city = null;

    public ?string $lastScanned = null;

    public ?array $result = null;

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

    public function lookup(BarcodeLookupService $service, ?string $code = null): void
    {
        $barcode = trim($code ?? $this->manualBarcode);

        $this->error = null;
        $this->result = null;
        $this->lastScanned = $barcode;

        if (! preg_match('/^\d{8,14}$/', $barcode)) {
            $this->error = 'To ne izgleda kao valjan barkod (očekujem 8-14 znamenki).';

            return;
        }

        $found = $service->lookup($barcode, $this->city ?: null);

        if (! $found['found']) {
            $this->error = "Proizvod s barkodom {$barcode} nije pronađen u bazi.";

            return;
        }

        if ($found['offers'] === []) {
            $this->error = "\"{$found['name']}\" je pronađen, ali nema cijena za odabrani grad. Pokušaj bez filtera po gradu.";
            $this->result = ['name' => $found['name'], 'offers' => []];

            return;
        }

        $this->result = $found;
    }
};
?>

<div class="space-y-6">
    <p class="text-sm text-slate-500">
        Ako artikl u trgovini nema istaknutu cijenu, skeniraj barkod (ili ga upiši ručno) da vidiš zadnju
        poznatu cijenu po lancu.
    </p>

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="mb-3 text-sm font-medium text-slate-700">Kamera</h2>

            <div wire:ignore>
                <div id="barcode-reader" class="overflow-hidden rounded-lg"></div>
            </div>

            <p class="mt-2 text-xs text-slate-400">
                Kamera radi samo preko HTTPS-a i uz dopuštenje preglednika za pristup kameri. Ako ne proradi,
                upiši barkod ručno.
            </p>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="mb-3 text-sm font-medium text-slate-700">Ručni unos</h2>

            <div class="space-y-3">
                <input
                    type="text"
                    inputmode="numeric"
                    wire:model="manualBarcode"
                    wire:keydown.enter="lookup"
                    placeholder="npr. 3850354009726"
                    class="w-full rounded-lg border-slate-300 font-mono shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                >

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Grad (opcionalno)</label>
                    <select wire:model="city" class="w-full rounded-lg border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">— svi gradovi —</option>
                        @foreach ($this->cities as $c)
                            <option value="{{ $c }}">{{ $c }}</option>
                        @endforeach
                    </select>
                </div>

                <button
                    wire:click="lookup"
                    wire:loading.attr="disabled"
                    wire:target="lookup"
                    class="w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:bg-slate-300"
                >
                    <span wire:loading.remove wire:target="lookup">Provjeri cijenu</span>
                    <span wire:loading wire:target="lookup">Tražim…</span>
                </button>
            </div>
        </div>
    </div>

    @if ($error)
        <p class="text-sm text-rose-600">{{ $error }}</p>
    @endif

    @if ($result)
        <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-3">
                <h2 class="font-medium text-slate-900">{{ $result['name'] }}</h2>
                <p class="text-xs text-slate-400">Barkod: {{ $lastScanned }}</p>
            </div>

            @if ($result['offers'] === [])
                <p class="px-5 py-6 text-center text-sm text-slate-400">Nema cijena za prikaz.</p>
            @else
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-2">Lanac</th>
                            <th class="px-5 py-2">Primjer poslovnice</th>
                            <th class="px-5 py-2">Broj poslovnica</th>
                            <th class="px-5 py-2 text-right">Cijena</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($result['offers'] as $offer)
                            <tr>
                                <td class="px-5 py-3 font-medium text-slate-800">{{ $offer['chain_name'] }}</td>
                                <td class="px-5 py-3 text-slate-600">{{ $offer['sample_address'] ?? $offer['sample_city'] }}</td>
                                <td class="px-5 py-3 text-slate-600">{{ $offer['store_count'] }}</td>
                                <td class="px-5 py-3 text-right font-medium text-slate-800">
                                    {{ number_format($offer['price'], 2, ',', '.') }} €
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endif

    <script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script>
        // Started on demand (e.g. when this tab is first opened) rather than
        // on page load, since the reader's a few page away — starting the
        // camera immediately would prompt for permission before the user
        // even asked to scan anything, and measuring the container while a
        // parent tab panel is hidden can leave it mis-sized.
        window.initBarcodeScanner = window.initBarcodeScanner || function () {
            if (window.initBarcodeScanner.started || typeof Html5QrcodeScanner === 'undefined') {
                return;
            }

            window.initBarcodeScanner.started = true;

            const scanner = new Html5QrcodeScanner('barcode-reader', {
                fps: 10,
                qrbox: { width: 280, height: 140 },
                formatsToSupport: [
                    Html5QrcodeSupportedFormats.EAN_13,
                    Html5QrcodeSupportedFormats.EAN_8,
                    Html5QrcodeSupportedFormats.UPC_A,
                    Html5QrcodeSupportedFormats.UPC_E,
                    Html5QrcodeSupportedFormats.CODE_128,
                ],
            }, false);

            let locked = false;

            scanner.render((decodedText) => {
                if (locked) {
                    return;
                }

                locked = true;
                @this.call('lookup', decodedText);
                setTimeout(() => { locked = false; }, 2000);
            });
        };
    </script>
</div>
