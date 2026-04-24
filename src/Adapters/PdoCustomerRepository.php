<?php

declare(strict_types=1);

namespace LGSB\Adapters;

use LGSB\Domain\Customer;
use LGSB\Domain\Repositories\CustomerRepository;
use PDO;

final class PdoCustomerRepository implements CustomerRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function findById(int $id): ?Customer
    {
        return $this->fetchOne(
            'SELECT * FROM customers WHERE id = ? AND deleted_at IS NULL LIMIT 1',
            [$id],
        );
    }

    public function findByUuid(string $uuid): ?Customer
    {
        return $this->fetchOne(
            'SELECT * FROM customers WHERE uuid = ? AND deleted_at IS NULL LIMIT 1',
            [$uuid],
        );
    }

    public function findByEmail(string $email): ?Customer
    {
        return $this->fetchOne(
            'SELECT * FROM customers WHERE email = ? AND deleted_at IS NULL LIMIT 1',
            [$email],
        );
    }

    public function findByStripeCustomerId(string $stripeCustomerId): ?Customer
    {
        return $this->fetchOne(
            'SELECT * FROM customers WHERE stripe_customer_id = ? AND deleted_at IS NULL LIMIT 1',
            [$stripeCustomerId],
        );
    }

    public function create(
        string  $email,
        ?string $name,
        ?string $stripeCustomerId,
        ?string $country,
    ): Customer {
        $uuid = Uuid::v4();
        $stmt = $this->pdo->prepare(
            'INSERT INTO customers (uuid, stripe_customer_id, email, name, country)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$uuid, $stripeCustomerId, $email, $name, $country]);
        return new Customer(
            id: (int) $this->pdo->lastInsertId(),
            uuid: $uuid,
            stripeCustomerId: $stripeCustomerId,
            email: $email,
            name: $name,
            country: $country,
            locale: null,
        );
    }

    public function updateStripeCustomerId(int $customerId, string $stripeCustomerId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE customers SET stripe_customer_id = ? WHERE id = ?'
        );
        $stmt->execute([$stripeCustomerId, $customerId]);
    }

    private function fetchOne(string $sql, array $params): ?Customer
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? self::toDto($row) : null;
    }

    private static function toDto(array $row): Customer
    {
        return new Customer(
            id: (int) $row['id'],
            uuid: (string) $row['uuid'],
            stripeCustomerId: $row['stripe_customer_id'] !== null ? (string) $row['stripe_customer_id'] : null,
            email: (string) $row['email'],
            name: $row['name'] !== null ? (string) $row['name'] : null,
            country: $row['country'] !== null ? (string) $row['country'] : null,
            locale: $row['locale'] !== null ? (string) $row['locale'] : null,
        );
    }
}
