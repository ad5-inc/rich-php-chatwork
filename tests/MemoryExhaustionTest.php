<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase as PlainTestCase;

/**
 * メモリ切れ。Laravel は 32KB しか空けずに report() を呼び、部品の callback が先に動く。
 * 部品がメモリを取ると 2 度目のメモリ切れで元の記録まで止まるので、別のプロセスで起こして確かめる
 */
final class MemoryExhaustionTest extends PlainTestCase
{
    #[Test]
    public function メモリ切れでも_Laravelの元の記録が残る(): void
    {
        $log = sys_get_temp_dir() . '/rich-chatwork-memory-' . bin2hex(random_bytes(4)) . '.log';
        $process = proc_open([PHP_BINARY, __DIR__ . '/bin/exhaust-memory.php', $log], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $this->assertIsResource($process);
        proc_close($process);

        $written = is_file($log) ? (string) file_get_contents($log) : '';
        if (is_file($log)) {
            unlink($log);
        }

        $this->assertStringContainsString('ERROR: Allowed memory size of', $written);
    }
}
