<?php

declare(strict_types=1);

namespace LGSB\Core;

use LGSB\Contracts\SettingsStore;
use LGSB\Domain\GiftCode;

final class GiftCodeMailer
{
    public function __construct(private readonly SettingsStore $settings) {}

    /**
     * Email gift codes to the purchaser.
     *
     * Uses PHP's mail() — swap for an SMTP/SES adapter when ready.
     *
     * @param GiftCode[] $codes
     */
    public function sendGiftCodes(string $toEmail, string $toName, array $codes): void
    {
        if ($codes === []) {
            return;
        }

        $from    = $this->settings->getMailFrom();
        $count   = count($codes);
        $tier    = $codes[0]->tier;
        $days    = $codes[0]->durationDays;
        $subject = "Your {$count} Looth Gift Membership Code" . ($count > 1 ? 's' : '');

        $codeLines = implode("\n", array_map(
            static fn (GiftCode $c): string => "  {$c->code}",
            $codes,
        ));

        $body = <<<TEXT
Hi {$toName},

Thank you for your purchase! Here are your {$count} Looth Gift Membership code(s).
Each code grants a {$days}-day membership.

{$codeLines}

To redeem, visit loothgroup.com and enter your code when prompted.
Each code can only be used once.

Thanks,
The Looth Team
TEXT;

        $headers = implode("\r\n", [
            "From: {$from}",
            'Content-Type: text/plain; charset=UTF-8',
            'MIME-Version: 1.0',
        ]);

        mail($toEmail, $subject, $body, $headers);
    }
}
