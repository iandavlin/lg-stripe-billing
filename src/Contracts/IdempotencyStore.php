<?php

declare(strict_types=1);

namespace LGSB\Contracts;

interface IdempotencyStore
{
    public function hasProcessed(string $eventId): bool;

    public function markProcessed(string $eventId): void;
}
