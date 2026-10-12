<?php

declare(strict_types=1);

namespace WilyCode\Database\DriverMysqli\Tests;

use PHPUnit\Framework\TestCase;
use WilyCode\Database\DriverMysqli\MysqliBinder;
use WilyCode\Database\Query\CompiledQuery;
use WilyCode\Database\Query\Enum\ParameterType;

final class MysqliBinderTest extends TestCase
{
    public function testConvertsNamedPlaceholdersToPositionalInOrder(): void
    {
        $q = new CompiledQuery(
            'SELECT * FROM users WHERE id = :id AND email = :email',
            [':id' => 10, ':email' => 'a@example.com'],
            [':id' => ParameterType::INTEGER, ':email' => ParameterType::STRING],
        );

        $bound = new MysqliBinder()->bind($q);

        self::assertSame('SELECT * FROM users WHERE id = ? AND email = ?', $bound->sql);
        self::assertSame([10, 'a@example.com'], $bound->params);
        self::assertSame(['i', 's'], $bound->types);
    }

    public function testIgnoresPlaceholdersInsideStringsIdentifiersAndComments(): void
    {
        $sql = <<<SQL
SELECT
  ':a' AS single_q,
  ":a" AS double_q,
  `:a` AS backtick,
  col = :a,
  -- :a in line comment
  other = :b,
  /* :a in block comment */
  tail = :c
SQL;

        $q = new CompiledQuery(
            $sql,
            [':a' => 1, ':b' => 2, ':c' => 3],
            [':a' => ParameterType::INTEGER, ':b' => ParameterType::INTEGER, ':c' => ParameterType::INTEGER],
        );

        $bound = new MysqliBinder()->bind($q);

        // Only placeholders outside strings/comments are replaced
        self::assertStringContainsString("':a' AS single_q", $bound->sql);
        self::assertStringContainsString('":a" AS double_q', $bound->sql);
        self::assertStringContainsString('`:a` AS backtick', $bound->sql);

        self::assertStringContainsString('col = ?', $bound->sql);
        self::assertStringContainsString('other = ?', $bound->sql);
        self::assertStringContainsString('tail = ?', $bound->sql);

        self::assertSame([1, 2, 3], $bound->params);
        self::assertSame(['i', 'i', 'i'], $bound->types);
    }

    public function testMapsParameterTypesToMysqliBindTypes(): void
    {
        $q = new CompiledQuery(
            'SELECT :i, :f, :s, :b, :n, :lob',
            [':i' => 1, ':f' => 1.5, ':s' => 'x', ':b' => true, ':n' => null, ':lob' => 'blob'],
            [
                ':i' => ParameterType::INTEGER,
                ':f' => ParameterType::FLOAT,
                ':s' => ParameterType::STRING,
                ':b' => ParameterType::BOOLEAN,
                ':n' => ParameterType::NULL,
                ':lob' => ParameterType::LOB,
            ],
        );

        $bound = new MysqliBinder()->bind($q);

        self::assertSame('SELECT ?, ?, ?, ?, ?, ?', $bound->sql);
        self::assertSame([1, 1.5, 'x', true, null, 'blob'], $bound->params);
        self::assertSame(['i', 'd', 's', 'i', 's', 'b'], $bound->types);
    }

    public function testDoesNotTreatDoubleDashWithoutWhitespaceAsComment(): void
    {
        // In MySQL "--" starts a comment only if followed by whitespace.
        $q = new CompiledQuery(
            'SELECT 1--1 AS x, :a AS y',
            [':a' => 123],
            [':a' => ParameterType::INTEGER],
        );

        $bound = new MysqliBinder()->bind($q);

        self::assertSame('SELECT 1--1 AS x, ? AS y', $bound->sql);
        self::assertSame([123], $bound->params);
        self::assertSame(['i'], $bound->types);
    }

    public function testIgnoresHashLineComments(): void
    {
        $sql = "SELECT 1 # :a in hash comment
, :b AS ok";
        $q = new CompiledQuery(
            $sql,
            [':b' => 2],
            [':b' => ParameterType::INTEGER],
        );

        $bound = new MysqliBinder()->bind($q);

        self::assertStringContainsString('# :a in hash comment', $bound->sql);
        self::assertStringContainsString(', ? AS ok', $bound->sql);
        self::assertSame([2], $bound->params);
        self::assertSame(['i'], $bound->types);
    }

    public function testIgnoresPlaceholdersInsideStringsWithDoubledQuotes(): void
    {
        $q = new CompiledQuery(
            "SELECT 'it''s :a' AS s, :a AS x",
            [':a' => 7],
            [':a' => ParameterType::INTEGER],
        );

        $bound = new MysqliBinder()->bind($q);

        self::assertSame("SELECT 'it''s :a' AS s, ? AS x", $bound->sql);
        self::assertSame([7], $bound->params);
        self::assertSame(['i'], $bound->types);
    }

    public function testInfersMissingTypesFromValues(): void
    {
        $query = new CompiledQuery(
            'SELECT :integer, :float, :string',
            [':integer' => 10, ':float' => 1.5, ':string' => 'x'],
        );

        $bound = new MysqliBinder()->bind($query);

        self::assertSame(['i', 'd', 's'], $bound->types);
    }

    public function testRejectsPositionalPlaceholdersInCanonicalQueries(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CompiledQuery('SELECT * FROM users WHERE id = ?', [':id' => 1]);
    }

    public function testCompiledSelectCanBeBoundForMysqli(): void
    {
        $compiled = \WilyCode\Database\Query\Query::select('users', 'u')
            ->where('u.id', 15)
            ->compile();

        $bound = new MysqliBinder()->bind($compiled);

        self::assertStringContainsString('u.id = ?', $bound->sql);
        self::assertSame([15], $bound->params);
        self::assertSame(['i'], $bound->types);
    }
}
