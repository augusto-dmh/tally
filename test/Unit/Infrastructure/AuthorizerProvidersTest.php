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

namespace HyperfTest\Unit\Infrastructure;

use App\Infrastructure\Http\AuthorizerProviders;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
final class AuthorizerProvidersTest extends TestCase
{
    public function testUsableOmitsAnEmptyFallbackAndKeepsThePrimaryUri(): void
    {
        $usable = AuthorizerProviders::usable([
            ['name' => 'primary', 'base_uri' => 'https://primary.example'],
            ['name' => 'fallback', 'base_uri' => ''],
        ]);

        $this->assertSame(
            [['name' => 'primary', 'base_uri' => 'https://primary.example']],
            $usable
        );
    }

    public function testUsableOmitsAnEntryWithNoBaseUri(): void
    {
        $usable = AuthorizerProviders::usable([
            ['name' => 'primary', 'base_uri' => 'https://primary.example'],
            ['name' => 'fallback'],
        ]);

        $this->assertSame(
            [['name' => 'primary', 'base_uri' => 'https://primary.example']],
            $usable
        );
    }

    public function testUsableReturnsAnEmptyListWhenEveryUriIsEmpty(): void
    {
        $usable = AuthorizerProviders::usable([
            ['name' => 'primary', 'base_uri' => ''],
            ['name' => 'fallback'],
        ]);

        $this->assertSame([], $usable);
    }

    public function testUsableKeepsRemainingEntriesInConfigOrder(): void
    {
        $usable = AuthorizerProviders::usable([
            ['name' => 'a', 'base_uri' => 'https://a.example'],
            ['name' => 'b', 'base_uri' => ''],
            ['name' => 'c', 'base_uri' => 'https://c.example'],
            ['name' => 'd', 'base_uri' => 'https://d.example'],
        ]);

        $this->assertSame(
            [
                ['name' => 'a', 'base_uri' => 'https://a.example'],
                ['name' => 'c', 'base_uri' => 'https://c.example'],
                ['name' => 'd', 'base_uri' => 'https://d.example'],
            ],
            $usable
        );
    }
}
