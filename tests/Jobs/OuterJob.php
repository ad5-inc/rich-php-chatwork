<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork\Tests\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;

/**
 * 中で別のジョブを sync で動かすジョブ（sync の入れ子）
 */
final class OuterJob implements ShouldQueue
{
    use Queueable;

    public static ?string $seenRequestId = null;

    public function handle(): void
    {
        $id = Context::get('request_id');
        self::$seenRequestId = is_string($id) ? $id : null;

        Queue::connection('sync')->push(new FailingJob());
    }
}
