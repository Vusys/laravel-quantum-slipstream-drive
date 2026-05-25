<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Vusys\QueryRicerExtreme\Driver\ColumnType;
use Vusys\QueryRicerExtreme\Driver\StringComparisonMode;
use Vusys\QueryRicerExtreme\Schema\SchemaDiscovery;

/**
 * Drives the private mapping helpers in SchemaDiscovery directly via reflection
 * so each branch of {@see SchemaDiscovery::mapType()} and
 * {@see SchemaDiscovery::modeFromCollation()} is covered without booting Laravel
 * or hitting a database.
 */
final class SchemaDiscoveryMappingTest extends TestCase
{
    /**
     * @return iterable<array{string, ColumnType}>
     */
    public static function typeMappingProvider(): iterable
    {
        yield ['', ColumnType::Unknown];
        yield ['int', ColumnType::Integer];
        yield ['integer', ColumnType::Integer];
        yield ['bigint', ColumnType::Integer];
        yield ['smallint', ColumnType::Integer];
        yield ['tinyint', ColumnType::Integer];
        yield ['int8', ColumnType::Integer];
        yield ['float', ColumnType::Float];
        yield ['double', ColumnType::Float];
        yield ['decimal', ColumnType::Float];
        yield ['bool', ColumnType::Boolean];
        yield ['boolean', ColumnType::Boolean];
        yield ['uuid', ColumnType::Uuid];
        yield ['date', ColumnType::Date];
        yield ['datetime', ColumnType::DateTime];
        yield ['timestamp', ColumnType::DateTime];
        yield ['timestamptz', ColumnType::DateTime];
        yield ['json', ColumnType::Json];
        yield ['jsonb', ColumnType::Json];
        yield ['blob', ColumnType::Binary];
        yield ['bytea', ColumnType::Binary];
        yield ['varchar', ColumnType::String];
        yield ['text', ColumnType::String];
        yield ['citext', ColumnType::String];
        yield ['enum', ColumnType::String];
        yield ['anothertype', ColumnType::Unknown];
    }

    #[Test]
    #[DataProvider('typeMappingProvider')]
    public function map_type_returns_expected_type(string $typeName, ColumnType $expected): void
    {
        $method = (new ReflectionClass(SchemaDiscovery::class))->getMethod('mapType');
        self::assertSame($expected, $method->invoke(new SchemaDiscovery, $typeName));
    }

    /**
     * @return iterable<array{string, StringComparisonMode}>
     */
    public static function collationProvider(): iterable
    {
        yield ['utf8mb4_bin', StringComparisonMode::CaseSensitive];
        yield ['utf8_bin', StringComparisonMode::CaseSensitive];
        yield ['Latin1_General_CS_AS', StringComparisonMode::CaseSensitive];
        yield ['utf8mb4_unicode_ci', StringComparisonMode::CaseInsensitive];
        yield ['utf8mb4_general_ci', StringComparisonMode::CaseInsensitive];
        yield ['utf8mb4_0900_ai_ci', StringComparisonMode::CaseInsensitive];
        yield ['totally_unknown', StringComparisonMode::Unknown];
    }

    #[Test]
    #[DataProvider('collationProvider')]
    public function mode_from_collation_classifies(string $collation, StringComparisonMode $expected): void
    {
        $method = (new ReflectionClass(SchemaDiscovery::class))->getMethod('modeFromCollation');
        self::assertSame($expected, $method->invoke(new SchemaDiscovery, $collation));
    }
}
