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

use App\Domain\InFlightTransferParties;
use App\Domain\Port\InFlightTransfer;
use App\Infrastructure\Http\ContextInFlightTransfer;
use Hyperf\Coroutine\Parallel;
use Hyperf\Engine\Channel;
use HyperfTest\Fake\PropertyInFlightTransfer;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

/**
 * Spec anchors: SCOPE-01 (Context isolates distinct pairs across an await),
 * SCOPE-02 (the same assertions fail on a property store), SCOPE-06 (unset
 * get is null on both), SCOPE-07 (a later coroutine sees empty Context).
 *
 * @internal
 * @coversNothing
 */
final class InFlightTransferIsolationTest extends TestCase
{
    private const PAYER_A = 1;

    private const PAYEE_A = 2;

    private const PAYER_B = 3;

    private const PAYEE_B = 4;

    public function testContextStoreKeepsEachCoroutinesOwnPairAfterRendezvousAndYield(): void
    {
        $results = $this->setDistinctPairsThenGetAfterRendezvous(new ContextInFlightTransfer());

        $this->assertEachCoroutineKeepsItsOwnPair($results['a'], $results['b']);
    }

    public function testPropertyStoreFailsTheSameIsolationAssertions(): void
    {
        $results = $this->setDistinctPairsThenGetAfterRendezvous(new PropertyInFlightTransfer());

        $isolationFailed = false;
        try {
            $this->assertEachCoroutineKeepsItsOwnPair($results['a'], $results['b']);
        } catch (ExpectationFailedException) {
            $isolationFailed = true;
        }

        $this->assertTrue(
            $isolationFailed,
            'Property store unexpectedly satisfied the isolation assertions (SCOPE-02).'
        );
    }

    public function testGetWithoutSetReturnsNullOnBothStores(): void
    {
        $this->assertNull((new ContextInFlightTransfer())->get());
        $this->assertNull((new PropertyInFlightTransfer())->get());
    }

    public function testContextGetAfterWriterCoroutinesEndReturnsNull(): void
    {
        $store = new ContextInFlightTransfer();
        $this->setDistinctPairsThenGetAfterRendezvous($store);

        $later = new Parallel(1);
        $later->add(static fn (): ?InFlightTransferParties => $store->get());
        $results = $later->wait();

        $this->assertNull($results[0]);
    }

    public function testContextGetIsEmptyOnACoroutineThatNeverSetWhileAnotherIsInFlight(): void
    {
        $store = new ContextInFlightTransfer();
        $aSet = new Channel(1);
        $bGot = new Channel(1);

        $parallel = new Parallel(2);
        $parallel->add(function () use ($store, $aSet, $bGot): ?InFlightTransferParties {
            $store->set(self::PAYER_A, self::PAYEE_A);
            $aSet->push(true);
            $bGot->pop();

            return $store->get();
        }, 'a');
        $parallel->add(function () use ($store, $aSet, $bGot): ?InFlightTransferParties {
            $aSet->pop();
            $got = $store->get();
            $bGot->push(true);

            return $got;
        }, 'b');
        $results = $parallel->wait();

        $this->assertInstanceOf(InFlightTransferParties::class, $results['a']);
        $this->assertSame(self::PAYER_A, $results['a']->payerId);
        $this->assertSame(self::PAYEE_A, $results['a']->payeeId);
        $this->assertNull($results['b']);
    }

    public function testPropertyStoreIsNotBoundInProductionOrTestDependencies(): void
    {
        $production = file_get_contents(BASE_PATH . '/config/autoload/dependencies.php');
        $testing = file_get_contents(BASE_PATH . '/test/dependencies.php');

        $this->assertIsString($production);
        $this->assertIsString($testing);
        $this->assertStringNotContainsString('PropertyInFlightTransfer', $production);
        $this->assertStringNotContainsString('PropertyInFlightTransfer', $testing);
    }

    /**
     * Both children set before either gets, then yield, then get. Results are
     * returned to the parent — assertions inside Parallel children are unreliable.
     *
     * @return array{a: null|InFlightTransferParties, b: null|InFlightTransferParties}
     */
    private function setDistinctPairsThenGetAfterRendezvous(InFlightTransfer $store): array
    {
        $aSet = new Channel(1);
        $bSet = new Channel(1);

        $parallel = new Parallel(2);
        $parallel->add(function () use ($store, $aSet, $bSet): ?InFlightTransferParties {
            $store->set(self::PAYER_A, self::PAYEE_A);
            $aSet->push(true);
            $bSet->pop();
            Coroutine::sleep(0.01);

            return $store->get();
        }, 'a');
        $parallel->add(function () use ($store, $aSet, $bSet): ?InFlightTransferParties {
            $store->set(self::PAYER_B, self::PAYEE_B);
            $bSet->push(true);
            $aSet->pop();
            Coroutine::sleep(0.01);

            return $store->get();
        }, 'b');

        /** @var array{a: null|InFlightTransferParties, b: null|InFlightTransferParties} $results */
        $results = $parallel->wait();

        return $results;
    }

    private function assertEachCoroutineKeepsItsOwnPair(
        mixed $fromA,
        mixed $fromB,
    ): void {
        $this->assertInstanceOf(InFlightTransferParties::class, $fromA);
        $this->assertInstanceOf(InFlightTransferParties::class, $fromB);
        $this->assertSame(self::PAYER_A, $fromA->payerId);
        $this->assertSame(self::PAYEE_A, $fromA->payeeId);
        $this->assertSame(self::PAYER_B, $fromB->payerId);
        $this->assertSame(self::PAYEE_B, $fromB->payeeId);
    }
}
