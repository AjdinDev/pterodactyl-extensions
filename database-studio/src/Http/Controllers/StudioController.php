<?php

namespace DatabaseStudio\Http\Controllers;

use DatabaseStudio\Services\DatabaseStudioService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\Database;
use Pterodactyl\Models\Server;

class StudioController extends Controller
{
    public function __construct(private DatabaseStudioService $studio) {}

    public function index(Request $request, Server $server): array
    {
        $this->authorize($request, $server, 'read');

        return ['databases' => $server->databases()->orderBy('database')->get(['id', 'database'])
            ->map(fn (Database $database) => ['id' => $database->id, 'name' => $database->database])->all()];
    }

    public function schema(Request $request, Server $server, string $databaseId): array
    {
        return $this->studio->schema($this->database($request, $server, $databaseId));
    }

    public function rows(Request $request, Server $server, string $databaseId): array
    {
        $database = $this->database($request, $server, $databaseId);
        $data = $request->validate([
            'table' => ['required', 'string', 'max:191'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'order_by' => ['sometimes', 'nullable', 'string', 'max:191'],
            'order_direction' => ['sometimes', 'in:asc,desc'],
        ]);

        return $this->studio->rows($database, $data['table'], $data);
    }

    public function query(Request $request, Server $server, string $databaseId): array
    {
        $database = $this->database($request, $server, $databaseId);
        $data = $request->validate(['sql' => ['required', 'string', 'max:1048576']]);
        if ($this->studio->statementRequiresWrite($data['sql'])) {
            $this->authorize($request, $server, 'write');
        }
        $result = $this->studio->execute($database, $data['sql']);
        $this->log('query', $database, [
            'sql_hash' => hash('sha256', $data['sql']), 'statement_type' => $result['statement_type'],
            'row_count' => $result['row_count'], 'affected_rows' => $result['affected_rows'],
        ]);

        return $result;
    }

    public function import(Request $request, Server $server, string $databaseId): array
    {
        $database = $this->database($request, $server, $databaseId, 'import');
        $data = $request->validate([
            'sql' => ['required_without:file', 'nullable', 'string', 'max:10485760'],
            'file' => ['required_without:sql', 'nullable', 'file', 'max:10240'],
        ]);
        $sql = $request->hasFile('file') ? (string) file_get_contents($request->file('file')->getRealPath()) : (string) $data['sql'];
        if ($this->studio->importRequiresWrite($sql)) {
            $this->authorize($request, $server, 'write');
        }
        $result = $this->studio->import($database, $sql);
        $this->log('import', $database, ['sql_hash' => hash('sha256', $sql), ...$result]);

        return $result;
    }

    public function export(Request $request, Server $server, string $databaseId): Response
    {
        $database = $this->database($request, $server, $databaseId, 'export');
        $data = $request->validate([
            'format' => ['sometimes', 'in:sql,sql.gz,csv'], 'table' => ['sometimes', 'nullable', 'string', 'max:191'],
        ]);
        $format = $data['format'] ?? 'sql';
        $table = $data['table'] ?? null;
        $result = $this->studio->export($database, $table, $format);
        $this->log('export', $database, ['format' => $format, 'table' => $table, 'row_count' => $result['row_count']]);

        return response($result['content'], 200, [
            'Content-Type' => $result['content_type'],
            'Content-Disposition' => 'attachment; filename="'.$result['filename'].'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function storeTable(Request $request, Server $server, string $databaseId): array
    {
        $database = $this->database($request, $server, $databaseId, 'write');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'], 'columns' => ['required', 'array', 'min:1', 'max:50'],
            'columns.*.name' => ['required', 'string', 'max:64'], 'columns.*.type' => ['required', 'string'],
            'columns.*.nullable' => ['sometimes', 'boolean'], 'columns.*.primary_key' => ['sometimes', 'boolean'],
            'columns.*.auto_increment' => ['sometimes', 'boolean'],
        ]);
        $result = $this->studio->createTable($database, $data);
        $this->log('table-create', $database, $result);

        return $result;
    }

    public function storeRow(Request $request, Server $server, string $databaseId): array
    {
        $database = $this->database($request, $server, $databaseId, 'write');
        $data = $request->validate(['table' => ['required', 'string', 'max:191'], 'values' => ['required', 'array']]);
        $result = $this->studio->insertRow($database, $data['table'], $data['values']);
        $this->log('row-create', $database, ['table' => $data['table'], ...$result]);

        return $result;
    }

    public function updateRow(Request $request, Server $server, string $databaseId): array
    {
        $database = $this->database($request, $server, $databaseId, 'write');
        $data = $request->validate([
            'table' => ['required', 'string', 'max:191'], 'values' => ['required', 'array'], 'key' => ['required', 'array'],
        ]);
        $result = $this->studio->updateRow($database, $data['table'], $data['key'], $data['values']);
        $this->log('row-update', $database, ['table' => $data['table'], ...$result]);

        return $result;
    }

    public function deleteRow(Request $request, Server $server, string $databaseId): array
    {
        $database = $this->database($request, $server, $databaseId, 'write');
        $data = $request->validate(['table' => ['required', 'string', 'max:191'], 'key' => ['required', 'array']]);
        $result = $this->studio->deleteRow($database, $data['table'], $data['key']);
        $this->log('row-delete', $database, ['table' => $data['table'], ...$result]);

        return $result;
    }

    private function database(Request $request, Server $server, string $id, string $permission = 'read'): Database
    {
        $this->authorize($request, $server, 'read');
        $this->authorize($request, $server, $permission);

        // Every operation resolves through this server; a database from another server is a 404.
        return $server->databases()->findOrFail((int) $id);
    }

    private function authorize(Request $request, Server $server, string $permission): void
    {
        if (! $request->user()->can('ext.database-studio.'.$permission, $server)) {
            throw new AuthorizationException;
        }
    }

    private function log(string $action, Database $database, array $properties): void
    {
        $event = Activity::event('server:database-studio.'.$action)->subject($database, $database->server)
            ->property(['database_id' => $database->id, 'name' => $database->database]);
        foreach ($properties as $key => $value) {
            $event->property($key, $value);
        }
        $event->log();
    }
}
