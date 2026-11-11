<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork\Tests;

use Ad5\RichPhpChatwork\Reporter;
use Ad5\RichPhpChatwork\Tests\Jobs\FailingJob;
use Ad5\RichPhpChatwork\Tests\Jobs\OuterJob;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\SyncQueue;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * ジョブの入口。Laravel は失敗のイベントを出した後で report() を呼ぶので、イベントの時点で入口を例外に結び付けておく
 */
final class JobEntranceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->useEnvironment('production');
        config(['app.name' => 'Probe', 'rich-chatwork.access_token' => 'probe-token', 'rich-chatwork.room_id' => '12345', 'queue.default' => 'sync']);
        Context::flush();
        Reporter::resolveUserUsing(null);
        FailingJob::$seenRequestId = null;
        OuterJob::$seenRequestId = null;
    }

    #[Test]
    public function ワーカーで失敗したら_通知にジョブの行がある(): void
    {
        Context::add('request_id', 'req-dispatch');
        $job = $this->syncJob(new FailingJob());
        Context::flush();

        $worker = $this->app?->make('queue.worker');
        $this->assertInstanceOf(Worker::class, $worker);
        // 本物のワーカーが例外を受けて report() するところ（runJob）を通す
        (function (SyncJob $job): void {
            $this->runJob($job, 'sync', new WorkerOptions(maxTries: 1));
        })->call($worker, $job);

        $body = $this->onlyBody();
        $this->assertStringContainsString("ジョブ: FailingJob（試行 1 回目）\n", $body);
        $this->assertStringContainsString('Request ID: ' . FailingJob::$seenRequestId . '（中身はログをこの番号で引く）', $body);
        $this->assertStringContainsString("発端の Request ID: req-dispatch\n", $body);
        $this->assertNotSame('req-dispatch', FailingJob::$seenRequestId, 'ジョブの中では新しい Request ID');
    }

    #[Test]
    public function Contextを戻すLaravelの処理より後で_ジョブのRequestIDを入れる(): void
    {
        Context::add('request_id', 'req-dispatch');

        try {
            Queue::connection('sync')->push(new FailingJob());
        } catch (RuntimeException) {
            // 投げられるのが目的
        }

        $this->assertNotNull(FailingJob::$seenRequestId);
        $this->assertNotSame('req-dispatch', FailingJob::$seenRequestId, 'ContextServiceProvider が後で動くと、ディスパッチした側の ID に戻ってしまう');
        $this->assertSame('req-dispatch', Context::get('request_id'), 'ジョブが終われば元に戻す');
    }

    #[Test]
    public function syncで失敗してHTTPまで投げられても_ジョブの行で知らせる(): void
    {
        $this->runningOverHttp();
        Route::get('/probe/sync', static function (): string {
            Context::add('request_id', 'req-http');
            Queue::connection('sync')->push(new FailingJob());

            return 'ok';
        });

        $this->get('/probe/sync')->assertStatus(500);

        $body = $this->onlyBody();
        $this->assertStringContainsString("ジョブ: FailingJob（試行 1 回目）\n", $body);
        $this->assertStringContainsString("発端の Request ID: req-http\n", $body);
        $this->assertDoesNotMatchRegularExpression('/^リクエスト: /m', $body);
        $this->assertSame('req-http', Context::get('request_id'));
    }

    #[Test]
    public function syncの入れ子では_内側のジョブで知らせ_1段ずつ戻す(): void
    {
        Context::add('request_id', 'req-top');

        try {
            Queue::connection('sync')->push(new OuterJob());
        } catch (RuntimeException $e) {
            $this->app?->make(Reporter::class)->report($e);
        }

        $body = $this->onlyBody();
        $this->assertStringContainsString("ジョブ: FailingJob（試行 1 回目）\n", $body);
        $this->assertStringContainsString('Request ID: ' . FailingJob::$seenRequestId . '（', $body);
        $this->assertStringContainsString('発端の Request ID: ' . OuterJob::$seenRequestId . "\n", $body);
        $this->assertNotSame(OuterJob::$seenRequestId, FailingJob::$seenRequestId);
        $this->assertSame('req-top', Context::get('request_id'), '外側のジョブの分も戻り、2 回戻らない');
    }

    private function onlyBody(): string
    {
        $this->assertCount(1, $this->transport->sent);

        return $this->transport->sent[0]['body'];
    }

    /**
     * sync のキューが作るのと同じ形のジョブ
     */
    private function syncJob(object $command): SyncJob
    {
        $queue = $this->app?->make('queue')->connection('sync');
        $this->assertInstanceOf(SyncQueue::class, $queue);
        $payload = (fn (object $command): string => $this->createPayload($command, 'default', ''))->call($queue, $command);

        return new SyncJob($this->app ?? $this->fail('アプリが無い'), $payload, 'sync', 'default');
    }
}
