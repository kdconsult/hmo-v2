<?php

namespace App\DTOs;

readonly class PartnerMutationIntent
{
    public function __construct(
        public bool $downgradeToNotRegistered,
        public ?string $reason = null,
    ) {}

    public static function none(): self
    {
        return new self(downgradeToNotRegistered: false);
    }

    public static function downgrade(string $reason): self
    {
        return new self(downgradeToNotRegistered: true, reason: $reason);
    }
}
