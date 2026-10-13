<?php

namespace DatabaseStudio\Services;

use Closure;
use DatabaseStudio\Exports\SpreadsheetFormulaEscaper;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use PDO;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Models\Database;
use Pterodactyl\Services\Extensions\ExtensionSettings;
use Throwable;

class DatabaseStudioService
{
    private const READ_KEYWORDS = ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'];

    private const IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    private const COLUMN_TYPES = [
        'integer' => 'INT',
        'big_integer' => 'BIGINT',
        'decimal' => 'DECIMAL(10,2)',
        'short_text' => 'VARCHAR(191)',
        'long_text' => 'TEXT',
        'boolean' => 'TINYINT(1)',
        'datetime' => 'DATETIME',
        'timestamp' => 'TIMESTAMP',
    ];

    private const AUTO_INCREMENT_TYPES = ['integer', 'big_integer'];

    private const FORBIDDEN_PATTERNS = [
        '/\bINTO\s+(OUTFILE|DUMPFILE)\b/i',
        '/\bLOAD\s+DATA\s+LOCAL\s+INFILE\b/i',
        '/\bLOAD_FILE\s*\(/i',
    ];

    public function __construct(
        private ConfigRepository $config,
        private Encrypter $encrypter,
        private DatabaseManager $database,
        private ExtensionSettings $settings
    ) {}

    public function buildConnectionConfig(Database $database): array
    {
        $database->loadMissing('host');

        return [
            'driver' => 'mysql',
            'host' => $database->host->host,
            'port' => $database->host->port,
            'database' => $database->database,
            'username' => $database->username,
            'password' => $this->encrypter->decrypt($database->password),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => false,
            'options' => [
                PDO::ATTR_TIMEOUT => $this->connectTimeoutSeconds(),
                (defined('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY') ? constant('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY') : constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) => false,
            ],
        ];
    }

    public function schema(Database $database): array
    {
        return $this->withConnection($database, function (Connection $connection) use ($database) {
            $tables = collect($this->listTables($connection))
                ->map(function (array $table) use ($connection) {
                    $metadata = $this->tableMetadata($connection, $table['name']);

                    return [
                        'name' => $table['name'],
                        'type' => $table['type'],
                        'columns' => $metadata['columns'],
                        'primary_key' => $metadata['primary_key'],
                    ];
                })
                ->values()
                ->all();

            return [
                'database' => $database->database,
                'tables' => $tables,
                'limits' => [
                    'show_technical_limits' => (bool) $this->settings->get('show_technical_limits', true),
                    'max_query_bytes' => $this->maxQueryBytes(),
                    'max_import_bytes' => $this->maxImportBytes(),
                    'max_result_rows' => $this->maxResultRows(),
                    'max_export_rows' => $this->maxExportRows(),
                    'default_page_size' => $this->defaultPageSize(),
                    'max_page_size' => $this->maxPageSize(),
                    ...$this->runtimeLimits(),
                ],
            ];
        });
    }

    public function rows(Database $database, string $table, array $options = []): array
    {
        return $this->withConnection($database, function (Connection $connection) use ($table, $options) {
            $metadata = $this->assertTableExists($connection, $table);
            $columns = collect($metadata['columns'])->pluck('name')->all();

            $page = max(1, (int) ($options['page'] ?? 1));
            $perPage = min($this->maxPageSize(), max(1, (int) ($options['per_page'] ?? $this->defaultPageSize())));
            $offset = ($page - 1) * $perPage;
            $orderBy = $options['order_by'] ?? ($metadata['primary_key'][0] ?? null);
            $orderDirection = strtolower((string) ($options['order_direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

            if ($orderBy !== null && ! in_array($orderBy, $columns, true)) {
                throw new DisplayException('The selected order column does not exist on this table.');
            }

            $total = $this->countTableRows($connection, $table);
            $query = $this->tableQuery($connection, $table);

            if ($orderBy !== null) {
                $query->orderBy($orderBy, $orderDirection);
            }

            return [
                'table' => $table,
                'columns' => $metadata['columns'],
                'primary_key' => $metadata['primary_key'],
                'rows' => $this->normalizeRows($query->limit($perPage)->offset($offset)->get()->all()),
                'pagination' => [
                    'total' => $total,
                    'count' => min($perPage, max(0, $total - $offset)),
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'total_pages' => max(1, (int) ceil($total / $perPage)),
                ],
            ];
        });
    }

    public function execute(Database $database, string $sql): array
    {
        $statement = $this->singleStatement($sql);
        $this->assertQuerySize($statement);
        $this->assertStatementAllowed($statement);

        return $this->withConnection($database, function (Connection $connection) use ($statement) {
            try {
                if ($this->isReadStatement($statement)) {
                    $rows = $this->readRows($connection, $statement);

                    return [
                        'type' => 'read',
                        'statement_type' => $this->statementKeyword($statement),
                        'columns' => $this->columnsFromRows($rows),
                        'rows' => array_slice($rows, 0, $this->maxResultRows()),
                        'row_count' => count($rows),
                        'affected_rows' => 0,
                    ];
                }

                $affected = $connection->affectingStatement($statement);

                return [
                    'type' => 'write',
                    'statement_type' => $this->statementKeyword($statement),
                    'columns' => [],
                    'rows' => [],
                    'row_count' => 0,
                    'affected_rows' => $affected,
                ];
            } catch (QueryException|\PDOException $exception) {
                throw $this->safeDatabaseException($exception);
            }
        }, readOnly: $this->isReadStatement($statement));
    }

    public function import(Database $database, string $sql): array
    {
        $this->assertImportSize($sql);
        $statements = $this->splitStatements($sql);

        if (empty($statements)) {
            throw new DisplayException('The SQL import did not contain any executable statements.');
        }

        foreach ($statements as $statement) {
            $this->assertStatementAllowed($statement);
        }

        return $this->withConnection($database, function (Connection $connection) use ($statements) {
            $affectedRows = 0;
            $writeStatements = 0;

            try {
                foreach ($statements as $statement) {
                    if ($this->isReadStatement($statement)) {
                        $this->readRows($connection, $statement);

                        continue;
                    }

                    $writeStatements++;
                    $affectedRows += $connection->affectingStatement($statement);
                }
            } catch (QueryException|\PDOException $exception) {
                throw $this->safeDatabaseException($exception);
            }

            return [
                'statements' => count($statements),
                'write_statements' => $writeStatements,
                'affected_rows' => $affectedRows,
            ];
        }, readOnly: ! $this->importRequiresWrite($sql));
    }

    public function createTable(Database $database, array $payload): array
    {
        $name = $this->validatedIdentifier($payload['name'] ?? null, 'Table names');
        $columns = $this->validatedTableColumns($payload['columns'] ?? []);

        return $this->withConnection($database, function (Connection $connection) use ($name, $columns) {
            $tables = collect($this->listTables($connection))->pluck('name')->all();
            if (in_array($name, $tables, true)) {
                throw new DisplayException('A table with that name already exists.');
            }

            $definitions = collect($columns)
                ->map(fn (array $column) => $this->columnDefinitionSql($connection, $column))
                ->all();

            $primaryKey = collect($columns)
                ->filter(fn (array $column) => $column['primary_key'])
                ->pluck('name')
                ->all();

            if (! empty($primaryKey)) {
                $definitions[] = sprintf(
                    'PRIMARY KEY (%s)',
                    implode(', ', array_map(fn (string $column) => $this->quoteIdentifier($connection, $column), $primaryKey))
                );
            }

            try {
                $connection->statement(sprintf(
                    "CREATE TABLE %s (\n    %s\n)",
                    $this->quoteTable($connection, $name),
                    implode(",\n    ", $definitions)
                ));
            } catch (QueryException|\PDOException $exception) {
                throw $this->safeDatabaseException($exception);
            }

            return [
                'table' => $name,
                'affected_rows' => 0,
            ];
        });
    }

    public function insertRow(Database $database, string $table, array $values): array
    {
        return $this->withConnection($database, function (Connection $connection) use ($table, $values) {
            $metadata = $this->assertTableExists($connection, $table);
            $values = $this->validatedColumnValues($metadata, $values);

            if (empty($values)) {
                throw new DisplayException('At least one column value is required.');
            }

            try {
                $connection->table($table)->insert($values);
            } catch (QueryException|\PDOException $exception) {
                throw $this->safeDatabaseException($exception);
            }

            return ['affected_rows' => 1];
        });
    }

    public function updateRow(Database $database, string $table, array $key, array $values): array
    {
        return $this->withConnection($database, function (Connection $connection) use ($table, $key, $values) {
            $metadata = $this->assertTableExists($connection, $table);
            $this->assertPrimaryKey($metadata);
            $key = $this->validatedPrimaryKey($metadata, $key);
            $values = $this->validatedColumnValues($metadata, $values, $metadata['primary_key']);

            if (empty($values)) {
                throw new DisplayException('At least one non-primary-key value is required.');
            }

            try {
                $query = $connection->table($table);
                foreach ($key as $column => $value) {
                    $query->where($column, $value);
                }

                return ['affected_rows' => $query->update($values)];
            } catch (QueryException|\PDOException $exception) {
                throw $this->safeDatabaseException($exception);
            }
        });
    }

    public function deleteRow(Database $database, string $table, array $key): array
    {
        return $this->withConnection($database, function (Connection $connection) use ($table, $key) {
            $metadata = $this->assertTableExists($connection, $table);
            $this->assertPrimaryKey($metadata);
            $key = $this->validatedPrimaryKey($metadata, $key);

            try {
                $query = $connection->table($table);
                foreach ($key as $column => $value) {
                    $query->where($column, $value);
                }

                return ['affected_rows' => $query->delete()];
            } catch (QueryException|\PDOException $exception) {
                throw $this->safeDatabaseException($exception);
            }
        });
    }

    public function export(Database $database, ?string $table, string $format): array
    {
        return $this->withConnection($database, function (Connection $connection) use ($database, $table, $format) {
            if ($table) {
                $this->assertDumpIdentifierSafe($table);
                $tables = [$this->assertTableExists($connection, $table)['name']];
            } else {
                $tables = collect($this->listTables($connection))->pluck('name')->all();
                $this->assertDumpIdentifiersSafe($tables);
            }

            $rowCount = $this->totalRowsForExport($connection, $tables);

            if ($rowCount > $this->maxExportRows()) {
                throw new DisplayException(sprintf(
                    'This export contains %d rows, which exceeds the configured export limit of %d rows.',
                    $rowCount,
                    $this->maxExportRows()
                ));
            }

            if ($format === 'csv') {
                if (count($tables) !== 1) {
                    throw new DisplayException('CSV export requires a single table.');
                }

                return [
                    'filename' => sprintf('%s-%s.csv', $database->database, $tables[0]),
                    'content_type' => 'text/csv',
                    'row_count' => $rowCount,
                    'content' => $this->exportCsv($connection, $tables[0]),
                ];
            }

            $content = $this->exportSql($connection, $tables);
            if ($format === 'sql.gz') {
                if (! function_exists('gzencode')) {
                    throw new DisplayException('SQL.gz export is not available on this panel.');
                }

                $content = gzencode($content);
                if ($content === false) {
                    throw new DisplayException('Database export failed while compressing the SQL dump.');
                }
            }

            return [
                'filename' => sprintf('%s.%s', $table ?: $database->database, $format),
                'content_type' => $format === 'sql.gz' ? 'application/gzip' : 'application/sql',
                'row_count' => $rowCount,
                'content' => $content,
            ];
        });
    }

    public function statementRequiresWrite(string $sql): bool
    {
        return ! $this->isReadStatement($this->singleStatement($sql));
    }

    public function importRequiresWrite(string $sql): bool
    {
        foreach ($this->splitStatements($sql) as $statement) {
            if (! $this->isReadStatement($statement)) {
                return true;
            }
        }

        return false;
    }

    public function isReadStatement(string $sql): bool
    {
        if ($this->containsExecutableComment($sql)) {
            return false;
        }

        $normalizedSql = $this->sqlWithoutComments($sql);
        foreach (self::FORBIDDEN_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalizedSql)) {
                return false;
            }
        }

        return in_array($this->statementKeyword($sql), self::READ_KEYWORDS, true);
    }

    public function singleStatement(string $sql): string
    {
        $statements = $this->splitStatements($sql);

        if (count($statements) !== 1) {
            throw new DisplayException('Run one SQL statement at a time. Use the import tab for SQL scripts.');
        }

        return $statements[0];
    }

    public function splitStatements(string $sql): array
    {
        $sql = trim(Str::of($sql)->ltrim("\xEF\xBB\xBF")->toString());
        if ($sql === '') {
            return [];
        }

        $statements = [];
        $current = '';
        $quote = null;
        $lineComment = false;
        $blockComment = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';
            $afterNext = $i + 2 < $length ? $sql[$i + 2] : '';

            if ($lineComment) {
                $current .= $char;
                if ($char === "\n") {
                    $lineComment = false;
                }

                continue;
            }

            if ($blockComment) {
                $current .= $char;
                if ($char === '*' && $next === '/') {
                    $current .= $next;
                    $i++;
                    $blockComment = false;
                }

                continue;
            }

            if ($quote !== null) {
                $current .= $char;

                if ($char === '\\' && $quote !== '`' && $next !== '') {
                    $current .= $next;
                    $i++;

                    continue;
                }

                if ($char === $quote) {
                    if ($quote !== '`' && $next === $quote) {
                        $current .= $next;
                        $i++;

                        continue;
                    }

                    $quote = null;
                }

                continue;
            }

            if (in_array($char, ["'", '"', '`'], true)) {
                $quote = $char;
                $current .= $char;

                continue;
            }

            if ($char === '-' && $next === '-' && ($afterNext === '' || ctype_space($afterNext))) {
                $lineComment = true;
                $current .= $char.$next;
                $i++;

                continue;
            }

            if ($char === '#') {
                $lineComment = true;
                $current .= $char;

                continue;
            }

            if ($char === '/' && $next === '*') {
                $blockComment = true;
                $current .= $char.$next;
                $i++;

                continue;
            }

            if ($char === ';') {
                $statement = trim($current);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $current = '';

                continue;
            }

            $current .= $char;
        }

        if ($quote !== null || $blockComment) {
            throw new DisplayException('The SQL statement contains an unterminated string or comment.');
        }

        $statement = trim($current);
        if ($statement !== '') {
            $statements[] = $statement;
        }

        return $statements;
    }

    public function statementKeyword(string $sql): string
    {
        $sql = $this->stripLeadingComments($sql);

        return preg_match('/^([a-z_]+)/i', $sql, $matches) ? strtoupper($matches[1]) : '';
    }

    public function redactedSqlPreview(string $sql): string
    {
        $preview = preg_replace(["/'(?:''|\\\\'|[^'])*'/", '/"(?:\\\\.|[^"])*"/', '/\s+/'], ['?', '?', ' '], trim($sql));

        return Str::limit((string) $preview, 120, '');
    }

    private function withConnection(Database $database, Closure $callback, bool $readOnly = false): mixed
    {
        $name = $this->connectionName($database);
        $this->config->set("database.connections.{$name}", $this->buildConnectionConfig($database));
        $this->database->purge($name);

        try {
            $connection = $this->database->connection($name);
            if ($readOnly) {
                // Enforce read permission in MySQL as well: SELECT can invoke a stored
                // function with side effects, so keyword classification alone is insufficient.
                $connection->statement('SET SESSION TRANSACTION READ ONLY');
            }

            return $callback($connection);
        } finally {
            $this->database->purge($name);
            $this->config->set("database.connections.{$name}", null);
        }
    }

    private function connectionName(Database $database): string
    {
        return 'ext_database_studio_'.$database->id;
    }

    private function listTables(Connection $connection): array
    {
        try {
            return collect($connection->select('SHOW FULL TABLES'))
                ->map(function (object $row) {
                    $values = array_values((array) $row);

                    return [
                        'name' => (string) ($values[0] ?? ''),
                        'type' => strtolower((string) ($values[1] ?? 'base table')),
                    ];
                })
                ->filter(fn (array $table) => $table['name'] !== '')
                ->values()
                ->all();
        } catch (QueryException|\PDOException $exception) {
            throw $this->safeDatabaseException($exception);
        }
    }

    private function tableMetadata(Connection $connection, string $table): array
    {
        try {
            $columns = collect($connection->select('SHOW FULL COLUMNS FROM '.$this->quoteTable($connection, $table)))
                ->map(fn (object $column) => [
                    'name' => (string) $column->Field,
                    'type' => (string) $column->Type,
                    'nullable' => strtoupper((string) $column->Null) === 'YES',
                    'default' => $column->Default,
                    'key' => (string) $column->Key,
                    'extra' => (string) $column->Extra,
                    'comment' => (string) $column->Comment,
                ])
                ->values()
                ->all();
        } catch (QueryException|\PDOException $exception) {
            throw $this->safeDatabaseException($exception);
        }

        return [
            'name' => $table,
            'columns' => $columns,
            'primary_key' => collect($columns)
                ->filter(fn (array $column) => $column['key'] === 'PRI')
                ->pluck('name')
                ->values()
                ->all(),
        ];
    }

    private function assertTableExists(Connection $connection, string $table): array
    {
        $tables = collect($this->listTables($connection))->pluck('name')->all();
        if (! in_array($table, $tables, true)) {
            throw new DisplayException('The selected table does not exist.');
        }

        return $this->tableMetadata($connection, $table);
    }

    private function validatedIdentifier(mixed $value, string $label): string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 64 || ! preg_match(self::IDENTIFIER_PATTERN, $value)) {
            throw new DisplayException($label.' may only contain letters, numbers, and underscores, and must start with a letter or underscore.');
        }

        return $value;
    }

    private function validatedTableColumns(array $columns): array
    {
        if (empty($columns)) {
            throw new DisplayException('At least one column is required.');
        }

        if (count($columns) > 50) {
            throw new DisplayException('Tables cannot be created with more than 50 columns from Database Studio.');
        }

        $seen = [];
        $cleaned = [];

        foreach ($columns as $column) {
            if (! is_array($column)) {
                throw new DisplayException('Each column must be an object.');
            }

            $name = $this->validatedIdentifier($column['name'] ?? null, 'Column names');
            if (isset($seen[$name])) {
                throw new DisplayException('Column names must be unique.');
            }
            $seen[$name] = true;

            $type = (string) ($column['type'] ?? '');
            if (! array_key_exists($type, self::COLUMN_TYPES)) {
                throw new DisplayException('The selected column type is not supported.');
            }

            $primaryKey = (bool) ($column['primary_key'] ?? false);
            $autoIncrement = (bool) ($column['auto_increment'] ?? false);
            $nullable = (bool) ($column['nullable'] ?? false);

            if ($primaryKey) {
                $nullable = false;
            }

            if ($autoIncrement) {
                $primaryKey = true;
                $nullable = false;

                if (! in_array($type, self::AUTO_INCREMENT_TYPES, true)) {
                    throw new DisplayException('Auto-increment columns must use an integer type.');
                }
            }

            $cleaned[] = [
                'name' => $name,
                'type' => $type,
                'nullable' => $nullable,
                'primary_key' => $primaryKey,
                'auto_increment' => $autoIncrement,
            ];
        }

        $primaryKeyColumns = collect($cleaned)->filter(fn (array $column) => $column['primary_key'])->count();
        $autoIncrementColumns = collect($cleaned)->filter(fn (array $column) => $column['auto_increment'])->count();

        if ($primaryKeyColumns > 1) {
            throw new DisplayException('Database Studio table creation supports one primary key column.');
        }

        if ($autoIncrementColumns > 1) {
            throw new DisplayException('Only one auto-increment column is allowed.');
        }

        return $cleaned;
    }

    private function columnDefinitionSql(Connection $connection, array $column): string
    {
        $type = self::COLUMN_TYPES[$column['type']];
        if ($column['auto_increment']) {
            $type .= ' UNSIGNED';
        }

        return sprintf(
            '%s %s %s%s',
            $this->quoteIdentifier($connection, $column['name']),
            $type,
            $column['nullable'] ? 'NULL' : 'NOT NULL',
            $column['auto_increment'] ? ' AUTO_INCREMENT' : ''
        );
    }

    private function validatedColumnValues(array $metadata, array $values, array $disallowedColumns = []): array
    {
        $columns = collect($metadata['columns'])->pluck('name')->all();
        $cleaned = [];

        foreach ($values as $column => $value) {
            if (! is_string($column) || ! in_array($column, $columns, true)) {
                throw new DisplayException('The submitted row includes an unknown column.');
            }

            if (in_array($column, $disallowedColumns, true)) {
                throw new DisplayException('Primary key columns cannot be changed from the row editor.');
            }

            if (! is_null($value) && ! is_scalar($value)) {
                throw new DisplayException('Row values must be scalar values or null.');
            }

            $cleaned[$column] = $value;
        }

        return $cleaned;
    }

    private function assertPrimaryKey(array $metadata): void
    {
        if (empty($metadata['primary_key'])) {
            throw new DisplayException('This table does not have a primary key and cannot be edited safely from the row editor.');
        }
    }

    private function validatedPrimaryKey(array $metadata, array $key): array
    {
        $cleaned = [];

        foreach ($metadata['primary_key'] as $column) {
            if (! array_key_exists($column, $key)) {
                throw new DisplayException('The submitted row key is missing a primary key column.');
            }

            if (! is_null($key[$column]) && ! is_scalar($key[$column])) {
                throw new DisplayException('Primary key values must be scalar values or null.');
            }

            $cleaned[$column] = $key[$column];
        }

        return $cleaned;
    }

    private function quoteTable(Connection $connection, string $table): string
    {
        return $connection->getQueryGrammar()->wrapTable($table);
    }

    private function quoteIdentifier(Connection $connection, string $identifier): string
    {
        return $connection->getQueryGrammar()->wrap($identifier);
    }

    private function tableQuery(Connection $connection, string $table): QueryBuilder
    {
        return $connection->query()->from($table);
    }

    private function countTableRows(Connection $connection, string $table): int
    {
        return (int) $this->tableQuery($connection, $table)->count();
    }

    private function selectTableRows(Connection $connection, string $table): array
    {
        return $this->normalizeRows($this->tableQuery($connection, $table)->get()->all());
    }

    private function normalizeRows(array $rows): array
    {
        return array_map(fn ($row) => (array) $row, $rows);
    }

    private function columnsFromRows(array $rows): array
    {
        return empty($rows) ? [] : array_map(strval(...), array_keys($rows[0]));
    }

    private function readRows(Connection $connection, string $sql): array
    {
        $statement = $connection->getPdo()->prepare($this->limitReadStatement($sql));
        $statement->execute();
        $rows = [];
        try {
            while (count($rows) < $this->maxResultRows() && ($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $rows[] = $row;
            }
        } finally {
            $statement->closeCursor();
        }

        return $rows;
    }

    /** Runtime diagnostics for both native admin settings and the server workspace. */
    public function runtimeLimits(): array
    {
        $upload = $this->iniBytes('upload_max_filesize');
        $post = $this->iniBytes('post_max_size');
        $bounded = fn (array $values): int => min(array_filter($values, fn ($value) => $value !== null));

        return [
            'limit_notice' => trim((string) $this->settings->get('limit_notice', '')),
            'max_upload_bytes' => $bounded([$this->maxImportBytes(), $upload, $post]),
            'max_text_import_bytes' => $bounded([$this->maxImportBytes(), $post]),
            'php_upload_max_filesize_bytes' => $upload,
            'php_post_max_size_bytes' => $post,
            'php_memory_limit_bytes' => $this->iniBytes('memory_limit'),
            'php_max_execution_time_seconds' => max(0, (int) ini_get('max_execution_time')),
        ];
    }

    private function iniBytes(string $key): ?int
    {
        $value = trim((string) ini_get($key));
        if ($value === '' || (float) $value <= 0) {
            return null;
        }
        $suffix = strtolower(substr($value, -1));
        $multiplier = match ($suffix) {
            'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1
        };

        return (int) ((float) $value * $multiplier);
    }

    private function limitReadStatement(string $sql): string
    {
        if ($this->statementKeyword($sql) !== 'SELECT' || preg_match('/\blimit\s+\d+/i', $sql)) {
            return $sql;
        }

        return rtrim($sql, " \t\n\r\0\x0B;").' LIMIT '.$this->maxResultRows();
    }

    private function assertQuerySize(string $sql): void
    {
        if (strlen($sql) > $this->maxQueryBytes()) {
            throw new DisplayException(sprintf('SQL queries cannot exceed %d bytes.', $this->maxQueryBytes()));
        }
    }

    private function assertImportSize(string $sql): void
    {
        if (strlen($sql) > $this->maxImportBytes()) {
            throw new DisplayException(sprintf('SQL imports cannot exceed %d bytes.', $this->maxImportBytes()));
        }
    }

    private function assertStatementAllowed(string $sql): void
    {
        if ($this->containsExecutableComment($sql)) {
            throw new DisplayException('Executable SQL comments are not allowed from Database Studio.');
        }

        $normalizedSql = $this->sqlWithoutComments($sql);
        foreach (self::FORBIDDEN_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalizedSql)) {
                throw new DisplayException('File-system SQL operations are not allowed from Database Studio.');
            }
        }
    }

    private function sqlWithoutComments(string $sql): string
    {
        $result = '';
        $quote = null;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';
            $afterNext = $i + 2 < $length ? $sql[$i + 2] : '';

            if ($quote !== null) {
                $result .= $char;

                if ($char === '\\' && $quote !== '`' && $next !== '') {
                    $result .= $next;
                    $i++;

                    continue;
                }

                if ($char === $quote) {
                    if ($quote !== '`' && $next === $quote) {
                        $result .= $next;
                        $i++;

                        continue;
                    }

                    $quote = null;
                }

                continue;
            }

            if (in_array($char, ["'", '"', '`'], true)) {
                $quote = $char;
                $result .= $char;

                continue;
            }

            if ($char === '-' && $next === '-' && ($afterNext === '' || ctype_space($afterNext))) {
                $result .= ' ';
                $i += 2;
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }
                if ($i < $length) {
                    $result .= "\n";
                }

                continue;
            }

            if ($char === '#') {
                $result .= ' ';
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }
                if ($i < $length) {
                    $result .= "\n";
                }

                continue;
            }

            if ($char === '/' && $next === '*') {
                $result .= ' ';
                $i += 2;
                while ($i + 1 < $length && ! ($sql[$i] === '*' && $sql[$i + 1] === '/')) {
                    $i++;
                }
                if ($i + 1 < $length) {
                    $i++;
                }

                continue;
            }

            $result .= $char;
        }

        return $result;
    }

    private function containsExecutableComment(string $sql): bool
    {
        $quote = null;
        $lineComment = false;
        $blockComment = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';
            $afterNext = $i + 2 < $length ? $sql[$i + 2] : '';

            if ($lineComment) {
                if ($char === "\n") {
                    $lineComment = false;
                }

                continue;
            }

            if ($blockComment) {
                if ($char === '*' && $next === '/') {
                    $blockComment = false;
                    $i++;
                }

                continue;
            }

            if ($quote !== null) {
                if ($char === '\\' && $quote !== '`' && $next !== '') {
                    $i++;

                    continue;
                }

                if ($char === $quote) {
                    if ($quote !== '`' && $next === $quote) {
                        $i++;

                        continue;
                    }

                    $quote = null;
                }

                continue;
            }

            if (in_array($char, ["'", '"', '`'], true)) {
                $quote = $char;

                continue;
            }

            if ($char === '-' && $next === '-' && ($afterNext === '' || ctype_space($afterNext))) {
                $lineComment = true;
                $i++;

                continue;
            }

            if ($char === '#') {
                $lineComment = true;

                continue;
            }

            if ($char === '/' && $next === '*') {
                if ($afterNext === '!' || (strtoupper($afterNext) === 'M' && ($sql[$i + 3] ?? '') === '!')) {
                    return true;
                }

                $blockComment = true;
                $i++;
            }
        }

        return false;
    }

    private function stripLeadingComments(string $sql): string
    {
        $sql = ltrim($sql);

        while ($sql !== '') {
            if (str_starts_with($sql, '/*')) {
                $end = strpos($sql, '*/');
                if ($end === false) {
                    return $sql;
                }

                $sql = ltrim(substr($sql, $end + 2));

                continue;
            }

            if (str_starts_with($sql, '#')) {
                $end = strpos($sql, "\n");
                $sql = $end === false ? '' : ltrim(substr($sql, $end + 1));

                continue;
            }

            if (str_starts_with($sql, '--') && (strlen($sql) === 2 || ctype_space($sql[2]))) {
                $end = strpos($sql, "\n");
                $sql = $end === false ? '' : ltrim(substr($sql, $end + 1));

                continue;
            }

            break;
        }

        return $sql;
    }

    private function totalRowsForExport(Connection $connection, array $tables): int
    {
        $total = 0;
        foreach ($tables as $table) {
            $total += $this->countTableRows($connection, $table);
        }

        return $total;
    }

    private function exportCsv(Connection $connection, string $table): string
    {
        $metadata = $this->tableMetadata($connection, $table);
        $rows = $this->selectTableRows($connection, $table);
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, SpreadsheetFormulaEscaper::escapeRow(collect($metadata['columns'])->pluck('name')->all()), escape: '');
        foreach ($rows as $row) {
            fputcsv($handle, SpreadsheetFormulaEscaper::escapeRow($row), escape: '');
        }

        rewind($handle);

        return stream_get_contents($handle) ?: '';
    }

    private function exportSql(Connection $connection, array $tables): string
    {
        $output = "-- Database Studio export\nSET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $table) {
            $quotedTable = $this->quoteDumpTable($connection, $table);
            $metadata = $this->tableMetadata($connection, $table);
            $createRow = (array) $connection->selectOne("SHOW CREATE TABLE {$quotedTable}");
            $createSql = (string) ($createRow['Create Table'] ?? array_values($createRow)[1] ?? '');

            $output .= "DROP TABLE IF EXISTS {$quotedTable};\n{$createSql};\n\n";

            $rows = $this->selectTableRows($connection, $table);
            foreach ($rows as $row) {
                $output .= $this->sqlDumpInsertStatement($connection, $quotedTable, $metadata, $row)."\n";
            }

            $output .= "\n";
        }

        return $output."SET FOREIGN_KEY_CHECKS=1;\n";
    }

    private function sqlDumpInsertStatement(Connection $connection, string $quotedTable, array $metadata, array $row): string
    {
        return sprintf(
            'INSERT INTO %s (%s) VALUES (%s);',
            // nosemgrep: SQL dump output only; the identifier is metadata-backed, validated, and grammar-wrapped above.
            $quotedTable,
            implode(', ', $this->sqlDumpColumns($connection, $metadata, array_keys($row))),
            implode(', ', array_map(fn ($value) => $this->quoteValue($connection, $value), array_values($row)))
        );
    }

    private function sqlDumpColumns(Connection $connection, array $metadata, array $columns): array
    {
        $knownColumns = collect($metadata['columns'])->pluck('name')->all();

        return array_map(function (string $column) use ($connection, $knownColumns) {
            if (! in_array($column, $knownColumns, true)) {
                throw new DisplayException('Database export failed because a row included an unknown column.');
            }

            return $this->quoteDumpIdentifier($connection, $column);
        }, $columns);
    }

    private function quoteDumpTable(Connection $connection, string $table): string
    {
        $this->assertDumpIdentifierSafe($table);

        return $this->quoteTable($connection, $table);
    }

    private function quoteDumpIdentifier(Connection $connection, string $identifier): string
    {
        $this->assertDumpIdentifierSafe($identifier);

        return $this->quoteIdentifier($connection, $identifier);
    }

    private function assertDumpIdentifierSafe(string $identifier): void
    {
        if (
            str_contains($identifier, "\0")
            || $identifier === '*'
            || str_contains($identifier, '.')
            || stripos($identifier, ' as ') !== false
            || str_contains($identifier, '->')
        ) {
            throw new DisplayException('Database export failed because an identifier contains an unsupported character.');
        }
    }

    private function assertDumpIdentifiersSafe(array $identifiers): void
    {
        foreach ($identifiers as $identifier) {
            if (! is_string($identifier)) {
                throw new DisplayException('Database export failed because an identifier contains an unsupported character.');
            }

            $this->assertDumpIdentifierSafe($identifier);
        }
    }

    private function quoteValue(Connection $connection, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || (is_float($value) && is_finite($value))) {
            return (string) $value;
        }

        if (! is_scalar($value)) {
            throw new DisplayException('Database export failed while preparing SQL dump values.');
        }

        $quoted = $connection->getPdo()->quote((string) $value);
        if ($quoted === false) {
            throw new DisplayException('Database export failed while preparing SQL dump values.');
        }

        return $quoted;
    }

    private function safeDatabaseException(Throwable $exception): DisplayException
    {
        return new DisplayException('Database operation failed. Check the SQL syntax, selected table, and database user permissions.');
    }

    private function maxQueryBytes(): int
    {
        return (int) $this->settings->get('max_query_bytes', 64 * 1024);
    }

    private function maxImportBytes(): int
    {
        return (int) $this->settings->get('max_import_bytes', 2 * 1024 * 1024);
    }

    private function maxResultRows(): int
    {
        return (int) $this->settings->get('max_result_rows', 500);
    }

    private function maxExportRows(): int
    {
        return (int) $this->settings->get('max_export_rows', 100000);
    }

    private function defaultPageSize(): int
    {
        return (int) $this->settings->get('default_page_size', 50);
    }

    private function maxPageSize(): int
    {
        return (int) $this->settings->get('max_page_size', 100);
    }

    private function connectTimeoutSeconds(): int
    {
        return (int) $this->settings->get('connect_timeout_seconds', 5);
    }
}
