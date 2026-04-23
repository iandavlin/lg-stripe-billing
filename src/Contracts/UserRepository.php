<?php

declare(strict_types=1);

namespace LGSB\Contracts;

interface UserRepository
{
    public function findByCustomerId(string $customerId): ?UserRecord;

    public function findByEmail(string $email): ?UserRecord;

    public function findById(int $userId): ?UserRecord;

    /** @return UserRecord[] */
    public function findStripePaidMembers(): array;

    /**
     * @throws UserCreateException
     * @return int New user ID.
     */
    public function create(string $email, string $name, string $role): int;

    public function setRole(int $userId, string $role): void;

    public function getMeta(int $userId, string $key): string;

    public function setMeta(int $userId, string $key, string $value): void;

    public function deleteMeta(int $userId, string $key): void;

    /** Null on failure. */
    public function generatePasswordResetUrl(int $userId): ?string;
}
