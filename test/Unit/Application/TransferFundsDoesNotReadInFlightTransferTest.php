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

namespace HyperfTest\Unit\Application;

use PHPUnit\Framework\TestCase;

/**
 * SCOPE-05: TransferFunds receives parties only via TransferFundsInput and
 * does not read the in-flight request slot.
 *
 * @internal
 * @coversNothing
 */
final class TransferFundsDoesNotReadInFlightTransferTest extends TestCase
{
    public function testTransferFundsDoesNotImportOrReadTheInFlightSlot(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Application/TransferFunds.php');
        $this->assertIsString($source);
        $this->assertStringNotContainsString('InFlightTransfer', $source);
        $this->assertStringNotContainsString('InFlightTransferParties', $source);
        $this->assertDoesNotMatchRegularExpression('/inFlightTransfer\s*->\s*get\s*\(/', $source);
        $this->assertStringContainsString(
            'public function execute(TransferFundsInput $input): TransferResult',
            $source
        );
        $this->assertStringContainsString('private readonly TransactionRunner $transactionRunner', $source);
        $this->assertStringContainsString('private readonly UserRepository $userRepository', $source);
        $this->assertStringContainsString('private readonly WalletRepository $walletRepository', $source);
        $this->assertStringContainsString('private readonly TransferRepository $transferRepository', $source);
        $this->assertStringContainsString('private readonly TransferAuthorizer $authorizer', $source);
        $this->assertStringContainsString('private readonly Outbox $outbox', $source);
        $this->assertStringContainsString('private readonly IdempotencyStore $idempotencyStore', $source);
        $this->assertStringContainsString('private readonly Ledger $ledger', $source);
    }
}
