<?php

declare(strict_types=1);

namespace WilyCode\Database\DriverMysqli;

use InvalidArgumentException;
use WilyCode\Database\Query\Binding\BinderInterface;
use WilyCode\Database\Query\Binding\BoundQuery;
use WilyCode\Database\Query\CompiledQuery;
use WilyCode\Database\Query\Enum\ParameterType;
use WilyCode\Database\Query\Support\SqlPlaceholderRewriter;

final class MysqliBinder implements BinderInterface
{
    public function bind(CompiledQuery $query): BoundQuery
    {
        [$sql, $params, $types] = $this->namedToPositional(
            $query->sql,
            $query->params,
            $query->types,
        );

        $mysqliTypes = [];
        foreach ($params as $index => $value) {
            $type = $types[$index] ?? ParameterType::fromPhpType($value);
            $mysqliTypes[] = match ($type) {
                ParameterType::INTEGER, ParameterType::BOOLEAN => 'i',
                ParameterType::FLOAT => 'd',
                ParameterType::LOB => 'b',
                default => 's',
            };
        }

        return new BoundQuery($sql, $params, $mysqliTypes);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, ParameterType|null> $types
     * @return array{0:string,1:list<mixed>,2:list<ParameterType|null>}
     */
    private function namedToPositional(string $sql, array $params, array $types): array
    {
        $positionalParams = [];
        $positionalTypes = [];

        $sql = SqlPlaceholderRewriter::rewrite(
            $sql,
            static function (): never {
                throw new InvalidArgumentException(
                    'Canonical compiled queries cannot contain positional placeholders.',
                );
            },
            static function (string $name) use (
                $params,
                $types,
                &$positionalParams,
                &$positionalTypes,
            ): string {
                $placeholder = ':' . $name;
                $positionalParams[] = $params[$placeholder];
                $positionalTypes[] = $types[$placeholder] ?? null;

                return '?';
            },
        );

        return [$sql, $positionalParams, $positionalTypes];
    }
}
