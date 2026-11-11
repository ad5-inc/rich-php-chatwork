<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork\Tests;

use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Support\Facades\Context;

/**
 * キューのジョブの作り物。失敗のイベントを直接出して、入口の印と Request ID の退避を見る
 *
 * キューに積むときと同じく、作った時点の Context を payload に持つ（Laravel が JobProcessing で戻す）
 */
final class FakeJob extends Job implements JobContract
{
    private readonly string $uuid;

    private readonly mixed $context;

    public function __construct(private readonly string $displayName, private readonly int $attempts = 1)
    {
        $this->uuid = bin2hex(random_bytes(8));
        $this->context = Context::dehydrate();
    }

    public function getJobId(): string
    {
        return $this->uuid;
    }

    public function getRawBody(): string
    {
        return (string) json_encode(['uuid' => $this->uuid, 'displayName' => $this->displayName, 'job' => 'Illuminate\Queue\CallQueuedHandler@call', 'illuminate:log:context' => $this->context]);
    }

    public function attempts(): int
    {
        return $this->attempts;
    }
}
