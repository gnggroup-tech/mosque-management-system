@php
    $actions = [];

    if ($account->isPendingApproval() && Illuminate\Support\Facades\Gate::allows('approve', $account)) {
        $actions['approve'] = route('admin.accounts.approve', $account);
    }
    if ($account->isActive() && Illuminate\Support\Facades\Gate::allows('suspend', $account)) {
        $actions['suspend'] = route('admin.accounts.suspend', $account);
    }
    if ($account->isSuspended() && Illuminate\Support\Facades\Gate::allows('reactivate', $account)) {
        $actions['reactivate'] = route('admin.accounts.reactivate', $account);
    }
    if (($account->isActive() || $account->isSuspended()) && Illuminate\Support\Facades\Gate::allows('archive', $account)) {
        $actions['archive'] = route('admin.accounts.archive', $account);
    }

    $modalName = 'account-action-'.$account->getKey();
@endphp

@if ($actions !== [])
    <div
        class="flex flex-wrap gap-2"
        x-data="accountActions(@js([
            'accountName' => $account->name,
            'actions' => $actions,
            'labels' => [
                'approve' => __('Approve account'),
                'suspend' => __('Suspend account'),
                'reactivate' => __('Reactivate account'),
                'archive' => __('Archive account'),
            ],
            'messages' => [
                403 => __('You are not authorized to perform this action.'),
                404 => __('The account could not be found.'),
                419 => __('Your session has expired. Please refresh the page.'),
                422 => __('The request is invalid or the account status has changed.'),
                'archiveConfirmation' => __('Enter the exact account name to confirm archival.'),
                'defaultError' => __('The action could not be completed.'),
                'networkError' => __('The server could not be reached. Please try again.'),
                'success' => [
                    'approve' => __('The account was approved successfully.'),
                    'suspend' => __('The account was suspended successfully.'),
                    'reactivate' => __('The account was reactivated successfully.'),
                    'archive' => __('The account was archived successfully.'),
                ],
            ],
        ]))"
    >
        @foreach ($actions as $action => $endpoint)
            <button
                type="button"
                data-testid="account-action-{{ $action }}-{{ $account->getKey() }}"
                data-requires-confirmation="{{ $action === 'archive' ? 'true' : 'false' }}"
                class="inline-flex items-center rounded-md px-3 py-2 text-xs font-semibold focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 {{ $action === 'archive' ? 'bg-red-700 text-white hover:bg-red-600 focus:ring-red-600' : 'bg-gray-800 text-white hover:bg-gray-700 focus:ring-gray-500' }}"
                x-bind:disabled="submitting"
                x-on:click="open('{{ $action }}'); $dispatch('open-modal', '{{ $modalName }}')"
            >
                {{ __(Illuminate\Support\Str::headline($action)) }}
            </button>
        @endforeach

        <x-modal :name="$modalName" focusable maxWidth="lg">
            <form method="POST" x-bind:action="endpoint" x-on:submit.prevent="submit" class="p-6">
                @csrf
                @method('PATCH')

                <h2 class="text-lg font-medium text-gray-900" x-text="title()"></h2>
                <p class="mt-2 text-sm text-gray-600">
                    {{ __('Account') }}: <strong>{{ $account->name }}</strong>
                </p>

                <div class="mt-5" x-show="action !== 'approve'">
                    <label for="reason-{{ $account->getKey() }}" class="block text-sm font-medium text-gray-700">
                        {{ __('Administrative reason') }}
                    </label>
                    <textarea
                        id="reason-{{ $account->getKey() }}"
                        name="reason"
                        rows="4"
                        minlength="3"
                        maxlength="500"
                        x-model="reason"
                        x-bind:required="action !== 'approve'"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    ></textarea>
                    <p class="mt-1 text-xs text-gray-500">{{ __('Between 3 and 500 characters.') }}</p>
                </div>

                <div class="mt-5" x-show="action === 'archive'">
                    <label for="confirmation-{{ $account->getKey() }}" class="block text-sm font-medium text-red-700">
                        {{ __('Type :name to confirm permanent archival', ['name' => $account->name]) }}
                    </label>
                    <input
                        id="confirmation-{{ $account->getKey() }}"
                        type="text"
                        x-model="confirmation"
                        x-bind:required="action === 'archive'"
                        autocomplete="off"
                        class="mt-1 block w-full rounded-md border-red-300 shadow-sm focus:border-red-500 focus:ring-red-500"
                    >
                </div>

                <p x-cloak x-show="error" x-text="error" class="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-700" role="alert"></p>

                <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                        x-bind:disabled="submitting"
                        x-on:click="$dispatch('close')"
                    >
                        {{ __('Cancel') }}
                    </button>
                    <button
                        type="submit"
                        data-testid="account-action-submit-{{ $account->getKey() }}"
                        class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700 disabled:cursor-not-allowed disabled:opacity-50"
                        x-bind:disabled="submitting"
                    >
                        <span x-show="! submitting">{{ __('Confirm action') }}</span>
                        <span x-cloak x-show="submitting">{{ __('Processing…') }}</span>
                    </button>
                </div>
            </form>
        </x-modal>
    </div>
@endif
