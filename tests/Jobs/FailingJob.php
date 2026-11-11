<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork\Tests\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Context;
use RuntimeException;

/**
 * 動かすと投げるジョブ。動いている間の Request ID を残す
 */
final class FailingJob implements ShouldQueue
{
    use Queueable;

    public static ?string $seenRequestId = null;

    public function handle(): void
    {
        $id = Context::get('request_id');
        self::$seenRequestId = is_string($id) ? $id : null;

        throw new RuntimeException('probe');
    }
}
