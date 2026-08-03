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

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Hyperf\Guzzle\ClientFactory;

/**
 * Builds clients that answer from a MockHandler instead of the network, and
 * keeps the options the adapter asked for so a test can inspect them.
 */
final class RecordingClientFactory extends ClientFactory
{
    /** @var array<string, mixed> */
    public array $options = [];

    public function __construct(private readonly MockHandler $handler)
    {
    }

    public function create(array $options = []): Client
    {
        $this->options = $options;

        return new Client(array_replace($options, ['handler' => HandlerStack::create($this->handler)]));
    }
}
