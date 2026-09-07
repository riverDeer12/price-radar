<?php

use App\Jobs\ImportPriceArchive;
use App\Models\ArchiveImport;
use App\Models\Chain;
use App\Models\Price;
use App\Models\Product;
use App\Models\Store;
use App\Services\ArchiveImportService;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public ?string $triggerError = null;

    public ?string $triggerMessage = null;

    #[Computed]
    public function imports()
    {
        return ArchiveImport::orderByDesc('archive_date')->limit(10)->get();
    }

    #[Computed]
    public function stats(): array
    {
        return [
            'chains' => Chain::count(),
            'stores' => Store::count(),
            'products' => Product::count(),
            'prices' => Price::count(),
        ];
    }

    #[Computed]
    public function isImportRunning(): bool
    {
        return ArchiveImport::whereIn('status', ['pending', 'downloading', 'importing'])->exists();
    }

    public function triggerImport(ArchiveImportService $service): void
    {
        $this->triggerError = null;
        $this->triggerMessage = null;

        if ($this->isImportRunning) {
            $this->triggerError = 'Uvoz je već u tijeku.';

            return;
        }

        try {
            $archive = $service->latestArchive();
        } catch (\Throwable $e) {
            $this->triggerError = 'Nije moguće dohvatiti popis arhiva sa cijene.dev: '.$e->getMessage();

            return;
        }

        if (! $archive) {
            $this->triggerError = 'Trenutno nema dostupnih arhiva na cijene.dev.';

            return;
        }

        $existing = ArchiveImport::where('archive_date', $archive['date'])->first();

        if ($existing && $existing->status === 'completed') {
            $this->triggerMessage = "Podaci za {$archive['date']} su već ažurni (uvezeno ".$existing->finished_at?->diffForHumans().').';

            return;
        }

        ImportPriceArchive::dispatch($archive['date'], $archive['url'], $archive['size'] ?? null, 'manual');

        $this->triggerMessage = "Uvoz arhive za {$archive['date']} je pokrenut u pozadini.";
    }
};
?>

<div wire:poll.5s class="space-y-8">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">Nadzorna ploča</h1>
            <p class="mt-1 text-sm text-slate-500">Dnevni uvoz arhive cijena s cijene.dev.</p>
        </div>

        <div class="flex flex-col items-end gap-2">
            <button
                wire:click="triggerImport"
                wire:loading.attr="disabled"
                wire:target="triggerImport"
                @if($this->isImportRunning) disabled @endif
                class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:bg-slate-300"
            >
                <span wire:loading.remove wire:target="triggerImport">
                    @if($this->isImportRunning)
                        Uvoz u tijeku…
                    @else
                        Preuzmi arhivu sada
                    @endif
                </span>
                <span wire:loading wire:target="triggerImport">Pokrećem…</span>
            </button>

            @if ($triggerMessage)
                <p class="text-sm text-emerald-600">{{ $triggerMessage }}</p>
            @endif

            @if ($triggerError)
                <p class="text-sm text-rose-600">{{ $triggerError }}</p>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
        @foreach ([
            'Trgovački lanci' => $this->stats['chains'],
            'Poslovnice' => $this->stats['stores'],
            'Proizvodi' => $this->stats['products'],
            'Trenutne cijene' => $this->stats['prices'],
        ] as $label => $value)
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900">{{ number_format($value, 0, ',', '.') }}</p>
            </div>
        @endforeach
    </div>

    <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-3">
            <h2 class="font-medium text-slate-900">Povijest uvoza</h2>
        </div>

        <table class="w-full text-left text-sm">
            <thead class="text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-5 py-2">Datum arhive</th>
                    <th class="px-5 py-2">Status</th>
                    <th class="px-5 py-2">Način pokretanja</th>
                    <th class="px-5 py-2">Trajanje</th>
                    <th class="px-5 py-2">Napomena</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($this->imports as $import)
                    <tr>
                        <td class="px-5 py-3 font-medium text-slate-800">{{ $import->archive_date->format('d.m.Y.') }}</td>
                        <td class="px-5 py-3">
                            @php
                                $badge = match ($import->status) {
                                    'completed' => 'bg-emerald-100 text-emerald-700',
                                    'failed' => 'bg-rose-100 text-rose-700',
                                    default => 'bg-amber-100 text-amber-700',
                                };
                            @endphp
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $badge }}">{{ $import->status }}</span>
                        </td>
                        <td class="px-5 py-3 text-slate-600">{{ $import->triggered_by === 'manual' ? 'Ručno' : 'Raspored' }}</td>
                        <td class="px-5 py-3 text-slate-600">
                            @if ($import->started_at && $import->finished_at)
                                {{ $import->started_at->diffForHumans($import->finished_at, true) }}
                            @elseif ($import->started_at)
                                u tijeku…
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-5 py-3 text-slate-500">
                            @if ($import->status === 'failed')
                                <span class="text-rose-600">{{ \Illuminate\Support\Str::limit($import->error_message, 80) }}</span>
                            @elseif ($import->stats)
                                {{ $import->stats['prices_upserted'] ?? '?' }} cijena, {{ $import->stats['stale_removed'] ?? 0 }} uklonjeno
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-5 py-6 text-center text-slate-400">Još nema pokrenutih uvoza.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
