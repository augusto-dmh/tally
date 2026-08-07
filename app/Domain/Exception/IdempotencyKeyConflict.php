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

/**
 * Raised when an Idempotency-Key is reused with a different request body.
 * HTTP mapping (422 / idempotency_key_conflict) lands in the handler later.
 */
final class IdempotencyKeyConflict extends DomainException
{
}
