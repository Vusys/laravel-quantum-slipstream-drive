<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\QueryRicerExtreme\Query\ModelMetadata;

final class ModelMetadataTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        ModelMetadata::flush();
        TableCountingModel::$tableCalls = 0;
    }

    #[Test]
    public function table_is_memoized_per_class(): void
    {
        ModelMetadata::table(new TableCountingModel);
        ModelMetadata::table(new TableCountingModel);
        ModelMetadata::table(new TableCountingModel);

        self::assertSame(1, TableCountingModel::$tableCalls);
    }

    #[Test]
    public function flush_clears_the_memo_so_subsequent_call_re_invokes_get_table(): void
    {
        ModelMetadata::table(new TableCountingModel);
        ModelMetadata::flush();
        ModelMetadata::table(new TableCountingModel);

        self::assertSame(2, TableCountingModel::$tableCalls);
    }
}

final class TableCountingModel extends Model
{
    public static int $tableCalls = 0;

    protected $table = 'table_counting_models';

    #[\Override]
    public function getTable(): string
    {
        self::$tableCalls++;

        return parent::getTable();
    }
}
