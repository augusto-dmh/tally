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

namespace App\Domain\Exception;

use RuntimeException;

/**
 * Catchable signal that an idempotency key insert lost a unique-key race.
 * The use case re-reads the winner's stored outcome and replays it.
 */
final class IdempotencyDuplicateKey extends RuntimeException
{
}
