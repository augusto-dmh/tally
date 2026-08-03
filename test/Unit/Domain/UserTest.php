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

use App\Domain\User;
use App\Domain\UserType;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class UserTest extends TestCase
{
    public function testACommonUserCanTransfer(): void
    {
        $user = new User(1, 'Ada Lovelace', '39053344705', 'ada@example.com', UserType::Common);

        $this->assertTrue($user->canTransfer());
    }

    public function testAMerchantCannotTransfer(): void
    {
        $merchant = new User(2, 'Padaria Central', '12345678909', 'padaria@example.com', UserType::Merchant);

        $this->assertFalse($merchant->canTransfer());
    }
}
