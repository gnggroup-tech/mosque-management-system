export default function registerAccountDirectory(Alpine) {
    Alpine.data('accountDirectoryPage', () => ({
        loading: false,
        message: '',

        init() {
            try {
                this.message = window.sessionStorage.getItem('account-directory-success') || '';
                window.sessionStorage.removeItem('account-directory-success');
            } catch {
                this.message = '';
            }
        },
    }));

    Alpine.data('accountActions', (config) => ({
        action: null,
        endpoint: '',
        reason: '',
        confirmation: '',
        error: '',
        submitting: false,

        open(action) {
            this.action = action;
            this.endpoint = config.actions[action];
            this.reason = '';
            this.confirmation = '';
            this.error = '';
        },

        title() {
            return config.labels[this.action] || '';
        },

        async submit() {
            if (this.submitting || !this.action || !this.endpoint) {
                return;
            }

            if (this.action === 'archive' && this.confirmation !== config.accountName) {
                this.error = config.messages.archiveConfirmation;

                return;
            }

            this.submitting = true;
            this.error = '';

            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
                const response = await fetch(this.endpoint, {
                    method: 'PATCH',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                    },
                    body: JSON.stringify(this.action === 'approve' ? {} : { reason: this.reason }),
                });

                if (!response.ok) {
                    this.error = config.messages[response.status] || config.messages.defaultError;

                    return;
                }

                try {
                    window.sessionStorage.setItem('account-directory-success', config.messages.success[this.action]);
                } catch {
                    // The reload still refreshes the authoritative account state when storage is unavailable.
                }

                window.location.reload();
            } catch {
                this.error = config.messages.networkError;
            } finally {
                this.submitting = false;
            }
        },
    }));
}
