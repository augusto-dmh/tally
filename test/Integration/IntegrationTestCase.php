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

namespace HyperfTest\Integration;

use Hyperf\DbConnection\Db;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests share one MySQL schema, so each starts from an empty one
 * and inserts only the rows it talks about. Rows go child-first because the
 * foreign keys forbid the reverse order.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Db::table('transfers')->delete();
        Db::table('wallets')->delete();
        Db::table('users')->delete();
    }

    protected function insertUser(string $cpf, string $type = 'common', string $fullName = 'Alice Ramos', string $email = 'alice@tally.test'): int
    {
        return (int) Db::table('users')->insertGetId([
            'full_name' => $fullName,
            'cpf' => $cpf,
            'email' => $email,
            'password_hash' => 'not-a-real-hash',
            'type' => $type,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    protected function insertWallet(int $userId, int $balanceCents): int
    {
        return (int) Db::table('wallets')->insertGetId([
            'user_id' => $userId,
            'balance_cents' => $balanceCents,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    protected function balanceOf(int $walletId): int
    {
        return (int) Db::table('wallets')->where('id', $walletId)->value('balance_cents');
    }
}
