<?php

declare(strict_types=1);

namespace SimPod\ClickHouseClient\Output;

/**
 * @psalm-immutable
 * @template T
 * @implements Output<T>
 */
final class TabSeparated implements Output
{
    public string $contents;

    public function __construct(string $contents)
    {
        $this->contents = $contents;
    }
}
