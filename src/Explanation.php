<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme;

use Vusys\QueryRicerExtreme\Enums\PlanType;

final class Explanation
{
    /**
     * @param  list<int|string>  $knownKeys
     * @param  list<int|string>  $missingKeys
     * @param  list<int|string>  $memoryKeys
     * @param  list<int|string>  $rejectedKeys
     */
    public function __construct(
        public readonly PlanType $type,
        public readonly string $modelClass,
        public readonly string $reason,
        public readonly bool $sqlExecuted,
        public readonly array $knownKeys = [],
        public readonly array $missingKeys = [],
        public readonly array $memoryKeys = [],
        public readonly array $rejectedKeys = [],
        public readonly ?string $coverageRegion = null,
    ) {}

    public function __toString(): string
    {
        return implode("\n", array_filter([
            "Plan: {$this->type->value}",
            "Model: {$this->modelClass}",
            "Reason: {$this->reason}",
            $this->knownKeys !== [] ? 'Known keys: ['.implode(', ', $this->knownKeys).']' : null,
            $this->missingKeys !== [] ? 'Missing keys: ['.implode(', ', $this->missingKeys).']' : null,
            'SQL executed: '.($this->sqlExecuted ? 'yes' : 'no'),
        ]));
    }
}
