<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork\Tests;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;

/**
 * ワーカーが動かすキューのジョブ（sync ではない物）。payload はキューに積んだときと同じ形
 */
final class WorkerJob extends Job implements JobContract
{
    public function __construct(Container $container, private readonly string $payload)
    {
        $this->container = $container;
        $this->connectionName = 'redis';
        $this->queue = 'default';
    }

    public function getJobId(): string
    {
        return 'worker-job';
    }

    public function getRawBody(): string
    {
        return $this->payload;
    }

    public function attempts(): int
    {
        return 1;
    }
}
