<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork\Tests;

use Ad5\RichPhpChatwork\Reporter;
use Ad5\RichPhpChatwork\Tests\Jobs\FailingJob;
use Ad5\RichPhpChatwork\Tests\Jobs\OuterJob;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\SyncQueue;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
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
    public function ワーカーで失敗したら_通知にジョブの行があり_ログと同じRequestIDを出す(): void
    {
        Context::add('request_id', 'req-dispatch');
        $job = $this->workerJob(new FailingJob());
        Context::flush();
        $logged = $this->requestIdWhenReported();

        $this->runOnWorker($job);

        $body = $this->onlyBody();
        $this->assertStringContainsString("ジョブ: FailingJob（試行 1 回目）\n", $body);
        $this->assertStringContainsString("発端の Request ID: req-dispatch\n", $body);
        $this->assertNotSame('req-dispatch', FailingJob::$seenRequestId, 'ジョブの中では新しい Request ID');
        $this->assertSame([FailingJob::$seenRequestId], $logged->ids, 'Laravel が例外を記録するときの Context の Request ID');
        $this->assertStringContainsString('Request ID: ' . FailingJob::$seenRequestId . '（中身はログをこの番号で引く）', $body);

        Event::dispatch(new Looping('redis', 'default'));
        $this->assertSame('req-dispatch', Context::get('request_id'), 'ワーカーが次のジョブへ回るときに戻す');
    }

    #[Test]
    public function 出した側のIDが無いジョブでも_ログと通知のRequestIDが一致する(): void
    {
        $job = $this->workerJob(new FailingJob());
        $logged = $this->requestIdWhenReported();

        $this->runOnWorker($job);

        $body = $this->onlyBody();
        $this->assertNotNull(FailingJob::$seenRequestId);
        $this->assertSame([FailingJob::$seenRequestId], $logged->ids);
        $this->assertStringContainsString('Request ID: ' . FailingJob::$seenRequestId . '（中身はログをこの番号で引く）', $body);
        $this->assertStringNotContainsString('発端の Request ID', $body);

        Event::dispatch(new Looping('redis', 'default'));
        $this->assertFalse(Context::has('request_id'), '元に無かったキーは消す');
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

    private function runOnWorker(WorkerJob $job): void
    {
        $worker = $this->app?->make('queue.worker');
        $this->assertInstanceOf(Worker::class, $worker);
        // 本物のワーカーが例外を受けて report() するところ（runJob）を通す
        (function (WorkerJob $job): void {
            $this->runJob($job, 'redis', new WorkerOptions(maxTries: 1));
        })->call($worker, $job);
    }

    /**
     * Laravel が例外を記録する時点（ContextLogProcessor が Context を付ける時点）の Request ID を残す
     */
    private function requestIdWhenReported(): RequestIds
    {
        $ids = new RequestIds();
        $handler = $this->app?->make(ExceptionHandler::class);
        $this->assertInstanceOf(Handler::class, $handler);
        $handler->reportable(static function (\Throwable $e) use ($ids): void {
            $id = Context::get('request_id');
            $ids->ids[] = is_string($id) ? $id : null;
        });

        return $ids;
    }

    private function onlyBody(): string
    {
        $this->assertCount(1, $this->transport->sent);

        return $this->transport->sent[0]['body'];
    }

    /**
     * キューに積んだときと同じ形のジョブ（payload は sync のキューで作る。作り方は同じ）
     */
    private function workerJob(object $command): WorkerJob
    {
        $queue = $this->app?->make('queue')->connection('sync');
        $this->assertInstanceOf(SyncQueue::class, $queue);
        $payload = (fn (object $command): string => $this->createPayload($command, 'default', ''))->call($queue, $command);
        $app = $this->app;
        $this->assertInstanceOf(Container::class, $app);

        return new WorkerJob($app, $payload);
    }
}
