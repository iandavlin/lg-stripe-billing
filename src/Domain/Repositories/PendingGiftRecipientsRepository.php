<?php

declare(strict_types=1);

namespace LGSB\Domain\Repositories;

/**
 * Buyer-supplied recipient list captured on POST /v1/checkout, persisted
 * across the Stripe checkout redirect, consumed on /v1/return when we
 * actually generate the gift codes.
 *
 * Stored separately from gift_codes because the codes don't exist yet
 * during checkout intent — and Stripe metadata can't reliably hold a
 * 50-recipient batch (8KB total cap, 500 chars per value).
 */
interface PendingGiftRecipientsRepository
{
    /**
     * Save the buyer's recipient list against a Stripe Checkout session ID.
     * Each recipient is {email?, name?, message?} — all fields optional.
     * Position in the array becomes the position field, used to map each
     * recipient to the corresponding generated code on return.
     *
     * @param list<array{email?:?string, name?:?string, message?:?string}> $recipients
     */
    public function store(string $stripeCheckoutSessionId, array $recipients): void;

    /**
     * Read recipients for a session, ordered by position, then delete them.
     * Returns an empty array if none were stored (legacy "buyer keeps the
     * code" mode).
     *
     * @return list<array{email:?string, name:?string, message:?string}>
     */
    public function consume(string $stripeCheckoutSessionId): array;
}
