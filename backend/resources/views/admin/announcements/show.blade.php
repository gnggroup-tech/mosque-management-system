<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between gap-3">
            <div>
                <h1 class="text-lg font-bold">{{ $announcement->title }}</h1>
                <p class="text-xs text-slate-500">{{ $announcement->mosque?->name ?? __('National') }}</p>
            </div>
            <x-status-badge :status="$announcement->status" />
        </div>
    </x-slot>

    <div class="mx-auto max-w-4xl space-y-6 px-4 py-8">
        <article class="rounded-2xl border bg-white p-6 shadow-sm">
            <div class="flex flex-wrap gap-2">
                <x-status-badge :status="$announcement->type" />
                <x-status-badge :status="$announcement->priority" />
                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs">{{ __($announcement->audience) }}</span>
            </div>

            <p class="mt-6 whitespace-pre-line text-slate-700">{{ $announcement->body }}</p>

            <dl class="mt-6 grid gap-3 border-t pt-5 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-slate-500">{{ __('Available internally') }}</dt>
                    <dd>{{ $receipt?->available_at?->translatedFormat('d M Y H:i') ?? __('Not applicable') }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">{{ __('Read at') }}</dt>
                    <dd>{{ $receipt?->read_at?->translatedFormat('d M Y H:i') ?? __('Unread') }}</dd>
                </div>
            </dl>

            <p class="mt-4 text-xs text-slate-500">{{ __('Internal availability is not proof of external delivery or reading.') }}</p>

            <div class="mt-6 flex flex-wrap gap-3">
                @can('announcements.manage')
                    @if ($announcement->status === 'draft')
                        <a href="{{ route('admin.announcements.edit', $announcement) }}" class="rounded-xl border px-4 py-2 font-semibold">{{ __('Edit') }}</a>
                        <form method="POST" action="{{ route('admin.announcements.publish', $announcement) }}" onsubmit="return confirm('{{ __('Publish this announcement internally?') }}')">
                            @csrf
                            <button class="rounded-xl bg-emerald-600 px-4 py-2 font-semibold text-white">{{ __('Publish') }}</button>
                        </form>
                    @endif
                @endcan

                @if ($receipt && ! $receipt->read_at)
                    <form method="POST" action="{{ route('admin.announcements.read', $announcement) }}">
                        @csrf
                        <button class="rounded-xl bg-sky-600 px-4 py-2 font-semibold text-white">{{ __('Mark as read') }}</button>
                    </form>
                @endif
            </div>
        </article>
    </div>
</x-app-layout>
