<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace App\Domain\Port;

use App\Domain\IdempotencyRecord;

interface IdempotencyStore
{
    public function find(string $key): ?IdempotencyRecord;

    /**
     * Insert a terminal outcome. Duplicate-key races surface as an
     * infrastructure signal the use case maps to re-read and replay.
     */
    public function save(IdempotencyRecord $record): void;
}
