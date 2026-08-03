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

use App\Domain\Port\UserRepository;
use App\Domain\User;

final class FakeUserRepository implements UserRepository
{
    /** @var array<int, User> */
    public array $usersById = [];

    public function add(User $user): void
    {
        $this->usersById[$user->id] = $user;
    }

    public function findById(int $id): ?User
    {
        return $this->usersById[$id] ?? null;
    }
}
