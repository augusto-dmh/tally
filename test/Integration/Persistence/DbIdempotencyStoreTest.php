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

namespace HyperfTest\Integration\Persistence;

use App\Domain\Exception\IdempotencyDuplicateKey;
use App\Domain\Exception\IdempotencyKeyConflict;
use App\Domain\IdempotencyRecord;
use App\Infrastructure\Persistence\DbIdempotencyStore;
use DateTimeImmutable;
use Hyperf\DbConnection\Db;
use HyperfTest\Integration\IntegrationTestCase;

/**
 * Spec anchors: CONC-05 (persist and load a terminal outcome under a key),
 * CONC-06 (same key with a different request hash is a conflict),
 * plus the design insert-then-catch unique-key race signal for replay.
 *
 * @internal
 * @coversNothing
 */
final class DbIdempotencyStoreTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Db::table('idempotency_keys')->delete();
    }

    public function testItPersistsAndLoadsATerminalOutcomeByKey(): void
    {
        $store = new DbIdempotencyStore();
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
        $this->assertEquals(['transfer_id' => 1, 'value' => 100.5], $found->responseBody);
        $this->assertSame('2026-08-04 12:00:00', $found->createdAt->format('Y-m-d H:i:s'));
    }

    public function testItReturnsNullWhenTheKeyIsUnknown(): void
    {
        $this->assertNull((new DbIdempotencyStore())->find('missing-key'));
    }

    public function testItRejectsASecondSaveWithADifferentRequestHash(): void
    {
        $store = new DbIdempotencyStore();
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

    public function testADuplicateKeySurfacesAsACatchableRaceSignal(): void
    {
        $store = new DbIdempotencyStore();
        $record = new IdempotencyRecord(
            'race-key',
            str_repeat('c', 64),
            201,
            ['transfer_id' => 9],
            new DateTimeImmutable('2026-08-04 12:00:00'),
        );
        $store->save($record);

        try {
            $store->save($record);
            $this->fail('Expected IdempotencyDuplicateKey for a unique-key collision.');
        } catch (IdempotencyDuplicateKey $signal) {
            $this->assertInstanceOf(IdempotencyDuplicateKey::class, $signal);
        }

        $found = $store->find('race-key');
        $this->assertNotNull($found);
        $this->assertSame(201, $found->statusCode);
        $this->assertEquals(['transfer_id' => 9], $found->responseBody);
    }
}
