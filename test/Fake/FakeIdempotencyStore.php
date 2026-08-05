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

namespace HyperfTest\Fake;

use App\Domain\Exception\IdempotencyKeyConflict;
use App\Domain\IdempotencyRecord;
use App\Domain\Port\IdempotencyStore;

final class FakeIdempotencyStore implements IdempotencyStore
{
    /** @var array<string, IdempotencyRecord> */
    private array $records = [];

    public function find(string $key): ?IdempotencyRecord
    {
        return $this->records[$key] ?? null;
    }

    public function save(IdempotencyRecord $record): void
    {
        $existing = $this->records[$record->key] ?? null;

        if ($existing !== null && $existing->requestHash !== $record->requestHash) {
            throw new IdempotencyKeyConflict(
                'Idempotency-Key was already used with a different request body.'
            );
        }

        if ($existing !== null) {
            return;
        }

        $this->records[$record->key] = $record;
    }
}
