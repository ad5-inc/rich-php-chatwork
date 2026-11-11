<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork\Tests;

use Illuminate\Queue\Jobs\Job;

/**
 * キューのジョブの作り物。失敗のイベントを直接出して、入口の印と Request ID の退避を見る
 */
final class FakeJob extends Job
{
    private readonly string $uuid;

    public function __construct(private readonly string $displayName, private readonly int $attempts = 1)
    {
        $this->uuid = bin2hex(random_bytes(8));
    }

    public function getJobId(): string
    {
        return $this->uuid;
    }

    public function getRawBody(): string
    {
        return (string) json_encode(['uuid' => $this->uuid, 'displayName' => $this->displayName, 'job' => 'Illuminate\Queue\CallQueuedHandler@call']);
    }

    public function attempts(): int
    {
        return $this->attempts;
    }
}
