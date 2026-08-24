<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

class BackupRestorePreparer
{
    /**
     * Prepare a decrypted user payload for insertion into an empty migrated database.
     *
     * The random password is intentionally discarded: no operator or restored user
     * can authenticate with it, so the password-reset flow is mandatory.
     */
    public function user(array $payload): array
    {
        foreach (['id', 'name', 'email'] as $required) {
            if (! array_key_exists($required, $payload)) {
                throw new InvalidArgumentException("Missing required user field: {$required}");
            }
        }

        $row = Arr::only($payload, [
            'id', 'name', 'email', 'email_verified_at', 'locale', 'status',
            'activated_at', 'suspended_at', 'suspension_reason', 'archived_at',
            'verification_required_at', 'verification_exempt_until',
            'created_at', 'updated_at',
        ]);
        $row['password'] = Hash::make(base64_encode(random_bytes(32)));
        $row['remember_token'] = null;

        return $row;
    }

    /**
     * Preserve invitation history while making every unaccepted link unusable.
     *
     * The replacement token is random and immediately discarded. A later resend
     * rotates it again and is the only way to obtain a usable invitation link.
     */
    public function invitation(array $payload): array
    {
        foreach (['id', 'user_id', 'token_hash', 'expires_at'] as $required) {
            if (! array_key_exists($required, $payload)) {
                throw new InvalidArgumentException("Missing required invitation field: {$required}");
            }
        }

        $row = Arr::only($payload, [
            'id', 'user_id', 'invited_by', 'token_hash', 'expires_at',
            'accepted_at', 'created_at', 'updated_at',
        ]);

        if (($row['accepted_at'] ?? null) === null) {
            $row['token_hash'] = hash('sha256', random_bytes(32));
            $row['expires_at'] = now()->subSecond()->format('Y-m-d H:i:s');
        }

        return $row;
    }
}
