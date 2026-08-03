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

namespace App\Infrastructure\Persistence;

use App\Domain\Port\UserRepository;
use App\Domain\User;
use App\Domain\UserType;
use Hyperf\DbConnection\Db;

final class DbUserRepository implements UserRepository
{
    public function findById(int $id): ?User
    {
        $row = Db::table('users')->where('id', $id)->first();

        if ($row === null) {
            return null;
        }

        return new User(
            (int) $row->id,
            $row->full_name,
            $row->cpf,
            $row->email,
            UserType::from($row->type),
        );
    }
}
