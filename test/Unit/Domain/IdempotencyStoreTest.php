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

namespace HyperfTest\Unit\Domain;

use App\Domain\Exception\IdempotencyKeyConflict;
use App\Domain\IdempotencyRecord;
use DateTimeImmutable;
use HyperfTest\Fake\FakeIdempotencyStore;
use PHPUnit\Framework\TestCase;

/**
 * Spec anchors: CONC-05 (store and retrieve a terminal outcome under a key),
 * CONC-06 (same key with a different request hash is a conflict).
 *
 * @internal
 * @coversNothing
 */
class IdempotencyStoreTest extends TestCase
{
    public function testItSavesAndFindsARecordByKey(): void
    {
        $store = new FakeIdempotencyStore();
        $createdAt = new DateTimeImmutable('2026-08-04 12:00:00');
        $record = new IdempotencyRecord(
            'key-1',
            str_repeat('a', 64),
            201,
            ['transfer_id' => 1, 'value' => 100.5],
            $createdAt,
        );

        $store->save($record);

        $found = $store->find('key-1');
        $this->assertInstanceOf(IdempotencyRecord::class, $found);
        $this->assertSame('key-1', $found->key);
        $this->assertSame(str_repeat('a', 64), $found->requestHash);
        $this->assertSame(201, $found->statusCode);
        $this->assertSame(['transfer_id' => 1, 'value' => 100.5], $found->responseBody);
        $this->assertEquals($createdAt, $found->createdAt);
    }

    public function testItReturnsNullWhenTheKeyIsUnknown(): void
    {
        $store = new FakeIdempotencyStore();

        $this->assertNull($store->find('missing-key'));
    }

    public function testItRejectsASecondSaveWithADifferentRequestHash(): void
    {
        $store = new FakeIdempotencyStore();
        $store->save(new IdempotencyRecord(
            'shared-key',
            str_repeat('a', 64),
            201,
            ['ok' => true],
            new DateTimeImmutable('2026-08-04 12:00:00'),
        ));

        $this->expectException(IdempotencyKeyConflict::class);

        $store->save(new IdempotencyRecord(
            'shared-key',
            str_repeat('b', 64),
            201,
            ['ok' => false],
            new DateTimeImmutable('2026-08-04 12:01:00'),
        ));
    }

    public function testItKeepsTheFirstTerminalOutcomeWhenTheSameHashIsSavedAgain(): void
    {
        $store = new FakeIdempotencyStore();
        $first = new IdempotencyRecord(
            'shared-key',
            str_repeat('a', 64),
            201,
            ['transfer_id' => 1],
            new DateTimeImmutable('2026-08-04 12:00:00'),
        );
        $store->save($first);

        $store->save(new IdempotencyRecord(
            'shared-key',
            str_repeat('a', 64),
            422,
            ['error' => 'ignored'],
            new DateTimeImmutable('2026-08-04 12:01:00'),
        ));

        $found = $store->find('shared-key');
        $this->assertSame(201, $found->statusCode);
        $this->assertSame(['transfer_id' => 1], $found->responseBody);
    }
}
