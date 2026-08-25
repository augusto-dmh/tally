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

namespace HyperfTest\Fake;

use App\Domain\InFlightTransferParties;
use App\Domain\Port\InFlightTransfer;

final class RecordingInFlightTransfer implements InFlightTransfer
{
    private ?InFlightTransferParties $current = null;

    /** @var list<InFlightTransferParties> */
    private array $sets = [];

    public function set(int $payerId, int $payeeId): void
    {
        $this->current = new InFlightTransferParties($payerId, $payeeId);
        $this->sets[] = $this->current;
    }

    public function get(): ?InFlightTransferParties
    {
        return $this->current;
    }

    public function reset(): void
    {
        $this->current = null;
        $this->sets = [];
    }

    /**
     * @return list<InFlightTransferParties>
     */
    public function recordedSets(): array
    {
        return $this->sets;
    }
}
