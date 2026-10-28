<?php

/*
 * Minimal stand-in for the slice of Phinx 0.16 the legacy intraRP migrations use, so they can be
 * played against a MariaDB database without installing the legacy app. It emits MySQL DDL directly;
 * raw execute() SQL goes to PDO untouched. Loaded only by tools/legacy-schema/load.php.
 *
 * Defaults follow Phinx 0.16 (feature flags unsigned_primary_keys and column_null_default on):
 * implicit `id` is INT UNSIGNED AUTO_INCREMENT, columns are nullable unless 'null' => false.
 */

declare(strict_types=1);

namespace Phinx\Db\Adapter {
    use PDO;

    class MysqlAdapter
    {
        public const TEXT_TINY = 255;

        public const TEXT_SMALL = 255;

        public const TEXT_REGULAR = 65535;

        public const TEXT_MEDIUM = 16777215;

        public const TEXT_LONG = 4294967295;

        public const BLOB_TINY = 255;

        public const BLOB_SMALL = 255;

        public const BLOB_REGULAR = 65535;

        public const BLOB_MEDIUM = 16777215;

        public const BLOB_LONG = 4294967295;

        public const INT_TINY = 255;

        public const INT_SMALL = 65535;

        public const INT_MEDIUM = 16777215;

        public const INT_REGULAR = 4294967295;

        public const INT_BIG = 18446744073709551615;

        public function __construct(private PDO $pdo) {}

        public function getConnection(): PDO
        {
            return $this->pdo;
        }

        public function execute(string $sql, array $params = []): int
        {
            if ($params === []) {
                return (int) $this->pdo->exec($sql);
            }
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);

            return $statement->rowCount();
        }

        public function fetchRow(string $sql): array|false
        {
            return $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
        }

        public function fetchAll(string $sql): array
        {
            return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        }

        /** Rows from information_schema for the current database. */
        public function schemaRows(string $view, string $where, array $params): array
        {
            $statement = $this->pdo->prepare("SELECT * FROM information_schema.{$view} WHERE TABLE_SCHEMA = DATABASE() AND {$where}");
            $statement->execute($params);

            return $statement->fetchAll(PDO::FETCH_ASSOC);
        }

        public function hasTable(string $table): bool
        {
            return $this->schemaRows('TABLES', 'TABLE_NAME = ?', [$table]) !== [];
        }

        public function hasColumn(string $table, string $column): bool
        {
            return $this->schemaRows('COLUMNS', 'TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]) !== [];
        }
    }
}

namespace Phinx\Migration {
    use Phinx\Db\Adapter\MysqlAdapter;
    use Phinx\Db\Table;
    use RuntimeException;

    class IrreversibleMigrationException extends RuntimeException {}

    abstract class AbstractMigration
    {
        final public function __construct(private MysqlAdapter $adapter) {}

        public function getAdapter(): MysqlAdapter
        {
            return $this->adapter;
        }

        public function table(string $name, array $options = []): Table
        {
            return new Table($name, $options, $this->adapter);
        }

        public function hasTable(string $name): bool
        {
            return $this->adapter->hasTable($name);
        }

        public function execute(string $sql, array $params = []): int
        {
            return $this->adapter->execute($sql, $params);
        }

        public function fetchRow(string $sql): array|false
        {
            return $this->adapter->fetchRow($sql);
        }

        public function fetchAll(string $sql): array
        {
            return $this->adapter->fetchAll($sql);
        }
    }
}

namespace Phinx\Db {
    use Phinx\Db\Adapter\MysqlAdapter;

    class Table
    {
        /** @var list<array{0: string, 1: string}> pending [kind, sql] in call order; kind is column|index|fk|alter|statement */
        private array $pending = [];

        /** @var list<array<string, mixed>> */
        private array $rows = [];

        /** Option keys the stand-in understands; anything else throws instead of being silently ignored. */
        private const TABLE_OPTIONS = ['id', 'primary_key', 'signed', 'engine', 'collation', 'encoding', 'comment', 'row_format'];

        private const COLUMN_OPTIONS = ['limit', 'null', 'default', 'signed', 'after', 'comment', 'values', 'precision', 'scale', 'update', 'identity', 'collation', 'encoding'];

        private const INDEX_OPTIONS = ['name', 'unique', 'type', 'limit', 'order'];

        private const FOREIGN_KEY_OPTIONS = ['delete', 'update', 'constraint'];

        public function __construct(private string $name, private array $options, private MysqlAdapter $adapter)
        {
            $this->assertOptions('table()', $options, self::TABLE_OPTIONS);
        }

        public function exists(): bool
        {
            return $this->adapter->hasTable($this->name);
        }

        public function hasColumn(string $column): bool
        {
            return $this->adapter->hasColumn($this->name, $column);
        }

        public function hasIndexByName(string $index): bool
        {
            return $this->adapter->schemaRows('STATISTICS', 'TABLE_NAME = ? AND INDEX_NAME = ?', [$this->name, $index]) !== [];
        }

        public function hasForeignKey(string|array $columns): bool
        {
            return $this->foreignKeyNames((array) $columns) !== [];
        }

        public function addColumn(string $name, string $type, array $options = []): static
        {
            $this->pending[] = ['column', $this->quote($name).' '.$this->columnDefinition($type, $options)];

            return $this;
        }

        public function changeColumn(string $name, string $type, array $options = []): static
        {
            $this->pending[] = ['alter', 'MODIFY '.$this->quote($name).' '.$this->columnDefinition($type, $options)];

            return $this;
        }

        public function renameColumn(string $from, string $to): static
        {
            $this->pending[] = ['alter', 'RENAME COLUMN '.$this->quote($from).' TO '.$this->quote($to)];

            return $this;
        }

        public function removeColumn(string $name): static
        {
            $this->pending[] = ['alter', 'DROP COLUMN '.$this->quote($name)];

            return $this;
        }

        public function addIndex(string|array $columns, array $options = []): static
        {
            $this->assertOptions('addIndex()', $options, self::INDEX_OPTIONS);
            $kind = match (true) {
                ($options['type'] ?? null) === 'fulltext' => 'FULLTEXT KEY',
                ! empty($options['unique']) => 'UNIQUE KEY',
                default => 'KEY',
            };
            $name = isset($options['name']) ? ' '.$this->quote($options['name']) : '';
            $parts = [];
            foreach ((array) $columns as $column) {
                $limit = is_array($options['limit'] ?? null) ? ($options['limit'][$column] ?? null) : ($options['limit'] ?? null);
                $order = $options['order'][$column] ?? null;
                $parts[] = $this->quote($column).($limit !== null ? "({$limit})" : '').($order !== null ? ' '.strtoupper($order) : '');
            }
            $this->pending[] = ['index', "{$kind}{$name} (".implode(', ', $parts).')'];

            return $this;
        }

        public function removeIndexByName(string $name): static
        {
            $this->pending[] = ['alter', 'DROP INDEX '.$this->quote($name)];

            return $this;
        }

        public function removeIndex(string|array $columns): static
        {
            $byIndex = [];
            foreach ($this->adapter->schemaRows('STATISTICS', 'TABLE_NAME = ?', [$this->name]) as $row) {
                $byIndex[$row['INDEX_NAME']][(int) $row['SEQ_IN_INDEX']] = $row['COLUMN_NAME'];
            }
            foreach ($byIndex as $index => $indexColumns) {
                ksort($indexColumns);
                if (array_values($indexColumns) === (array) $columns) {
                    $this->removeIndexByName($index);
                }
            }

            return $this;
        }

        public function addForeignKey(string|array $columns, string $referencedTable, string|array $referencedColumns = 'id', array $options = []): static
        {
            $this->assertOptions('addForeignKey()', $options, self::FOREIGN_KEY_OPTIONS);
            $sql = isset($options['constraint']) ? 'CONSTRAINT '.$this->quote($options['constraint']).' ' : '';
            $sql .= 'FOREIGN KEY ('.$this->columnList($columns).') REFERENCES '.$this->quote($referencedTable).' ('.$this->columnList($referencedColumns).')';
            foreach (['delete' => 'ON DELETE', 'update' => 'ON UPDATE'] as $option => $clause) {
                if (isset($options[$option])) {
                    $sql .= " {$clause} ".str_replace('_', ' ', strtoupper($options[$option]));
                }
            }
            $this->pending[] = ['fk', $sql];

            return $this;
        }

        public function dropForeignKey(string|array $columns, ?string $constraint = null): static
        {
            foreach ($constraint !== null ? [$constraint] : $this->foreignKeyNames((array) $columns) as $name) {
                $this->pending[] = ['alter', 'DROP FOREIGN KEY '.$this->quote($name)];
            }

            return $this;
        }

        public function rename(string $newName): static
        {
            $this->pending[] = ['rename', $newName];

            return $this;
        }

        public function drop(): static
        {
            $this->pending[] = ['statement', 'DROP TABLE '.$this->quote($this->name)];

            return $this;
        }

        public function truncate(): void
        {
            $this->adapter->execute('TRUNCATE TABLE '.$this->quote($this->name));
        }

        public function insert(array $rows): static
        {
            $this->rows = array_merge($this->rows, array_is_list($rows) ? $rows : [$rows]);

            return $this;
        }

        public function saveData(): void
        {
            foreach ($this->rows as $row) {
                $columns = implode(', ', array_map($this->quote(...), array_keys($row)));
                $placeholders = implode(', ', array_fill(0, count($row), '?'));
                $values = array_map(fn ($value) => is_bool($value) ? (int) $value : $value, array_values($row));
                $this->adapter->execute("INSERT INTO {$this->quote($this->name)} ({$columns}) VALUES ({$placeholders})", $values);
            }
            $this->rows = [];
        }

        public function create(): void
        {
            $id = $this->options['id'] ?? 'id';
            $definitions = [];
            $primaryKey = $this->options['primary_key'] ?? null;
            if ($id !== false) {
                $id = $id === true ? 'id' : $id;
                $signed = $this->options['signed'] ?? false;
                $definitions[] = $this->quote($id).' INT'.($signed ? '' : ' UNSIGNED').' NOT NULL AUTO_INCREMENT';
                $primaryKey = $id;
            }
            $later = [];
            foreach ($this->pending as [$kind, $sql]) {
                if (in_array($kind, ['column', 'index', 'fk'], true)) {
                    $definitions[] = preg_replace('/ AFTER `[^`]+`$/', '', $sql);
                } else {
                    $later[] = [$kind, $sql];
                }
            }
            if ($primaryKey !== null) {
                $definitions[] = 'PRIMARY KEY ('.$this->columnList($primaryKey).')';
            }

            $collation = $this->options['collation'] ?? 'utf8mb4_unicode_ci';
            $sql = 'CREATE TABLE '.$this->quote($this->name)." (\n  ".implode(",\n  ", $definitions)."\n)";
            $sql .= ' ENGINE='.($this->options['engine'] ?? 'InnoDB');
            $sql .= ' CHARACTER SET '.($this->options['encoding'] ?? strtok($collation, '_')).' COLLATE '.$collation;
            if (isset($this->options['row_format'])) {
                $sql .= ' ROW_FORMAT='.$this->options['row_format'];
            }
            if (isset($this->options['comment'])) {
                $sql .= ' COMMENT '.$this->adapter->getConnection()->quote($this->options['comment']);
            }

            $this->adapter->execute($sql);
            $this->pending = $later;
            $this->update();
        }

        public function update(): void
        {
            foreach ($this->pending as [$kind, $sql]) {
                $table = $this->quote($this->name);
                $this->adapter->execute(match ($kind) {
                    'column', 'index', 'fk' => "ALTER TABLE {$table} ADD {$sql}",
                    'alter' => "ALTER TABLE {$table} {$sql}",
                    'rename' => "RENAME TABLE {$table} TO ".$this->quote($sql),
                    'statement' => $sql,
                });
                if ($kind === 'rename') {
                    $this->name = $sql;
                }
            }
            $this->pending = [];
            $this->saveData();
        }

        public function save(): void
        {
            $this->exists() ? $this->update() : $this->create();
        }

        /** @return list<string> */
        private function foreignKeyNames(array $columns): array
        {
            $byConstraint = [];
            foreach ($this->adapter->schemaRows('KEY_COLUMN_USAGE', 'TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL', [$this->name]) as $row) {
                $byConstraint[$row['CONSTRAINT_NAME']][(int) $row['ORDINAL_POSITION']] = $row['COLUMN_NAME'];
            }
            $names = [];
            foreach ($byConstraint as $name => $constraintColumns) {
                ksort($constraintColumns);
                if (array_values($constraintColumns) === $columns) {
                    $names[] = $name;
                }
            }

            return $names;
        }

        private function assertOptions(string $method, array $options, array $allowed): void
        {
            $unknown = array_diff(array_keys($options), $allowed);
            if ($unknown !== []) {
                throw new \InvalidArgumentException("{$this->name}: {$method} option(s) not supported by the Phinx stand-in: ".implode(', ', $unknown));
            }
        }

        private function columnDefinition(string $type, array $options): string
        {
            $this->assertOptions("column {$type}", $options, self::COLUMN_OPTIONS);
            $limit = $options['limit'] ?? null;
            $sql = match ($type) {
                'string' => 'VARCHAR('.($limit ?? 255).')',
                'char' => 'CHAR('.($limit ?? 255).')',
                'uuid' => 'CHAR(36)',
                'text' => match (true) {
                    $limit === null => 'TEXT',
                    $limit > MysqlAdapter::TEXT_MEDIUM => 'LONGTEXT',
                    $limit > MysqlAdapter::TEXT_REGULAR => 'MEDIUMTEXT',
                    $limit > MysqlAdapter::TEXT_TINY => 'TEXT',
                    default => 'TINYTEXT',
                },
                'integer' => match (true) {
                    $limit === null => 'INT',
                    $limit > MysqlAdapter::INT_REGULAR => 'BIGINT',
                    $limit > MysqlAdapter::INT_MEDIUM => 'INT',
                    $limit > MysqlAdapter::INT_SMALL => 'MEDIUMINT',
                    $limit > MysqlAdapter::INT_TINY => 'SMALLINT',
                    $limit === MysqlAdapter::INT_TINY => 'TINYINT',
                    default => "INT({$limit})",
                },
                'tinyinteger' => 'TINYINT',
                'smallinteger' => 'SMALLINT',
                'biginteger' => 'BIGINT',
                'boolean' => 'TINYINT(1)',
                'float' => 'FLOAT',
                'double' => 'DOUBLE',
                'decimal' => 'DECIMAL('.($options['precision'] ?? 10).','.($options['scale'] ?? 0).')',
                'datetime', 'timestamp', 'date', 'time', 'year', 'json', 'blob', 'binary' => strtoupper($type),
                'enum', 'set' => strtoupper($type).'('.implode(',', array_map(
                    fn ($value) => $this->adapter->getConnection()->quote((string) $value),
                    is_array($options['values']) ? $options['values'] : explode(',', $options['values']),
                )).')',
            };

            $numeric = in_array($type, ['integer', 'tinyinteger', 'smallinteger', 'biginteger', 'boolean', 'float', 'double', 'decimal'], true);
            if ($numeric && ! ($options['signed'] ?? true)) {
                $sql .= ' UNSIGNED';
            }
            if (isset($options['encoding'])) {
                $sql .= ' CHARACTER SET '.$options['encoding'];
            }
            if (isset($options['collation'])) {
                $sql .= ' COLLATE '.$options['collation'];
            }
            $sql .= ($options['null'] ?? true) ? ' NULL' : ' NOT NULL';
            if (! empty($options['identity'])) {
                $sql .= ' AUTO_INCREMENT';
            }
            if (array_key_exists('default', $options)) {
                $sql .= ' DEFAULT '.$this->defaultValue($options['default']);
            }
            if (isset($options['update'])) {
                $sql .= ' ON UPDATE '.$options['update'];
            }
            if (isset($options['comment'])) {
                $sql .= ' COMMENT '.$this->adapter->getConnection()->quote($options['comment']);
            }
            if (isset($options['after'])) {
                $sql .= ' AFTER '.$this->quote($options['after']);
            }

            return $sql;
        }

        private function defaultValue(mixed $value): string
        {
            return match (true) {
                $value === null => 'NULL',
                is_bool($value) => (string) (int) $value,
                is_int($value), is_float($value) => (string) $value,
                in_array(strtoupper($value), ['CURRENT_TIMESTAMP', 'CURRENT_TIMESTAMP()'], true) => 'CURRENT_TIMESTAMP',
                default => $this->adapter->getConnection()->quote($value),
            };
        }

        private function columnList(string|array $columns): string
        {
            return implode(', ', array_map($this->quote(...), (array) $columns));
        }

        private function quote(string $identifier): string
        {
            return '`'.str_replace('`', '``', $identifier).'`';
        }
    }
}

namespace App\Documents\Editor {
    /**
     * Stub for the legacy app class two migrations import. Its real content is seed data for
     * document templates, not schema, so the stub leaves those seeds empty.
     */
    final class SystemTemplates
    {
        public const LEHRGAENGE = [];

        public const FACHDIENSTE = [];

        public static function all(): array
        {
            return [];
        }
    }
}
