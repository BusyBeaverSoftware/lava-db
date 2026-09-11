<?php

declare(strict_types=1);

namespace Lava\Db\Query;

use Lava\Db\Problem\BadQuery;

/**
 * An INSERT, one or many rows.
 *
 * `of()` enforces the one invariant the compiler relies on: every row sets
 * the same columns, in the same order. The compiler emits a single column
 * list and then flattens the rows, so a row that set a different column
 * would silently land in the wrong column — a data corruption that no
 * constraint would catch. Comparing the key lists is the whole check.
 */
final class InsertQuery extends Query
{
    /**
     * @param non-empty-list<array<string, int|float|string|null>> $rows
     */
    private function __construct(
        string $table,
        public readonly array $rows,
    ) {
        parent::__construct($table);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @throws BadQuery when the rows are empty or disagree about their columns
     */
    public static function of(string $table, array $rows): self
    {
        if ($rows === []) {
            throw BadQuery::emptyWrite('insert', $table);
        }

        /** @var list<string>|null $columns */
        $columns = null;
        $normalized = [];
        foreach ($rows as $row) {
            if ($row === []) {
                throw BadQuery::emptyWrite('insert', $table);
            }
            $keys = array_keys($row);
            if ($columns === null) {
                $columns = $keys;
            } elseif ($keys !== $columns) {
                throw BadQuery::mixedColumns($columns, $keys);
            }
            $normalized[] = Bindings::map($row);
        }

        return new self($table, $normalized);
    }
}
