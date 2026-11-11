<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork;

/**
 * クラス名・関数名を本文に出す形。件名・原因・包んだもの・呼び出しの並びは、必ずここを通す
 *
 * 匿名クラスの名前は「親@anonymous\0絶対パス:行$番号」、PHP 8.4 からのクロージャは「{closure:絶対パス:行}」で、
 * どちらもそのままだとサーバーのパスと NUL が出る
 */
final class Names
{
    /** 件名で、名前空間を 1 段付ける一般的な名前（PHP の組み込みの例外の名前） */
    private const GENERIC = [
        'Exception', 'Error', 'ErrorException', 'RuntimeException', 'LogicException', 'InvalidArgumentException',
        'DomainException', 'LengthException', 'OutOfRangeException', 'OutOfBoundsException', 'RangeException',
        'OverflowException', 'UnderflowException', 'UnexpectedValueException', 'BadFunctionCallException',
        'BadMethodCallException', 'TypeError', 'ValueError', 'ArithmeticError', 'DivisionByZeroError',
    ];

    public function __construct(private readonly Source $source)
    {
    }

    public function class(string $name): string
    {
        $anonymous = strpos($name, '@anonymous');
        if ($anonymous === false) {
            return str_replace("\0", '', $name);
        }

        $head = substr($name, 0, $anonymous) . '@anonymous';
        $where = substr($name, $anonymous + strlen("@anonymous\0"));
        if (preg_match('/\A(.+):(\d+)\$[0-9a-f]+\z/', $where, $match) !== 1) {
            return $head;
        }

        return $head . '（' . $this->source->relative($match[1]) . ':' . $match[2] . '）';
    }

    public function function(string $name): string
    {
        return $this->source->relativeIn(str_replace("\0", '', $name));
    }

    /**
     * 件名の短い名前。一般的な名前なら 1 段上の名前空間を付ける（Service\Exception）
     */
    public function short(string $class): string
    {
        $anonymous = strpos($class, '@anonymous');
        if ($anonymous !== false) {
            return $this->short(substr($class, 0, $anonymous)) . '@anonymous';
        }

        $parts = explode('\\', $class);
        $short = array_pop($parts);
        $parent = array_pop($parts);

        return in_array($short, self::GENERIC, true) && $parent !== null ? "{$parent}\\{$short}" : $short;
    }
}
