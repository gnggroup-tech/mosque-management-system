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
            'id', 'name', 'email', 'email_verified_at', 'locale', 'created_at', 'updated_at',
        ]);
        $row['password'] = Hash::make(base64_encode(random_bytes(32)));
        $row['remember_token'] = null;

        return $row;
    }
}
