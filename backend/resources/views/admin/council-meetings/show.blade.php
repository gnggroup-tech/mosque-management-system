<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between gap-3">
            <div>
                <h1 class="text-lg font-bold">{{ $meeting->title }}</h1>
                <p class="text-xs text-slate-500">{{ $meeting->council->name }} &middot; {{ $meeting->council->mosque->name }}</p>
            </div>
            <x-status-badge :status="$meeting->status" />
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 px-4 py-8">
        <section class="grid gap-4 rounded-2xl border bg-white p-6 shadow-sm sm:grid-cols-3">
            <div><small class="text-slate-500">{{ __('Scheduled at') }}</small><p>{{ $meeting->scheduled_at->translatedFormat('d M Y H:i') }}</p></div>
            <div><small class="text-slate-500">{{ __('Location') }}</small><p>{{ $meeting->location ?: __('Not specified') }}</p></div>
            <div><small class="text-slate-500">{{ __('Quorum required') }}</small><p>{{ $meeting->quorum_required }}</p></div>
            <div class="sm:col-span-3"><small class="text-slate-500">{{ __('Agenda') }}</small><p class="mt-1 whitespace-pre-line text-sm">{{ $meeting->agenda }}</p></div>
        </section>

        <section class="rounded-2xl border bg-white p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="font-bold">{{ __('Participants and notice status') }}</h2>
                    <p class="text-xs text-slate-500">{{ __('Queued and sent indicate processing states, not delivery or reading proof.') }}</p>
                </div>
                @can('council-meetings.manage')
                    @if (in_array($meeting->status, ['draft', 'convened']))
                        <form method="POST" action="{{ route('admin.council-meetings.send-notice', $meeting) }}" onsubmit="return confirm('{{ __('Queue council notices now?') }}')">
                            @csrf
                            <button class="rounded-xl bg-sky-600 px-4 py-2 text-sm font-semibold text-white">{{ __('Queue notices') }}</button>
                        </form>
                    @endif
                @endcan
            </div>

            <form method="POST" action="{{ route('admin.council-meetings.attendance', $meeting) }}" class="mt-4 divide-y">
                @csrf
                @foreach ($meeting->participants as $index => $participant)
                    @php($notice = $participant->notice_sent_at ? 'sent' : ($participant->notice_failed_at ? 'failed' : ($participant->notice_queued_at ? 'queued' : 'pending')))
                    <div class="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <strong>{{ $participant->member->user->name }}</strong>
                            <small class="block text-slate-500">{{ __($participant->member->function) }}</small>
                        </div>
                        <div class="flex items-center gap-3">
                            <x-status-badge :status="$notice" />
                            @can('council-meetings.manage')
                                <input type="hidden" name="participants[{{ $index }}][id]" value="{{ $participant->id }}">
                                <select name="participants[{{ $index }}][status]" class="rounded-xl border-slate-300 text-sm">
                                    @foreach (['present', 'absent', 'excused'] as $status)
                                        <option value="{{ $status }}" @selected($participant->attendance_status === $status)>{{ __($status) }}</option>
                                    @endforeach
                                </select>
                            @else
                                <x-status-badge :status="$participant->attendance_status" />
                            @endcan
                        </div>
                    </div>
                @endforeach
                @can('council-meetings.manage')
                    <button class="mt-4 rounded-xl border px-4 py-2 text-sm font-semibold">{{ __('Save attendance') }}</button>
                @endcan
            </form>
        </section>

        @can('council-meetings.manage')
            @if ($meeting->status === 'convened')
                <section class="rounded-2xl border bg-white p-6">
                    <h2 class="font-bold">{{ __('Minutes and quorum') }}</h2>
                    <form method="POST" action="{{ route('admin.council-meetings.close', $meeting) }}" class="mt-4">
                        @csrf
                        <textarea name="minutes" required rows="7" class="w-full rounded-xl border-slate-300" placeholder="{{ __('Meeting minutes') }}"></textarea>
                        <button onclick="return confirm('{{ __('Close these minutes?') }}')" class="mt-3 rounded-xl bg-emerald-600 px-4 py-2 font-semibold text-white">{{ __('Close minutes') }}</button>
                    </form>
                </section>
            @endif

            @if ($meeting->status === 'completed')
                <section class="rounded-2xl border bg-white p-6">
                    <h2 class="font-bold">{{ __('Record decision') }}</h2>
                    <form method="POST" action="{{ route('admin.council-meetings.decisions.store', $meeting) }}" class="mt-4 grid gap-3 sm:grid-cols-3">
                        @csrf
                        <input name="title" required placeholder="{{ __('Title') }}" class="rounded-xl border-slate-300 sm:col-span-2">
                        <select name="outcome" class="rounded-xl border-slate-300">
                            @foreach (['approved', 'rejected', 'deferred'] as $value)
                                <option value="{{ $value }}">{{ __($value) }}</option>
                            @endforeach
                        </select>
                        <textarea name="description" required placeholder="{{ __('Description') }}" class="rounded-xl border-slate-300 sm:col-span-3"></textarea>
                        @foreach (['votes_for', 'votes_against', 'abstentions'] as $field)
                            <input type="number" min="0" name="{{ $field }}" value="0" class="rounded-xl border-slate-300" aria-label="{{ __($field) }}">
                        @endforeach
                        <button class="rounded-xl bg-emerald-600 px-4 py-2 font-semibold text-white sm:col-span-3">{{ __('Record decision') }}</button>
                    </form>
                </section>
            @endif
        @endcan

        @if ($meeting->minutes)
            <section class="rounded-2xl border bg-white p-6">
                <h2 class="font-bold">{{ __('Meeting minutes') }}</h2>
                <p class="mt-3 whitespace-pre-line text-sm">{{ $meeting->minutes }}</p>
            </section>
        @endif

        <section class="rounded-2xl border bg-white p-6">
            <h2 class="font-bold">{{ __('Decisions') }}</h2>
            <div class="mt-3 divide-y">
                @forelse ($meeting->decisions as $decision)
                    <article class="py-4">
                        <div class="flex justify-between"><strong>{{ $decision->title }}</strong><x-status-badge :status="$decision->outcome" /></div>
                        <p class="mt-2 text-sm">{{ $decision->description }}</p>
                    </article>
                @empty
                    <p class="py-6 text-sm text-slate-500">{{ __('No decisions.') }}</p>
                @endforelse
            </div>
        </section>
    </div>
</x-app-layout>
