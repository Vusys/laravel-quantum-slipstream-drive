<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive\Graph;

use Illuminate\Database\Eloquent\Model;
use Vusys\QuantumSlipstreamDrive\Query\ScopeFingerprinter;

final readonly class ModelIdentity
{
    public function __construct(
        public string $connection,
        public string $modelClass,
        public string $table,
        public string $primaryKeyName,
        public int|string $primaryKeyValue,
        public string $scopeFingerprint,
    ) {}

    public static function fromModel(Model $model, ?string $fingerprint = null): ?self
    {
        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            return null;
        }

        return new self(
            connection: $model->getConnectionName() ?? 'default',
            modelClass: $model::class,
            table: $model->getTable(),
            primaryKeyName: $model->getKeyName(),
            primaryKeyValue: $key,
            scopeFingerprint: $fingerprint ?? ScopeFingerprinter::fromModel($model),
        );
    }

    public function key(): string
    {
        return implode('|', [
            $this->connection,
            $this->modelClass,
            $this->table,
            $this->primaryKeyName,
            (string) $this->primaryKeyValue,
            $this->scopeFingerprint,
        ]);
    }
}
