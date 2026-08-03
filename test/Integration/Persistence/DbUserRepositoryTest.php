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

use App\Domain\UserType;
use App\Infrastructure\Persistence\DbUserRepository;
use HyperfTest\Integration\IntegrationTestCase;

/**
 * @internal
 * @coversNothing
 */
final class DbUserRepositoryTest extends IntegrationTestCase
{
    public function testFindsAStoredUserWithEveryFieldHydrated(): void
    {
        $id = $this->insertUser('11111111111', 'common', 'Alice Ramos', 'alice@tally.test');

        $user = (new DbUserRepository())->findById($id);

        $this->assertNotNull($user);
        $this->assertSame($id, $user->id);
        $this->assertSame('Alice Ramos', $user->fullName);
        $this->assertSame('11111111111', $user->cpf);
        $this->assertSame('alice@tally.test', $user->email);
        $this->assertSame(UserType::Common, $user->type);
    }

    public function testHydratesTheMerchantTypeFromTheColumn(): void
    {
        $id = $this->insertUser('33333333333', 'merchant', 'Mercado Central', 'mercado@tally.test');

        $user = (new DbUserRepository())->findById($id);

        $this->assertNotNull($user);
        $this->assertSame(UserType::Merchant, $user->type);
        $this->assertFalse($user->canTransfer());
    }

    public function testReturnsNullWhenNoUserCarriesTheId(): void
    {
        $id = $this->insertUser('11111111111');

        $this->assertNull((new DbUserRepository())->findById($id + 1));
    }
}
