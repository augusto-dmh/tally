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

/**
 * Selects authorizer provider configs that have a usable base URI, in list order.
 */
final class AuthorizerProviders
{
    /**
     * @param list<array<string, mixed>> $providers
     * @return list<array<string, mixed>>
     */
    public static function usable(array $providers): array
    {
        $usable = [];
        foreach ($providers as $provider) {
            if ((string) ($provider['base_uri'] ?? '') === '') {
                continue;
            }
            $usable[] = $provider;
        }

        return $usable;
    }
}
