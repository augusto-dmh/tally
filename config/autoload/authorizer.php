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

use function Hyperf\Support\env;

return [
    'defaults' => [
        'timeout' => (float) env('AUTHORIZER_TIMEOUT', 5.0),
        'connect_timeout' => (float) env('AUTHORIZER_CONNECT_TIMEOUT', 2.0),
        'max_attempts' => 3,
        'backoff_base_ms' => 50,
        'backoff_cap_ms' => 500,
        'fail_counter' => 5,
        'success_counter' => 1,
        'duration' => 10.0,
    ],
    'providers' => [
        [
            'name' => 'primary',
            'base_uri' => env('AUTHORIZER_URL', 'https://util.devi.tools'),
            'breaker_name' => 'transfer.authorizer.primary',
        ],
        [
            'name' => 'fallback',
            'base_uri' => env('AUTHORIZER_FALLBACK_URL', ''),
            'breaker_name' => 'transfer.authorizer.fallback',
        ],
    ],
];
