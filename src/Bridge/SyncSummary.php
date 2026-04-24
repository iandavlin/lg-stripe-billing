<?php

declare(strict_types=1);

namespace LGSB\Bridge;

readonly class SyncSummary
{
    public function __construct(
        public int   $checked,
        public int   $updated,
        public int   $unchanged,
        /** @var string[] */
        public array $errors,
    ) {}
}
