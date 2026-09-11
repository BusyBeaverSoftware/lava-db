<?php

declare(strict_types=1);

namespace Lava\Db\Schema;

/**
 * One column, and the modifiers that describe it.
 *
 * The modifiers mutate and return `$this` so a declaration reads as one line
 * — `$t->string('email')->unique()` — and the state they set is public,
 * because the compiler reads it directly. A getter per flag would be twelve
 * methods that only ever return what the property already says; this pack
 * favours the shorter honest thing over the ceremonial one.
 *
 * `default()` and `defaultExpression()` are two methods rather than one
 * because they are two different claims. `default(true)` binds a value and
 * renders it as a literal, safely. `defaultExpression('CURRENT_TIMESTAMP')`
 * puts SQL into the DDL verbatim, and is the caller saying "I know this is
 * SQL" — the same split, and the same reasoning, as `where()` versus
 * `whereRaw()`.
 */
final class ColumnDef
{
    public bool $nullable = false;

    public bool $primary = false;

    public bool $unique = false;

    public bool $autoIncrement = false;

    /**
     * The column default, tagged with how to render it: `literal` goes
     * through the dialect's escaping, `sql` is written out verbatim.
     *
     * @var array{kind: 'literal'|'sql', value: mixed}|null
     */
    public ?array $default = null;

    public ?string $referencesTable = null;

    public string $referencesColumn = 'id';

    public ?ForeignAction $onDelete = null;

    public ?ForeignAction $onUpdate = null;

    public function __construct(
        public readonly string $name,
        public readonly ColumnType $type,
        public readonly ?int $length = null,
        public readonly ?int $precision = null,
        public readonly ?int $scale = null,
    ) {
    }

    public function nullable(bool $nullable = true): self
    {
        $this->nullable = $nullable;
        return $this;
    }

    /** A default value. Rendered as a SQL literal through the dialect's escaping. */
    public function default(mixed $value): self
    {
        $this->default = ['kind' => 'literal', 'value' => $value];
        return $this;
    }

    /**
     * A default written as SQL, verbatim — `CURRENT_TIMESTAMP`, `now()`,
     * `gen_random_uuid()`. Nothing about the string is checked or quoted,
     * which is exactly why it is a separate method.
     */
    public function defaultExpression(string $sql): self
    {
        $this->default = ['kind' => 'sql', 'value' => $sql];
        return $this;
    }

    public function primary(): self
    {
        $this->primary = true;
        return $this;
    }

    /** Shorthand for a single-column unique index; the compiler names it. */
    public function unique(): self
    {
        $this->unique = true;
        return $this;
    }

    public function autoIncrement(): self
    {
        $this->autoIncrement = true;
        return $this;
    }

    /** Declares a foreign key. The action defaults to RESTRICT — see {@see ForeignAction}. */
    public function references(string $table, string $column = 'id'): self
    {
        $this->referencesTable = $table;
        $this->referencesColumn = $column;
        return $this;
    }

    public function onDelete(ForeignAction $action): self
    {
        $this->onDelete = $action;
        return $this;
    }

    public function onUpdate(ForeignAction $action): self
    {
        $this->onUpdate = $action;
        return $this;
    }

    /**
     * The name of the foreign-key constraint a reference compiles into, e.g.
     * `posts_user_id_foreign`. Deterministic and derived from the table and
     * column, so a later migration can drop the constraint without having to
     * ask the database what it called it — the same reasoning as
     * {@see Index::defaultName()}.
     */
    public static function foreignKeyName(string $table, string $column): string
    {
        return $table . '_' . $column . '_foreign';
    }
}
