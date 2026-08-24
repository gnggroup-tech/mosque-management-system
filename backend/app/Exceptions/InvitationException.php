<?php

namespace App\Exceptions;

use RuntimeException;

class InvitationException extends RuntimeException
{
    public static function invalid(): self
    {
        return new self('The invitation is invalid or has expired.');
    }

    public static function accountUnavailable(): self
    {
        return new self('The account cannot be invited.');
    }
}
