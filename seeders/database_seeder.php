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
use App\Infrastructure\Persistence\OpeningLedgerBackfill;
use Hyperf\Database\Seeders\Seeder;
use Hyperf\DbConnection\Db;

class DatabaseSeeder extends Seeder
{
    /**
     * The demo accounts every environment starts from: two common users who can
     * send, and one merchant who can only receive.
     */
    private const ACCOUNTS = [
        ['full_name' => 'Alice Ramos', 'cpf' => '11111111111', 'email' => 'alice@tally.test', 'type' => 'common', 'balance_cents' => 100000],
        ['full_name' => 'Bruno Teixeira', 'cpf' => '22222222222', 'email' => 'bruno@tally.test', 'type' => 'common', 'balance_cents' => 50000],
        ['full_name' => 'Mercado Central', 'cpf' => '33333333333', 'email' => 'mercado@tally.test', 'type' => 'merchant', 'balance_cents' => 0],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        foreach (self::ACCOUNTS as $account) {
            Db::table('users')->updateOrInsert(
                ['cpf' => $account['cpf']],
                [
                    'full_name' => $account['full_name'],
                    'email' => $account['email'],
                    'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
                    'type' => $account['type'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            $userId = Db::table('users')->where('cpf', $account['cpf'])->value('id');

            Db::table('wallets')->updateOrInsert(
                ['user_id' => $userId],
                [
                    'balance_cents' => $account['balance_cents'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        (new OpeningLedgerBackfill())->run();
    }
}
