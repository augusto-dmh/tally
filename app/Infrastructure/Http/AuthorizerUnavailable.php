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

namespace App\Infrastructure\Http;

use RuntimeException;

/**
 * The authorizer could not be reached or did not return a usable answer.
 * Distinct from an explicit decline (authorized=false).
 */
final class AuthorizerUnavailable extends RuntimeException
{
}
