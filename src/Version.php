<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork;

use Composer\InstalledVersions;
use Throwable;

/**
 * 本文の最後の行に出す、知らせを作った部品の版。版によって本文の形が違うので、読み手が CHANGELOG のどの版と照らせばよいかを示す
 *
 * 版は実行時に Composer の記録から取る（コードに版の数字を書かない。タグを打つだけで変わる）。
 * リリースの版の形（0.6.1・1.0.0-beta.2）だけを出し、dev のブランチ（dev-main）・記録が無い・取れないときは決まった文にする。
 * 版の文字は Composer の記録（使う側の composer.lock）から来るので、許可する形に当ててから出す
 */
final readonly class Version
{
    public const PACKAGE = 'ad5-inc/rich-php-chatwork';

    public const UNKNOWN = 'rich-php-chatwork（版は不明）';

    private const PATTERN = '/\Av?\d{1,5}\.\d{1,5}\.\d{1,5}(?:-[0-9A-Za-z]{1,20}(?:\.[0-9A-Za-z]{1,20}){0,3})?\z/';

    private const WIDTH = 80;

    public function __construct(private ?string $pretty)
    {
    }

    /**
     * 今入っている版。Composer 2 の InstalledVersions が無い・この部品の記録が無い（OutOfBoundsException）ときは不明
     */
    public static function installed(): self
    {
        try {
            $pretty = class_exists(InstalledVersions::class) ? InstalledVersions::getPrettyVersion(self::PACKAGE) : null;
        } catch (Throwable) {
            $pretty = null;
        }

        return new self($pretty);
    }

    /**
     * 本文の最後の行（「rich-php-chatwork 0.6.1」）。形が合わなければ決まった文
     */
    public function line(): string
    {
        if ($this->pretty === null || preg_match(self::PATTERN, $this->pretty) !== 1) {
            return self::UNKNOWN;
        }

        return Text::field('rich-php-chatwork ' . $this->pretty, false, null, self::WIDTH);
    }
}
