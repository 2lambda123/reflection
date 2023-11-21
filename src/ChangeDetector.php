<?php

declare(strict_types=1);

namespace Typhoon\Reflection;

/**
 * @api
 */
interface ChangeDetector
{
    public function changed(): bool;
}
