<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork\Tests;

use Ad5\RichPhpChatwork\Names;
use Ad5\RichPhpChatwork\Source;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase as PlainTestCase;
use RuntimeException;

/**
 * クラス名・関数名の整形。サーバーの絶対パスと NUL を出さない
 */
final class NamesTest extends PlainTestCase
{
    private const BASE = '/srv/probe-app';

    #[Test]
    public function 匿名クラスはNULより後ろを捨て_相対パスと行を添える(): void
    {
        $names = $this->names();
        $anonymous = "RuntimeException@anonymous\0" . self::BASE . '/app/Services/Probe.php:12$0';

        $this->assertSame('RuntimeException@anonymous（app/Services/Probe.php:12）', $names->class($anonymous));
        $this->assertSame('RuntimeException@anonymous', $names->short($anonymous));
        $this->assertSame('class@anonymous', $names->class("class@anonymous\0"));
    }

    #[Test]
    public function 本物の匿名クラスの名前も整える(): void
    {
        $e = new class ('probe') extends RuntimeException {
        };
        $names = new Names(new Source(dirname(__DIR__)));

        $this->assertMatchesRegularExpression('/\ARuntimeException@anonymous（tests\/NamesTest\.php:\d+）\z/u', $names->class($e::class));
    }

    #[Test]
    public function クロージャのパスを相対にする(): void
    {
        $names = $this->names();

        $this->assertSame('{closure:routes/web.php:12}', $names->function('{closure:' . self::BASE . '/routes/web.php:12}'));
        $this->assertSame('{closure:App\Http\Controllers\ProbeController::index():30}', $names->function('{closure:App\Http\Controllers\ProbeController::index():30}'));
    }

    #[Test]
    public function 一般的な名前だけ_名前空間を1段付ける(): void
    {
        $names = $this->names();

        $this->assertSame('Service\Exception', $names->short('Google\Service\Exception'));
        $this->assertSame('Probe\RuntimeException', $names->short('App\Probe\RuntimeException'));
        $this->assertSame('RuntimeException', $names->short('RuntimeException'));
        $this->assertSame('QueryException', $names->short('Illuminate\Database\QueryException'));
    }

    private function names(): Names
    {
        return new Names(new Source(self::BASE));
    }
}
