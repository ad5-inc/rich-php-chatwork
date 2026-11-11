<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork;

use Illuminate\Contracts\Config\Repository as Config;

/**
 * 設定 rich-chatwork.* を型を決めて読む。型の違う値は既定に戻す（使う側の書き間違いで知らせが止まらないように）
 */
final class Settings
{
    /**
     * @param list<string> $default
     *
     * @return list<string>
     */
    public static function strings(Config $config, string $key, array $default): array
    {
        $value = $config->get("rich-chatwork.{$key}");

        return is_array($value) ? array_values(array_filter($value, is_string(...))) : $default;
    }

    public static function positive(Config $config, string $key, int $default): int
    {
        $value = $config->get("rich-chatwork.{$key}");

        return is_int($value) && $value > 0 ? $value : $default;
    }

    public static function string(Config $config, string $key, string $default = ''): string
    {
        $value = $config->get("rich-chatwork.{$key}");

        return is_string($value) ? $value : $default;
    }
}
