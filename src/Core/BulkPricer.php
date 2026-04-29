<?php

declare(strict_types=1);

namespace LGSB\Core;

final class BulkPricer
{
    /**
     * @param array<array{min:int,pct:int}> $tiers sorted descending by min so the
     *                                              first match wins (e.g. 50→20→10)
     */
    public function __construct(private readonly array $tiers) {}

    /** Parse "10:10,20:20,50:30" env string into a BulkPricer. */
    public static function fromEnvString(string $raw): self
    {
        if ($raw === '') {
            return new self([]);
        }

        $tiers = [];
        foreach (explode(',', $raw) as $chunk) {
            $parts = explode(':', trim($chunk), 2);
            if (count($parts) !== 2) {
                continue;
            }
            $min = (int) $parts[0];
            $pct = (int) $parts[1];
            if ($min > 0 && $pct > 0 && $pct < 100) {
                $tiers[] = ['min' => $min, 'pct' => $pct];
            }
        }

        usort($tiers, static fn (array $a, array $b): int => $b['min'] <=> $a['min']);
        return new self($tiers);
    }

    /** @return array<array{min:int,pct:int}> */
    public function tiers(): array
    {
        return $this->tiers;
    }

    /** Discount percentage for this quantity. 0 if below lowest tier. */
    public function discountPct(int $qty): int
    {
        foreach ($this->tiers as $tier) {
            if ($qty >= $tier['min']) {
                return $tier['pct'];
            }
        }
        return 0;
    }

    /** Per-seat price in cents after bulk discount. */
    public function discountedUnitAmountCents(int $baseCents, int $qty): int
    {
        $pct = $this->discountPct($qty);
        return (int) floor($baseCents * (100 - $pct) / 100);
    }
}
