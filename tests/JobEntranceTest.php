<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork\Tests;

use Ad5\RichPhpChatwork\JobEntrance;
use Ad5\RichPhpChatwork\Reporter;
use Ad5\RichPhpChatwork\Tests\Jobs\FailingJob;
use Ad5\RichPhpChatwork\Tests\Jobs\OuterJob;
use Illuminate\Config\Repository as ConfigRepository;
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
        $this->assertStringContainsString('Request ID: ' . FailingJob::$seenRequestId . "（ジョブの中のログ）\n", $body);
        $this->assertStringContainsString("発端の Request ID: req-http（例外の記録はこの番号）\n", $body);
        $this->assertDoesNotMatchRegularExpression('/^リクエスト: /m', $body);
        $this->assertSame('req-http', Context::get('request_id'));
    }

    #[Test]
    public function syncで失敗して呼び出し側で記録されたら_記録の番号を知らせに書く(): void
    {
        Context::add('request_id', 'req-caller');
        $logged = $this->requestIdWhenReported();

        try {
            Queue::connection('sync')->push(new FailingJob());
        } catch (RuntimeException $e) {
            report($e);
        }

        $body = $this->onlyBody();
        $this->assertSame(['req-caller'], $logged->ids);
        $this->assertStringContainsString("発端の Request ID: req-caller（例外の記録はこの番号）\n", $body);
    }

    #[Test]
    public function 出した側にIDが無いsyncで失敗したら_ジョブのIDを残し_記録と知らせの番号が揃う(): void
    {
        $logged = $this->requestIdWhenReported();

        try {
            Queue::connection('sync')->push(new FailingJob());
        } catch (RuntimeException $e) {
            report($e);
        }

        $body = $this->onlyBody();
        $this->assertSame([FailingJob::$seenRequestId], $logged->ids);
        $this->assertStringContainsString('Request ID: ' . FailingJob::$seenRequestId . "（中身はログをこの番号で引く）\n", $body);
        $this->assertStringNotContainsString('発端の Request ID', $body);
        $this->assertSame(FailingJob::$seenRequestId, Context::get('request_id'), '呼び出し側の残りのログもジョブの番号で引ける');
    }

    #[Test]
    public function 出した側にIDが無いsyncの失敗が続いても_前のジョブのIDを発端にしない(): void
    {
        foreach ([1, 2] as $round) {
            try {
                Queue::connection('sync')->push(new FailingJob());
            } catch (RuntimeException $e) {
                report($e);
            }
            $ids[$round] = FailingJob::$seenRequestId;
            // 同じ例外は間引かれるので、間を置く
            $this->travel(61)->seconds();
        }

        $this->assertCount(2, $this->transport->sent);
        $this->assertNotSame($ids[1], $ids[2]);
        $this->assertStringNotContainsString('発端の Request ID', $this->transport->sent[1]['body'], '1 通目のジョブの ID を発端にしない');

        Event::dispatch(new \Illuminate\Console\Events\CommandFinished('probe', new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\NullOutput(), 0));
        $this->assertFalse(Context::has('request_id'), '入口の終わりで、残したジョブの ID を戻す');
    }

    #[Test]
    public function 残したsyncの失敗を受け止めた後の別の例外は_コマンドの入口で出す(): void
    {
        Context::add('command', 'report:daily');
        try {
            Queue::connection('sync')->push(new FailingJob());
        } catch (RuntimeException) {
            // 受け止めて続ける
        }

        report(new \LogicException('probe'));

        $body = $this->onlyBody();
        $this->assertStringContainsString("コマンド: report:daily\n", $body);
        $this->assertStringNotContainsString('ジョブ: ', $body);
    }

    #[Test]
    public function 残したsyncの失敗を受け止めた後の別の例外は_HTTPの入口で出す(): void
    {
        $this->runningOverHttp();
        Route::get('/probe/after-sync', static function (): string {
            try {
                Queue::connection('sync')->push(new FailingJob());
            } catch (RuntimeException) {
                // 受け止めて続ける
            }

            throw new \LogicException('probe');
        });

        $this->get('/probe/after-sync')->assertStatus(500);

        $body = $this->onlyBody();
        $this->assertStringContainsString('リクエスト: GET /probe/after-sync', $body);
        $this->assertStringNotContainsString('ジョブ: ', $body);
    }

    #[Test]
    public function syncの失敗を包み直した例外でも_ジョブの入口で出す(): void
    {
        Context::add('request_id', 'req-caller');
        try {
            Queue::connection('sync')->push(new FailingJob());
        } catch (RuntimeException $e) {
            report(new \LogicException('wrapped', 0, $e));
        }

        $body = $this->onlyBody();
        $this->assertStringContainsString("ジョブ: FailingJob（試行 1 回目）\n", $body);
        $this->assertStringContainsString('Request ID: ' . FailingJob::$seenRequestId . "（ジョブの中のログ）\n", $body);
        $this->assertStringContainsString("発端の Request ID: req-caller（例外の記録はこの番号）\n", $body);
    }

    #[Test]
    public function 入れ子のコマンドでは_一番外のコマンドの終わりで戻す(): void
    {
        $input = new \Symfony\Component\Console\Input\ArrayInput([]);
        $output = new \Symfony\Component\Console\Output\NullOutput();
        Event::dispatch(new \Illuminate\Console\Events\CommandStarting('outer', $input, $output));
        try {
            Queue::connection('sync')->push(new FailingJob());
        } catch (RuntimeException) {
            // 受け止めて続ける
        }
        Event::dispatch(new \Illuminate\Console\Events\CommandStarting('inner', $input, $output));
        Event::dispatch(new \Illuminate\Console\Events\CommandFinished('inner', $input, $output, 0));

        $this->assertSame(FailingJob::$seenRequestId, Context::get('request_id'), '中の Artisan::call の終わりでは戻さない');

        Event::dispatch(new \Illuminate\Console\Events\CommandFinished('outer', $input, $output, 0));
        $this->assertFalse(Context::has('request_id'));
    }

    #[Test]
    public function 最小の知らせも_普通の知らせと同じRequestIDを出す(): void
    {
        Context::add('request_id', 'req-caller');

        try {
            Queue::connection('sync')->push(new FailingJob());
        } catch (RuntimeException $e) {
            $this->reporterWithBrokenConfig('rich-chatwork.message_max_length')->report($e);
        }

        $body = $this->onlyBody();
        $this->assertStringContainsString('知らせを組み立てられなかった[/title]Request ID: ' . FailingJob::$seenRequestId . "（ジョブの中のログ）\n発端の Request ID: req-caller（例外の記録はこの番号）[/info]", $body);
    }

    #[Test]
    public function 印の付いた段は_Contextが入れ替わっていなければ戻してから捨てる(): void
    {
        $entrance = $this->app?->make(JobEntrance::class);
        $this->assertInstanceOf(JobEntrance::class, $entrance);
        Context::add('request_id', 'req-a');
        $first = new FakeJob('App\Jobs\FirstJob');
        $entrance->processing($first);
        $entrance->failed($first, new RuntimeException('probe'));

        $second = new FakeJob('App\Jobs\SecondJob');
        $entrance->processing($second);

        $this->assertSame('req-a', $entrance->of(new RuntimeException('probe'), new RuntimeException('probe'))['origin'] ?? null, '前のジョブの ID を発端にしない');
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
        $this->assertStringContainsString('Request ID: ' . FailingJob::$seenRequestId . '（ジョブの中のログ）', $body);
        $this->assertStringContainsString("発端の Request ID: req-top（例外の記録はこの番号）\n", $body);
        $this->assertNotSame(OuterJob::$seenRequestId, FailingJob::$seenRequestId);
        $this->assertSame('req-top', Context::get('request_id'), '外側のジョブの分も戻り、2 回戻らない');
    }

    /**
     * 設定の 1 つのキーを読むと投げる Reporter（組み立ての失敗を起こす）。入口は本物の JobEntrance を使う
     */
    private function reporterWithBrokenConfig(string $brokenKey): Reporter
    {
        $config = new class (config()->all(), $brokenKey) extends ConfigRepository {
            /**
             * @param array<string, mixed> $items
             */
            public function __construct(array $items, private readonly string $broken)
            {
                parent::__construct($items);
            }

            /**
             * @param array<mixed>|string $key
             */
            public function get($key, $default = null): mixed
            {
                if ($key === $this->broken) {
                    throw new \LogicException('config is broken');
                }

                return parent::get($key, $default);
            }
        };
        $app = $this->app ?? $this->fail('アプリが無い');

        return new Reporter($app, $config, $app->make('cache'), $this->transport, self::source(), $app->make(JobEntrance::class));
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
