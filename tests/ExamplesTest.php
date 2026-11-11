<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork\Tests;

use Ad5\RichPhpChatwork\Reporter;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\TransferStats;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\View\ViewException;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\ErrorHandler\Error\FatalError;
use TypeError;

/**
 * 本文の形の例を、作り物のアプリで組み立てて確かめる。「…」は中身を問わない所、NNN は vendor の行番号
 */
final class ExamplesTest extends TestCase
{
    private FixtureApp $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = new FixtureApp();
        $this->useSource($this->fixture->source());
        $this->useEnvironment('production');
        config([
            'app.name' => 'example-app',
            'rich-chatwork.access_token' => 'probe-token',
            'rich-chatwork.room_id' => '12345',
            'rich-chatwork.snippet_paths' => ['app/', 'routes/'],
        ]);
        Context::flush();
        Reporter::resolveUserUsing(null);
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
        Reporter::resolveUserUsing(null);

        parent::tearDown();
    }

    #[Test]
    public function 例1_DBの値の長さの失敗(): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-11-11 14:02:00'));
        $this->fixture->file('app/UseCase/Actions/Employee/UpdateAction.php', [
            40 => '$param = EmployeeParam::from($request);',
            42 => '$employee->update($param->toArray());',
            44 => 'return $employee;',
        ]);
        $e = $this->queryFailure('22001', 1406, 'String data, right truncated', "Data too long for column 'name' at row 1");
        self::fabricate($e, $this->fixture->path('vendor/laravel/framework/src/Illuminate/Database/Connection.php'), 825, [
            ...$this->fixture->vendorFrames(3),
            ['file' => $this->fixture->path('app/UseCase/Actions/Employee/UpdateAction.php'), 'line' => 42, 'function' => 'update', 'class' => 'Illuminate\Database\Eloquent\Model', 'type' => '->'],
            ['file' => $this->fixture->path('app/Http/Controllers/EmployeeController.php'), 'line' => 31, 'function' => '__invoke', 'class' => 'App\UseCase\Actions\Employee\UpdateAction', 'type' => '->'],
            ...$this->fixture->vendorFrames(38),
        ]);
        $this->overHttp('PUT', '/api/employees/5', 'api/employees/{employee}');
        Reporter::resolveUserUsing(static fn (): string => '42');
        Context::add('request_id', '6f1c…');

        $this->reporter()->report($e);
        $this->travelTo(new \DateTimeImmutable('2026-11-11 14:02:30'));
        for ($count = 0; $count < 12; $count++) {
            $this->reporter()->report($e);
        }
        $this->travelTo(new \DateTimeImmutable('2026-11-11 14:03:01'));
        $this->reporter()->report($e);

        $this->assertCount(2, $this->transport->sent);
        $this->assertSame(<<<'TEXT'
            [info][title]example-app（production）QueryException: SQLSTATE 22001・列 name[/title][code]SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'name' at row 1 (Connection: mysql, SQL: （ログ）)[/code]アプリの行: app/UseCase/Actions/Employee/UpdateAction.php:42（vendor の Connection.php:825 で投げた）
            [code]  40 | $param = EmployeeParam::from($request);
              41 |
            > 42 | $employee->update($param->toArray());
              43 |
              44 | return $employee;[/code]リクエスト: PUT /api/employees/{employee} ／ 利用者: 42
            Request ID: 6f1c…（中身はログをこの番号で引く）
            同じ例外: 前の知らせ（14:02）の後の 60 秒に、送らずに 12 回
            [hr][code]（vendor の 3 段）
            #3 app/UseCase/Actions/Employee/UpdateAction.php:42 Illuminate\Database\Eloquent\Model->update()
            #4 app/Http/Controllers/EmployeeController.php:31 App\UseCase\Actions\Employee\UpdateAction->__invoke()
            （vendor の 38 段）[/code][/info]
            TEXT, $this->transport->sent[1]['body']);
        $this->assertStringNotContainsString('同じ例外', $this->transport->sent[0]['body'], '1 回目は間引いた回数が無い');
    }

    #[Test]
    public function 例2_ビューの中のDBの例外(): void
    {
        $this->fixture->file('app/Models/Employee.php', [120 => 'return $query->where(\'nme\', $value);']);
        $query = $this->queryFailure('42S22', 1054, 'Column not found', "Unknown column 'nme' in 'where clause'");
        self::fabricate($query, $this->fixture->path('vendor/laravel/framework/src/Illuminate/Database/Connection.php'), 825, [
            ...$this->fixture->vendorFrames(2),
            ['file' => $this->fixture->path('storage/framework/views/0123456789abcdef.php'), 'line' => 3, 'function' => 'scopeActive', 'class' => 'App\Models\Employee', 'type' => '->'],
            ['file' => $this->fixture->path('app/Models/Employee.php'), 'line' => 120, 'function' => 'where', 'class' => 'Illuminate\Database\Eloquent\Builder', 'type' => '->'],
            ...$this->fixture->vendorFrames(10),
        ]);
        $view = $this->fixture->path('resources/views/employees/show.blade.php');
        $inner = new ViewException($query->getMessage() . " (View: {$view})", 0, 1, $query->getFile(), $query->getLine(), $query);
        $outer = new ViewException($inner->getMessage() . " (View: {$view})", 0, 1, $query->getFile(), $query->getLine(), $inner);
        $this->overHttp('GET', '/employees/5', 'employees/{employee}');
        Reporter::resolveUserUsing(static fn (): string => '7');
        Context::add('request_id', 'req-2');

        $this->reporter()->report($outer);

        $this->assertMatchesExample(<<<'TEXT'
            [info][title]example-app（production）QueryException: SQLSTATE 42S22[/title][code]SQLSTATE[42S22]: Column not found: 1054 Unknown column 'nme' in 'where clause' (Connection: mysql, SQL: （ログ）)[/code]包んだもの: ViewException（ビュー resources/views/employees/show.blade.php）
            アプリの行: app/Models/Employee.php:120（vendor の Connection.php:NNN で投げた）
            [code]…[/code]リクエスト: GET /employees/{employee} ／ 利用者: 7
            Request ID: …（中身はログをこの番号で引く）
            [hr][code]…[/code][/info]
            TEXT, $this->onlyBody());
    }

    #[Test]
    public function 例3_ジョブの中のTypeError(): void
    {
        config(['rich-chatwork.send_message' => true]);
        $this->fixture->file('app/Jobs/SyncEmployeeJob.php', [28 => '$this->service->run($this->employeeId);']);
        $e = new TypeError('App\Services\Employee\SyncService::run(): Argument #1 ($employeeId) must be of type int, null given, called in ' . $this->fixture->path('app/Jobs/SyncEmployeeJob.php') . ' on line 28');
        self::fabricate($e, $this->fixture->path('app/Services/Employee/SyncService.php'), 15, [
            ['file' => $this->fixture->path('app/Jobs/SyncEmployeeJob.php'), 'line' => 28, 'function' => 'run', 'class' => 'App\Services\Employee\SyncService', 'type' => '->'],
            ...$this->fixture->vendorFrames(5),
        ]);
        Context::add('request_id', 'req-origin');
        $job = new FakeJob('App\Jobs\SyncEmployeeJob', 2);
        Event::dispatch(new JobProcessing('redis', $job));
        Event::dispatch(new JobExceptionOccurred('redis', $job, $e));

        $this->reporter()->report($e);

        $body = $this->onlyBody();
        $this->assertMatchesExample(<<<'TEXT'
            [info][title]example-app（production）TypeError: SyncService::run() 引数 #1 int←null[/title][code]App\Services\Employee\SyncService::run(): Argument #1 ($employeeId) must be of type int, null given, called in app/Jobs/SyncEmployeeJob.php on line 28[/code]種類: 引数の名前: $employeeId
            アプリの行: app/Jobs/SyncEmployeeJob.php:28（定義は SyncService.php:15）
            [code]…[/code]ジョブ: SyncEmployeeJob（試行 2 回目）
            Request ID: …（中身はログをこの番号で引く）
            発端の Request ID: req-origin
            [hr][code]…[/code][/info]
            TEXT, $body);
        $this->assertStringNotContainsString('Request ID: req-origin（', $body, 'ジョブの Request ID は新しく付けた物');
        $this->assertNotSame('req-origin', Context::get('request_id'), 'report() が済むまでは戻さない（ログの Request ID と揃える）');
        Event::dispatch(new Looping('redis', 'default'));
        $this->assertSame('req-origin', Context::get('request_id'), 'ワーカーが次へ回るときに戻す');
    }

    #[Test]
    public function 例3b_外のAPIの失敗(): void
    {
        $this->fixture->file('app/Services/Notify/SlackNotifier.php', [40 => 'Http::post($this->url, $payload)->throw();']);
        $response = new Response(new Psr7Response(404, [], 'no_service'));
        $response->transferStats = new TransferStats(new Psr7Request('POST', 'https://hooks.slack.example/services/T0PROBE01/B0PROBE02/ProbeSecretProbeSecret12'), $response->toPsrResponse());
        $e = self::fabricate(new RequestException($response), $this->fixture->path('vendor/laravel/framework/src/Illuminate/Http/Client/PendingRequest.php'), 1100, [
            ...$this->fixture->vendorFrames(2),
            ['file' => $this->fixture->path('app/Services/Notify/SlackNotifier.php'), 'line' => 40, 'function' => 'throw', 'class' => 'Illuminate\Http\Client\Response', 'type' => '->'],
        ]);
        Context::add(['request_id' => 'req-3b', 'command' => 'report:daily']);

        $this->reporter()->report($e);

        $this->assertMatchesExample(<<<'TEXT'
            [info][title]example-app（production）RequestException: HTTP 404[/title][code]HTTP 404 POST https://hooks.slack.example/services/{…}/{…}/{…}[/code]アプリの行: app/Services/Notify/SlackNotifier.php:40（vendor の PendingRequest.php:NNN で投げた）
            [code]…[/code]コマンド: report:daily
            Request ID: …（中身はログをこの番号で引く）
            [hr][code]…[/code][/info]
            TEXT, $this->onlyBody());
    }

    #[Test]
    public function 例4_メモリ切れ(): void
    {
        $e = new FatalError('Allowed memory size of 536870912 bytes exhausted (tried to allocate 20480 bytes)', 0, [
            'type' => E_ERROR,
            'file' => $this->fixture->path('vendor/laravel/framework/src/Illuminate/Collections/Collection.php'),
            'line' => 512,
        ], 0, true, []);
        Context::add(['request_id' => 'req-4', 'command' => 'report:daily --since=2026-11-01']);

        $this->reporter()->report($e);

        $this->assertMatchesExample(<<<'TEXT'
            [info][title]example-app（production）FatalError: メモリ切れ（上限 512 MB）[/title]アプリの行: 無し（vendor の Collection.php:NNN で投げた）
            コマンド: report:daily
            Request ID: …（中身はログをこの番号で引く）
            呼び出しの並び: 取れない[/info]
            TEXT, $this->onlyBody());
    }

    #[Test]
    public function 例5_例外ではない知らせ(): void
    {
        $this->reporter()->notice('ログインの失敗が多い', ['直近 10 分の失敗: 52 回', 'IP やログイン ID は laravel.log を見る'], 'signin:all');

        $this->assertSame("[info][title]example-app（production）知らせ: ログインの失敗が多い[/title]直近 10 分の失敗: 52 回\nIP やログイン ID は laravel.log を見る[/info]", $this->onlyBody());
    }

    private function reporter(): Reporter
    {
        return $this->app?->make(Reporter::class) ?? $this->fail('アプリが無い');
    }

    private function onlyBody(): string
    {
        $this->assertCount(1, $this->transport->sent);

        return $this->transport->sent[0]['body'];
    }

    /**
     * 例の「…」は何でもよい所（改行を含む）、NNN は数字
     */
    private function assertMatchesExample(string $example, string $body): void
    {
        $pattern = preg_quote(str_replace(['…', 'NNN'], ['@@ANY@@', '@@NUM@@'], $example), '/');
        $pattern = str_replace(['@@ANY@@', '@@NUM@@'], ['.*?', '\d+'], $pattern);

        $this->assertMatchesRegularExpression('/\A' . $pattern . '\z/su', $body);
    }

    private function overHttp(string $method, string $path, string $uri): void
    {
        $this->runningOverHttp();
        $request = Request::create($path, $method);
        $route = new Route($method, $uri, static fn (): null => null);
        $request->setRouteResolver(static fn (): Route => $route);
        $this->app?->instance('request', $request);
    }

    private function queryFailure(string $state, int $driver, string $stateText, string $driverMessage): QueryException
    {
        $pdo = new PDOException("SQLSTATE[{$state}]: {$stateText}: {$driver} {$driverMessage}");
        $pdo->errorInfo = [$state, $driver, $driverMessage];

        return new QueryException('mysql', 'update employees set name = ? where id = ?', ['probe-value', 5], $pdo, ['driver' => 'mysql', 'host' => 'db.internal', 'port' => 3306, 'database' => 'probe_db']);
    }
}
