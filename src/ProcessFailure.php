<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork;

use Throwable;

/**
 * 外のコマンド（Laravel の Process・Symfony の Process）の失敗。artisan の「コマンド: …」の行と取り違えないよう「外のコマンド」と書く。文言はコマンドラインそのもの（-p の後ろのパスワード・
 * --token の値など）を含むので使わず、終了コード・時間切れの秒数だけを例外の項目から取る
 *
 * どちらの部品も入っていない環境では、クラスの名前で当たらないだけ（instanceof は無いクラスでも投げない）
 */
final readonly class ProcessFailure
{
    private function __construct(
        public string $message,
        public string $summary,
        /** @var list<array{label: string, value: string, inSummary: bool}> */
        public array $items,
    ) {
    }

    public static function of(Throwable $e): ?self
    {
        return match (true) {
            $e instanceof \Illuminate\Process\Exceptions\ProcessTimedOutException => self::timeout($e->getPrevious() instanceof \Symfony\Component\Process\Exception\ProcessTimedOutException ? $e->getPrevious()->getExceededTimeout() : null),
            $e instanceof \Symfony\Component\Process\Exception\ProcessTimedOutException => self::timeout($e->getExceededTimeout()),
            // ProcessStartFailedException は ProcessFailedException を継承するので先に見る
            $e instanceof \Symfony\Component\Process\Exception\ProcessStartFailedException => new self('外のコマンドを起動できなかった（コマンドラインはログ）', '外のコマンドを起動できなかった', []),
            $e instanceof \Illuminate\Process\Exceptions\ProcessFailedException => self::failed($e->result->exitCode()),
            $e instanceof \Symfony\Component\Process\Exception\ProcessFailedException => self::failed($e->getProcess()->getExitCode()),
            $e instanceof \Symfony\Component\Process\Exception\RunProcessFailedException => self::failed($e->context->exitCode),
            default => null,
        };
    }

    private static function failed(?int $exit): self
    {
        if ($exit === null) {
            return new self('外のコマンドの失敗（コマンドラインはログ）', '外のコマンドの失敗', []);
        }

        return new self("外のコマンドの失敗（終了コード {$exit}、コマンドラインはログ）", "外のコマンドの失敗・終了コード {$exit}", [
            ['label' => '終了コード', 'value' => (string) $exit, 'inSummary' => true],
        ]);
    }

    private static function timeout(?float $seconds): self
    {
        if ($seconds === null) {
            return new self('外のコマンドの時間切れ（コマンドラインはログ）', '外のコマンドの時間切れ', []);
        }

        // 1 秒未満は小数 2 桁（0.04 秒）。それより短く 0.00 になるなら「0.01 秒未満」
        $shown = match (true) {
            $seconds < 0.005 => '0.01 秒未満',
            $seconds < 1 => rtrim(rtrim(sprintf('%.2f', $seconds), '0'), '.') . ' 秒',
            default => rtrim(rtrim(sprintf('%.1f', $seconds), '0'), '.') . ' 秒',
        };

        return new self("外のコマンドの時間切れ（{$shown}、コマンドラインはログ）", "外のコマンドの時間切れ・{$shown}", [
            ['label' => '時間切れの秒数', 'value' => $shown, 'inSummary' => true],
        ]);
    }
}
