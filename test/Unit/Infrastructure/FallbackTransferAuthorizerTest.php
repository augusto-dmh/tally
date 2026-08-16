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

use App\Domain\Money;
use App\Domain\Transfer;
use App\Infrastructure\Http\AuthorizerAttempt;
use App\Infrastructure\Http\AuthorizerUnavailable;
use App\Infrastructure\Http\FallbackTransferAuthorizer;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
final class FallbackTransferAuthorizerTest extends TestCase
{
    public function testAuthorizeReturnsTrueWhenTheFirstLeafClearsWithoutCallingLaterLeaves(): void
    {
        $primary = new FakeAuthorizerAttempt(true);
        $secondary = new FakeAuthorizerAttempt(true);

        $cleared = (new FallbackTransferAuthorizer([$primary, $secondary]))->authorize($this->transfer());

        $this->assertTrue($cleared);
        $this->assertSame(1, $primary->calls);
        $this->assertSame(0, $secondary->calls);
    }

    public function testAuthorizeReturnsFalseWhenTheFirstLeafDeclinesWithoutCallingLaterLeaves(): void
    {
        $primary = new FakeAuthorizerAttempt(false);
        $secondary = new FakeAuthorizerAttempt(true);

        $cleared = (new FallbackTransferAuthorizer([$primary, $secondary]))->authorize($this->transfer());

        $this->assertFalse($cleared);
        $this->assertSame(1, $primary->calls);
        $this->assertSame(0, $secondary->calls);
    }

    public function testAuthorizeReturnsTrueWhenTheFirstLeafIsUnavailableAndALaterLeafClears(): void
    {
        $primary = new FakeAuthorizerAttempt(new AuthorizerUnavailable());
        $secondary = new FakeAuthorizerAttempt(true);

        $cleared = (new FallbackTransferAuthorizer([$primary, $secondary]))->authorize($this->transfer());

        $this->assertTrue($cleared);
        $this->assertSame(1, $primary->calls);
        $this->assertSame(1, $secondary->calls);
    }

    public function testAuthorizeReturnsFalseWhenTheNextLeafDeclinesAfterAnUnavailableLeaf(): void
    {
        $primary = new FakeAuthorizerAttempt(new AuthorizerUnavailable());
        $secondary = new FakeAuthorizerAttempt(false);
        $tertiary = new FakeAuthorizerAttempt(true);

        $cleared = (new FallbackTransferAuthorizer([$primary, $secondary, $tertiary]))->authorize($this->transfer());

        $this->assertFalse($cleared);
        $this->assertSame(1, $primary->calls);
        $this->assertSame(1, $secondary->calls);
        $this->assertSame(0, $tertiary->calls);
    }

    public function testAuthorizeReturnsFalseWhenEveryLeafIsUnavailable(): void
    {
        $primary = new FakeAuthorizerAttempt(new AuthorizerUnavailable());
        $secondary = new FakeAuthorizerAttempt(new AuthorizerUnavailable());

        $cleared = (new FallbackTransferAuthorizer([$primary, $secondary]))->authorize($this->transfer());

        $this->assertFalse($cleared);
        $this->assertSame(1, $primary->calls);
        $this->assertSame(1, $secondary->calls);
    }

    public function testAuthorizeReturnsTrueWhenTheOnlyLeafClears(): void
    {
        $leaf = new FakeAuthorizerAttempt(true);

        $cleared = (new FallbackTransferAuthorizer([$leaf]))->authorize($this->transfer());

        $this->assertTrue($cleared);
        $this->assertSame(1, $leaf->calls);
    }

    public function testAuthorizeReturnsFalseWhenTheOnlyLeafDeclines(): void
    {
        $leaf = new FakeAuthorizerAttempt(false);

        $cleared = (new FallbackTransferAuthorizer([$leaf]))->authorize($this->transfer());

        $this->assertFalse($cleared);
        $this->assertSame(1, $leaf->calls);
    }

    public function testAuthorizeReturnsFalseWhenTheOnlyLeafIsUnavailable(): void
    {
        $leaf = new FakeAuthorizerAttempt(new AuthorizerUnavailable());

        $cleared = (new FallbackTransferAuthorizer([$leaf]))->authorize($this->transfer());

        $this->assertFalse($cleared);
        $this->assertSame(1, $leaf->calls);
    }

    public function testAuthorizeReturnsFalseWhenTheLeafListIsEmpty(): void
    {
        $cleared = (new FallbackTransferAuthorizer([]))->authorize($this->transfer());

        $this->assertFalse($cleared);
    }

    public function testSwappingLeafOrderChangesWhoIsTriedFirst(): void
    {
        $listedFirst = new FakeAuthorizerAttempt(true);
        $listedSecond = new FakeAuthorizerAttempt(true);

        $asListed = (new FallbackTransferAuthorizer([$listedFirst, $listedSecond]))->authorize($this->transfer());

        $this->assertTrue($asListed);
        $this->assertSame(1, $listedFirst->calls);
        $this->assertSame(0, $listedSecond->calls);

        $swappedFirst = new FakeAuthorizerAttempt(true);
        $swappedSecond = new FakeAuthorizerAttempt(true);

        $swapped = (new FallbackTransferAuthorizer([$swappedSecond, $swappedFirst]))->authorize($this->transfer());

        $this->assertTrue($swapped);
        $this->assertSame(1, $swappedSecond->calls);
        $this->assertSame(0, $swappedFirst->calls);
    }

    private function transfer(): Transfer
    {
        return new Transfer(null, 1, 2, Money::fromCents(10050), new DateTimeImmutable('2026-01-02 03:04:05'));
    }
}

final class FakeAuthorizerAttempt implements AuthorizerAttempt
{
    public int $calls = 0;

    public function __construct(
        private readonly bool|AuthorizerUnavailable $outcome,
    ) {
    }

    public function attempt(Transfer $transfer): bool
    {
        ++$this->calls;

        if ($this->outcome instanceof AuthorizerUnavailable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }
}
