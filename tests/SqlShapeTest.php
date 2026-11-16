<?php

declare(strict_types=1);

namespace Ad5\RichPhpChatwork\Tests;

use Ad5\RichPhpChatwork\SqlShape;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase as PlainTestCase;
use RuntimeException;

/**
 * 値を ? にした SQL の形。値が SQL に入る道（vendor の Laravel で確かめた物）ごとに、値が ? になって出ないことを見る
 */
final class SqlShapeTest extends PlainTestCase
{
    /** SQL に埋めた値。形のどこにも出てはいけない */
    private const VALUE = 'probe-secret';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function valuePaths(): iterable
    {
        // whereIntegerInRaw・int の主キーの whereKey・eager load は整数を SQL に直接並べる
        yield 'whereIntegerInRaw の整数の並び' => [
            'select * from `employees` where `employees`.`id` in (90210, 90211, 90212, 90213, 90214)',
            'select * from `employees` where `employees`.`id` in (? ×5)',
        ];
        yield 'limit・offset' => ['select * from `employees` limit 90210 offset 90211', 'select * from `employees` limit ? offset ?'];
        yield 'RAND の種' => ['select * from `employees` order by RAND(90210)', 'select * from `employees` order by rand(?)'];
        yield 'JSON の列のパス' => [
            'select * from `employees` where json_unquote(json_extract(`meta`, \'$."' . self::VALUE . '"\')) = ?',
            'select * from `employees` where json_unquote(json_extract(`meta`, ?)) = ?',
        ];
        yield 'whereRaw に引用符で書いた文字列' => ["select * from `users` where `email` = '" . self::VALUE . "@example.test'", 'select * from `users` where `email` = ?'];
        // 許可リストに無い語は ? になり、間の記号（-）だけが残る
        yield 'whereRaw に引用符なしで埋めた値' => ['select * from `users` where token = ' . self::VALUE, 'select * from `users` where ? = ?-?'];
        yield 'selectRaw の数' => ['select `id`, 90210 as `probe` from `users`', 'select `id`, ? as `probe` from `users`'];
        yield 'orderByRaw の式' => ['select * from `users` order by field(`id`, 90210, 90211)', 'select * from `users` order by ?(`id`, ?, ?)'];
        yield 'havingRaw' => ['select `dept`, count(*) from `users` group by `dept` having count(*) > 90210', 'select `dept`, count(*) from `users` group by `dept` having count(*) > ?'];
        yield 'DB::statement のコメント' => ['update `users` set `active` = 0 /* ' . self::VALUE . ' */ where `id` = 90210', 'update `users` set `active` = ? where `id` = ?'];
        yield 'DB::unprepared の複数の文' => ["set @probe = '" . self::VALUE . "'; delete from `users` where `id` = @probe", 'set ? = ? delete from `users` where `id` = ?'];
        yield 'スキーマの既定値とコメント' => [
            "alter table `users` add `status` varchar(20) not null default '" . self::VALUE . "' comment '" . self::VALUE . "'",
            'alter table `users` add `status` varchar(?) not null default ?',
        ];
        yield '16 進・指数・小数・文字コード付きの文字列' => ["select * from `t` where `a` = 0x70726f6265 or `b` = 1e-5 or `c` = 1.5 or `d` = _utf8mb4'" . self::VALUE . "' or `e` = X'70'", 'select * from `t` where `a` = ? or `b` = ? or `c` = ? or `d` = ? or `e` = ?'];
        yield '名前付きの引数と変数' => ['select * from `t` where `a` = :' . self::VALUE . ' and `b` = @' . self::VALUE, 'select * from `t` where `a` = ?-? and `b` = ?-?'];
        yield '全角の値' => ['select * from `t` where `name` = 山田' . self::VALUE, 'select * from `t` where `name` = ?-?'];
    }

    #[Test]
    #[DataProvider('valuePaths')]
    public function SQLに直接入った値は_どの道でも出さない(string $sql, string $expected): void
    {
        $shape = SqlShape::shape($sql);

        $this->assertSame($expected, $shape);
        $this->assertNoValue($shape);
    }

    #[Test]
    public function 識別子に名前の文字だけを入れた物は残り_ほかの文字を含めば出さない(): void
    {
        // orderBy($request->sort) のような書き方。名前の文字だけの値は残る（README の伏せきれない物）
        $this->assertSame('select * from `users` order by `probe_sort` asc', SqlShape::shape('select * from `users` order by `probe_sort` asc'));
        $this->assertSame('select * from `users` order by ? asc', SqlShape::shape('select * from `users` order by `probe sort; drop` asc'));
        $this->assertSame('select * from ?', SqlShape::shape('select * from `' . str_repeat('a', 65) . '`'));
        $this->assertSame('select * from ?', SqlShape::shape('select * from `a``b`'));
    }

    #[Test]
    public function 接続の設定のデータベース名は_dbに替える(): void
    {
        $this->assertSame('select * from {db}.`users` join `other_db`.`roles`', SqlShape::shape('select * from `probe_db`.`users` join `other_db`.`roles`', 'probe_db'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unclosed(): iterable
    {
        yield '閉じない単引用符' => ["select * from `t` where `a` = '" . self::VALUE . ' and `b` = 1', 'select * from `t` where `a` = ?'];
        yield '閉じない二重引用符' => ['select * from `t` where `a` = "' . self::VALUE, 'select * from `t` where `a` = ?'];
        yield '閉じないバッククォート' => ['select * from `t` where `' . self::VALUE, 'select * from `t` where ?'];
        yield '閉じないブロックのコメント' => ['select * from `t` /* ' . self::VALUE . ' where `a` = 1', 'select * from `t` ?'];
        yield '行のコメント（--）' => ["select * from `t` -- " . self::VALUE . "\nwhere `a` = ?", 'select * from `t` ? where `a` = ?'];
        yield '行のコメント（#）' => ["select * from `t` # " . self::VALUE . "\nwhere `a` = ?", 'select * from `t` ? where `a` = ?'];
        yield '空白の無い --' => ['select * from `t` where `a` = 1--' . self::VALUE, 'select * from `t` where `a` = ?'];
    }

    #[Test]
    #[DataProvider('unclosed')]
    public function 閉じない引用とコメントは_末尾まで飲む(string $sql, string $expected): void
    {
        $shape = SqlShape::shape($sql);

        $this->assertSame($expected, $shape);
        $this->assertNoValue($shape);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function escapes(): iterable
    {
        yield 'バックスラッシュで逃がした引用符' => ["select * from `t` where `a` = 'it\\' " . self::VALUE . "' and `b` = ?", 'select * from `t` where `a` = ? and `b` = ?'];
        yield '重ねた引用符' => ["select * from `t` where `a` = 'it'' " . self::VALUE . "' and `b` = ?", 'select * from `t` where `a` = ? and `b` = ?'];
        yield '末尾のバックスラッシュ' => ["select * from `t` where `a` = '" . self::VALUE . '\\', 'select * from `t` where `a` = ?'];
        // NO_BACKSLASH_ESCAPES の DB では 'x\' で文字列が終わるが、逃がしとして読み、後ろまで ? に飲む（伏せすぎる側）
        yield 'NO_BACKSLASH_ESCAPES の形' => ["select * from `t` where `a` = 'x\\' and `b` = '" . self::VALUE . "'", 'select * from `t` where `a` = ?-?'];
        yield '文字列の中のコメントの印' => ["select * from `t` where `a` = '-- /* # " . self::VALUE . "' and `b` = ?", 'select * from `t` where `a` = ? and `b` = ?'];
    }

    #[Test]
    #[DataProvider('escapes')]
    public function 文字列の中の逃がしと重ねた引用符は_中身として読む(string $sql, string $expected): void
    {
        $shape = SqlShape::shape($sql);

        $this->assertSame($expected, $shape);
        $this->assertNoValue($shape);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function markup(): iterable
    {
        yield '文字列の中の閉じタグ' => ["select * from `t` where `a` = '[/code][toall]'"];
        yield '引用符の外の閉じタグ' => ['select * from `t` where `a` = [/code][To:123][info]'];
        yield '識別子の中の閉じタグ' => ['select * from `[/code]` where `a` = ?'];
        yield '改行と引用符' => ["select *\nfrom `t`\r\nwhere `a` = \"x\" and `b` = 'y'"];
        yield '波括弧' => ['select * from `t` where `a` = {probe}'];
    }

    #[Test]
    #[DataProvider('markup')]
    public function Chatworkの記法と引用符と改行は_字句の段階で作れない(string $sql): void
    {
        $shape = (string) SqlShape::shape($sql);

        $this->assertNotSame('', $shape);
        $this->assertDoesNotMatchRegularExpression('/[\[\]\'"\n\r{}]/', $shape);
    }

    #[Test]
    public function 括弧の中のプレースホルダーの並びは_4個から縮める(): void
    {
        $this->assertSame('select * from `t` where `id` in (?, ?, ?)', SqlShape::shape('select * from `t` where `id` in (?, ?, ?)'));
        $this->assertSame('select * from `t` where `id` in (? ×4)', SqlShape::shape('select * from `t` where `id` in (?, ?, ?, ?)'));
        $ids = implode(', ', range(1, 1200));
        $this->assertSame(
            'select * from `employees` where `employees`.`id` in (? ×1200) and `employees`.`deleted_at` is null',
            SqlShape::shape("select * from `employees` where `employees`.`id` in ({$ids}) and `employees`.`deleted_at` is null"),
        );
        // ? と , のほかが混ざれば縮めない
        $this->assertSame('select coalesce(?, ?, ?, `a`) from `t`', SqlShape::shape('select coalesce(?, ?, ?, `a`) from `t`'));
    }

    #[Test]
    public function insertの行は縮めず_同じ形の行が続けば行の数で縮める(): void
    {
        $this->assertSame(
            'insert into `employees` (`name`, `email`, `department_id`, `updated_at`, `created_at`) values (?, ?, ?, ?, ?)',
            SqlShape::shape('insert into `employees` (`name`, `email`, `department_id`, `updated_at`, `created_at`) values (?, ?, ?, ?, ?)'),
        );
        $this->assertSame(
            'insert into `evaluations` (`employee_id`, `period_id`, `score`) values (?, ?, ?) ×3',
            SqlShape::shape('insert into `evaluations` (`employee_id`, `period_id`, `score`) values (?, ?, ?), (?, ?, ?), (?, ?, ?)'),
        );
        $rows = implode(', ', array_fill(0, 500, "(90210, '" . self::VALUE . "', now())"));
        $this->assertSame('insert into `t` (`a`, `b`, `c`) values (?, ?, now()) ×500', SqlShape::shape("insert into `t` (`a`, `b`, `c`) values {$rows}"));
        // 形の違う行が混ざれば縮めない
        $this->assertSame('insert into `t` values (?, ?), (?, now())', SqlShape::shape('insert into `t` values (?, ?), (?, now())'));
    }

    #[Test]
    public function 表示幅600を超えたら_末尾を省いた印にする(): void
    {
        $columns = implode(', ', array_map(static fn (int $i): string => "`column_{$i}`", range(1, 100)));
        $shape = (string) SqlShape::shape("select {$columns} from `t`");

        $this->assertLessThanOrEqual(SqlShape::WIDTH, mb_strwidth($shape));
        $this->assertStringEndsWith(SqlShape::OMITTED, $shape);
        $this->assertStringStartsWith('select `column_1`, `column_2`', $shape);
    }

    #[Test]
    public function 読むのは先頭の20000バイトまでで_後ろは読まずに印を付ける(): void
    {
        // 20,000 バイトの境目をまたぐ文字列も、閉じが無いものとして末尾まで ? になる
        $sql = 'select * from `t` where `a` = ? ' . str_repeat(' ', SqlShape::READ_BYTES - 50) . "and `b` = '" . self::VALUE . "' and `c` = ?";
        $shape = (string) SqlShape::shape($sql);

        $this->assertSame('select * from `t` where `a` = ? and `b` = ?…', $shape);
        $this->assertNoValue($shape);
    }

    #[Test]
    public function 長いSQLでも1回の読み取りで終わる(): void
    {
        $started = hrtime(true);
        $shape = SqlShape::shape(str_repeat("(('\\", 300000));

        $this->assertNotNull($shape);
        $this->assertLessThan(2_000_000_000, hrtime(true) - $started, '20,000 バイトで止まり、後戻りしない');
    }

    #[Test]
    public function MySQLとMariaDBの接続だけで出し_ほかのドライバーでは出さない(): void
    {
        $this->assertSame('select * from `users` where `id` = ?', SqlShape::of($this->query('mysql', 'select * from `users` where `id` = ?')));
        $this->assertSame('select * from `users` where `id` = ?', SqlShape::of($this->query('mariadb', 'select * from `users` where `id` = ?')));
        $this->assertNull(SqlShape::of($this->query('pgsql', 'select * from "users" where "id" = ?')));
        $this->assertNull(SqlShape::of($this->query('sqlite', 'select * from "users" where "id" = ?')));
        $this->assertNull(SqlShape::of($this->query('sqlsrv', 'select * from [users] where [id] = ?')));
        $this->assertNull(SqlShape::of(new QueryException('probe-unknown', 'select 1', [], new PDOException('probe'))), '設定にも詳細にもドライバーが無ければ出さない');
    }

    #[Test]
    public function 例外の詳細のデータベース名を_dbに替える(): void
    {
        $this->assertSame('select * from {db}.`users`', SqlShape::of($this->query('mysql', 'select * from `probe_db`.`users`')));
    }

    #[Test]
    public function 組み立てに失敗したら_形を出さない(): void
    {
        $e = new class ('mysql', 'select 1', [], new PDOException('probe'), ['driver' => 'mysql']) extends QueryException {
            public function getSql(): string
            {
                throw new RuntimeException('probe');
            }
        };

        $this->assertNull(SqlShape::of($e));
    }

    #[Test]
    public function 空のSQLは形を出さない(): void
    {
        $this->assertNull(SqlShape::shape(''));
        $this->assertNull(SqlShape::shape(" \n\t "));
    }

    private function query(string $driver, string $sql): QueryException
    {
        return new QueryException($driver, $sql, [self::VALUE], new PDOException('probe'), ['driver' => $driver, 'host' => 'db.internal', 'port' => 3306, 'database' => 'probe_db']);
    }

    private function assertNoValue(?string $shape): void
    {
        $this->assertNotNull($shape);
        foreach ([self::VALUE, '90210', '90211', '山田', '70726f6265', 'utf8mb4'] as $value) {
            $this->assertStringNotContainsString($value, $shape);
        }
    }
}
