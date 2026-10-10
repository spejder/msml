<?php

declare(strict_types=1);

namespace MSML\Tests;

use Spejder\Odoo\Odoo;

/**
 * An Odoo client that answers from a callback and records all calls.
 *
 * It never talks to a server.
 */
class FakeOdoo extends Odoo
{
    /**
     * @var callable(string, string, array<mixed>): array<mixed>
     */
    protected $handler;

    /**
     * @var array<array{0: string, 1: string, 2: array<mixed>}>
     */
    public array $calls = [];

    /**
     * @param callable(string, string, array<mixed>): array<mixed> $handler
     *   Gets the method, the model and the arguments. Returns the result.
     */
    public function __construct(callable $handler)
    {
        parent::__construct('http://localhost', 'test', 'test', 'test');
        $this->handler = $handler;
    }

    public function search(string $model, array $data, int $offset = 0, int $limit = 0): array
    {
        return $this->call('search', $model, [$data, $offset, $limit]);
    }

    public function read(string $model, array $ids, array $fields = []): array
    {
        return $this->call('read', $model, [$ids, $fields]);
    }

    public function searchRead(
        string $model,
        array $data = [],
        array $fields = [],
        int $offset = 0,
        int $limit = 0
    ): array {
        return $this->call('search_read', $model, [$data, $fields, $offset, $limit]);
    }

    /**
     * Number of calls of a method on a model.
     */
    public function count(string $method, string $model): int
    {
        return count(array_filter(
            $this->calls,
            fn (array $call): bool => $call[0] === $method && $call[1] === $model
        ));
    }

    /**
     * @param array<mixed> $args
     *
     * @return array<mixed>
     */
    protected function call(string $method, string $model, array $args): array
    {
        $this->calls[] = [$method, $model, $args];

        return ($this->handler)($method, $model, $args);
    }
}
