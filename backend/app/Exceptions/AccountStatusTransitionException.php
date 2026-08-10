<?php

namespace App\Exceptions;

use DomainException;

class AccountStatusTransitionException extends DomainException
{
    public static function unpersistedAccount(): self
    {
        return new self('The target account must be persisted.');
    }

    public static function unpersistedActor(): self
    {
        return new self('The transition actor must be persisted.');
    }

    public static function invalidTransition(string $from, string $to): self
    {
        return new self("Account status transition from {$from} to {$to} is not allowed.");
    }

    public static function suspensionReasonRequired(): self
    {
        return new self('A suspension reason is required.');
    }

    public static function suspensionReasonTooLong(): self
    {
        return new self('The suspension reason exceeds the supported length.');
    }
}
