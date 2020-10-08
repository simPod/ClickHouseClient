<?php

declare(strict_types=1);

namespace SimPod\ClickHouseClient\Sql;

use function preg_replace;
use function Safe\sprintf;
use function str_replace;

/** @internal */
final class SqlFactory
{
    private ValueFormatter $valueFormatter;

    public function __construct(ValueFormatter $valueFormatter)
    {
        $this->valueFormatter = $valueFormatter;
    }

    /** @param array<string, mixed> $parameters */
    public function createWithParameters(string $query, array $parameters) : string
    {
        /** @var mixed $value */
        foreach ($parameters as $name => $value) {
            $query = preg_replace(
                sprintf('~:%s(?!\w)~', $name),
                str_replace('\\', '\\\\', $this->valueFormatter->format($value, $name, $query)),
                $query
            );
        }

        return $query;
    }
}
