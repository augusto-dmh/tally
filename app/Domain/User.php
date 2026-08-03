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

namespace App\Domain;

final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $fullName,
        public readonly string $cpf,
        public readonly string $email,
        public readonly UserType $type,
    ) {
    }

    public function canTransfer(): bool
    {
        return $this->type === UserType::Common;
    }
}
