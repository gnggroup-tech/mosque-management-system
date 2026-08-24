<?php

namespace App\Exceptions;

use RuntimeException;

class AccountProvisioningException extends RuntimeException
{
    public static function invalid(string $message = 'The account provisioning request is invalid or stale.'): self
    {
        return new self($message);
    }
}
