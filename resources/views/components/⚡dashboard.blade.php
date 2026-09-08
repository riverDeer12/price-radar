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
    public function currentImport(): ?ArchiveImport
    {
        return ArchiveImport::whereIn('status', ['pending', 'downloading', 'importing'])
            ->orderByDesc('id')
            ->first();
    }

    #[Computed]
    public function isImportRunning(): bool
    {
        return $this->currentImport !== null;
    }

    /**
     * Rough "how much longer" estimate from progress-so-far, not shown
     * until there's enough signal to not be wildly wrong.
     */
    public function estimateEtaSeconds(ArchiveImport $import): ?int
    {
        $progress = $import->progress ?? [];
        $phase = $progress['phase'] ?? null;

        if ($phase === 'downloading' && ($progress['percent'] ?? 0) >= 3) {
            $elapsed = now()->diffInSeconds($import->started_at);
            $percent = $progress['percent'];

            return (int) round($elapsed / $percent * (100 - $percent));
        }

        if ($phase === 'importing' && ($progress['chains_done'] ?? 0) >= 2 && ($progress['phase_started_at'] ?? null)) {
            $phaseStart = \Illuminate\Support\Carbon::parse($progress['phase_started_at']);
            $elapsed = now()->diffInSeconds($phaseStart);
            $done = $progress['chains_done'];
            $total = $progress['chains_total'] ?? 0;

            if ($done <= 0 || $total <= $done) {
                return null;
            }

            return (int) round($elapsed / $done * ($total - $done));
        }

        return null;
    }

    public function formatEta(?int $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }

        if ($seconds < 60) {
            return 'manje od minute';
        }

        return '~'.(int) round($seconds / 60).' min';
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

    @if ($this->currentImport)
        @php
            $progress = $this->currentImport->progress ?? [];
            $phase = $progress['phase'] ?? 'downloading';
            $eta = $this->formatEta($this->estimateEtaSeconds($this->currentImport));

            $phaseLabel = match ($phase) {
                'downloading' => 'Preuzimanje arhive s cijene.dev',
                'extracting' => 'Raspakiravanje arhive',
                'importing' => 'Obrada trgovačkih lanaca',
                'finalizing' => 'Spremanje u bazu (povijest cijena i trenutne cijene)',
                default => 'Uvoz u tijeku',
            };

            $percent = match ($phase) {
                'downloading' => $progress['percent'] ?? 0,
                'importing' => ($progress['chains_total'] ?? 0) > 0
                    ? (int) round((($progress['chains_done'] ?? 0) / $progress['chains_total']) * 100)
                    : 0,
                default => null,
            };
        @endphp

        <div class="rounded-xl border border-indigo-200 bg-indigo-50 p-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2 text-sm font-medium text-indigo-900">
                    <svg class="h-4 w-4 animate-spin text-indigo-600" viewBox="0 0 24 24" fill="none">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                    {{ $phaseLabel }}
                </div>
                <div class="text-xs text-indigo-700">
                    traje {{ $this->currentImport->started_at->diffForHumans(now(), true) }}
                    @if ($eta)
                        &middot; preostalo {{ $eta }}
                    @endif
                </div>
            </div>

            @if ($phase === 'importing' && ($progress['current_chain_name'] ?? null))
                <p class="mt-1 text-xs text-indigo-700">
                    Trenutni lanac: <span class="font-medium">{{ $progress['current_chain_name'] }}</span>
                    ({{ $progress['chains_done'] ?? 0 }}/{{ $progress['chains_total'] ?? '?' }})
                </p>
            @elseif ($phase === 'downloading' && ($progress['total_bytes'] ?? null))
                <p class="mt-1 text-xs text-indigo-700">
                    {{ number_format(($progress['downloaded_bytes'] ?? 0) / 1048576, 1) }} MB
                    / {{ number_format($progress['total_bytes'] / 1048576, 1) }} MB
                </p>
            @elseif ($phase === 'finalizing')
                <p class="mt-1 text-xs text-indigo-700">Ovo može potrajati par minuta za velike lance — bez brige, ne treba osvježavati stranicu.</p>
            @endif

            <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-indigo-100">
                @if ($percent !== null)
                    <div class="h-full rounded-full bg-indigo-600 transition-all duration-500" style="width: {{ max(3, $percent) }}%"></div>
                @else
                    <div class="h-full w-1/3 animate-pulse rounded-full bg-indigo-600"></div>
                @endif
            </div>
        </div>
    @endif

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
