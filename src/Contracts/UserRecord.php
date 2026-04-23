<?php

declare(strict_types=1);

namespace LGSB\Contracts;

readonly class UserRecord
{
    public function __construct(
        public int    $id,
        public string $email,
        public string $username,
        /** @var string[] */
        public array  $roles,
    ) {}

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function isProtected(): bool
    {
        return $this->hasRole('looth4');
    }
}
