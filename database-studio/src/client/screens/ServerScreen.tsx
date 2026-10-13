import { useEffect, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ServerContentBlock, useCurrentServerRequired, useServerPermission, Button } from '@pterodactyl/sdk';
import { errorMessage, studioApi, studioDatabasesQueryOptions } from '../api';
import type { Column, NewColumn, QueryResult, Row, Schema } from '../api';

const tabs = ['Browse', 'SQL', 'Import', 'Export', 'Create table'] as const;
type Tab = typeof tabs[number];

export default function ServerScreen() {
    return <ServerContentBlock title='Database Studio'><Studio /></ServerContentBlock>;
}

export function Studio() {
    const server = useCurrentServerRequired().attributes.uuid;
    const databases = useQuery(studioDatabasesQueryOptions(server));
    const [selected, setSelected] = useState<number>();
    const database = databases.data?.find(db => db.id === selected) ?? databases.data?.[0];
    return <section className='ds-studio'>
            <header className='ds-heading'><div><h1>Database Studio</h1><p>Manage the MySQL databases attached to this server.</p></div>
                <label>Database<select value={database?.id ?? ''} onChange={e => setSelected(Number(e.target.value))} disabled={!databases.data?.length}>
                    {!database && <option value=''>Select a database</option>}
                    {databases.data?.map(db => <option key={db.id} value={db.id}>{db.name}</option>)}
                </select></label>
            </header>
            {databases.isPending && <p role='status'>Loading databases…</p>}
            {databases.error && <Message error>{errorMessage(databases.error)}</Message>}
            {databases.data?.length === 0 && <Message>Create a database in the server’s Databases tab to start using Studio.</Message>}
            {database && <Workspace key={`${server}-${database.id}`} server={server} database={database.id} />}
        </section>;
}

function Message({ children, error = false }: { children: ReactNode; error?: boolean }) {
    return <div className={`ds-message ${error ? 'ds-error' : ''}`} role={error ? 'alert' : 'status'}>{children}</div>;
}

function Workspace({ server, database }: { server: string; database: number }) {
    const api = studioApi(server, database);
    const cache = useQueryClient();
    const [tab, setTab] = useState<Tab>('Browse');
    const [table, setTable] = useState('');
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');
    const write = useServerPermission('ext.database-studio.write');
    const canImport = useServerPermission('ext.database-studio.import');
    const canExport = useServerPermission('ext.database-studio.export');
    const schema = useQuery({ queryKey: ['database-studio', server, database, 'schema'], queryFn: api.schema, retry: false });
    useEffect(() => {
        if (schema.data && !schema.data.tables.some(t => t.name === table)) setTable(schema.data.tables[0]?.name ?? '');
    }, [schema.data, table]);
    async function run(action: () => Promise<unknown>, success: string, refresh = false): Promise<boolean> {
        setBusy(true); setError(''); setMessage('');
        try {
            await action(); setMessage(success);
            if (refresh) await cache.invalidateQueries({ queryKey: ['database-studio', server, database] });
            return true;
        } catch (cause) { setError(errorMessage(cause)); return false; }
        finally { setBusy(false); }
    }
    const available = tabs.filter(t => t !== 'Import' || canImport).filter(t => t !== 'Export' || canExport).filter(t => t !== 'Create table' || write);
    return <>
        <div className='ds-tabs' role='tablist' aria-label='Database tools'>{available.map(t => <button key={t} role='tab' aria-selected={tab === t} onClick={() => { setTab(t); setError(''); setMessage(''); }}>{t}</button>)}</div>
        {error && <Message error>{error}</Message>}{message && <Message>{message}</Message>}
        {schema.isPending && <p role='status'>Connecting to database…</p>}
        {schema.error && <Message error>{errorMessage(schema.error)} <Button size='small' onClick={() => schema.refetch()}>Retry</Button></Message>}
        {schema.data && <div className='ds-panel' role='tabpanel' aria-label={tab}>
            {tab === 'Browse' && <Browse key={table} {...{ server, database, table, setTable, schema: schema.data, write, busy, run }} />}
            {tab === 'SQL' && <Sql {...{ api, busy, run, write, schema: schema.data }} />}
            {tab === 'Import' && <Import {...{ api, busy, run, write, schema: schema.data }} />}
            {tab === 'Export' && <Export {...{ api, busy, run, schema: schema.data }} />}
            {tab === 'Create table' && <CreateTable {...{ api, busy, run }} onCreated={name => { setTable(name); setTab('Browse'); }} />}
        </div>}
    </>;
}

type Api = ReturnType<typeof studioApi>;
type Run = (action: () => Promise<unknown>, success: string, refresh?: boolean) => Promise<boolean>;
type Actions = { api: Api; busy: boolean; run: Run };

function Browse({ server, database, table, setTable, schema, write, busy, run }: {
    server: string; database: number; table: string; setTable: (name: string) => void; schema: Schema; write: boolean; busy: boolean; run: Run;
}) {
    const api = studioApi(server, database);
    const [page, setPage] = useState(1);
    const [size, setSize] = useState(Math.min(schema.limits.default_page_size, schema.limits.max_page_size));
    const [order, setOrder] = useState('');
    const [direction, setDirection] = useState('asc');
    const [editor, setEditor] = useState<Row | 'new'>();
    const [deleting, setDeleting] = useState<Row>();
    const [structure, setStructure] = useState(false);
    const rows = useQuery({ queryKey: ['database-studio', server, database, 'rows', table, page, size, order, direction], queryFn: () => api.rows(table, page, size, order || undefined, direction), enabled: !!table, retry: false });
    const metadata = schema.tables.find(t => t.name === table);
    const key = (row: Row): Row => Object.fromEntries(metadata!.primary_key.map(name => [name, row[name]]));
    const editable = write && !!metadata?.primary_key.length && metadata.type === 'base table';
    return <>
        <div className='ds-toolbar'>
            <label>Table<select value={table} onChange={e => setTable(e.target.value)}>{!table && <option>No tables yet</option>}{schema.tables.map(t => <option key={t.name}>{t.name}</option>)}</select></label>
            <label>Sort column<select value={order} onChange={e => { setOrder(e.target.value); setPage(1); }}><option value=''>Primary key / default</option>{metadata?.columns.map(c => <option key={c.name}>{c.name}</option>)}</select></label>
            <label>Direction<select value={direction} onChange={e => setDirection(e.target.value)}><option value='asc'>Ascending</option><option value='desc'>Descending</option></select></label>
            <Button size='small' isSecondary onClick={() => rows.refetch()} disabled={!table || busy}>Refresh</Button>
            <Button size='small' isSecondary onClick={() => setStructure(!structure)} disabled={!table}>{structure ? 'Hide structure' : 'Structure'}</Button>
            {write && <Button size='small' onClick={() => setEditor('new')} disabled={!table || metadata?.type !== 'base table' || busy}>Insert row</Button>}
        </div>
        {!table && <Message>This database has no tables. Use SQL, Import, or Create table to add one.</Message>}
        {structure && metadata && <DataGrid columns={['name', 'type', 'nullable', 'default', 'key', 'extra']} rows={metadata.columns.map(c => ({ ...c }))} />}
        {rows.isFetching && table && <p role='status'>Loading rows…</p>}
        {rows.error && <Message error>{errorMessage(rows.error)}</Message>}
        {rows.data && <>
            <DataGrid columns={rows.data.columns.map(c => c.name)} rows={rows.data.rows} actions={editable ? row => <div className='ds-inline'><Button size='xsmall' isSecondary disabled={busy} onClick={() => setEditor(row)}>Edit</Button><Button.Danger size='xsmall' disabled={busy} onClick={() => setDeleting(row)}>Delete</Button.Danger></div> : undefined} />
            {!metadata?.primary_key.length && <p className='ds-muted'>Row editing and deletion require a primary key.</p>}
            <div className='ds-toolbar ds-pagination'><span>{rows.data.pagination.total.toLocaleString('en')} rows · Page {page} of {rows.data.pagination.total_pages}</span>
                <Button size='small' isSecondary disabled={page <= 1 || busy} onClick={() => setPage(page - 1)}>Previous</Button>
                <Button size='small' isSecondary disabled={page >= rows.data.pagination.total_pages || busy} onClick={() => setPage(page + 1)}>Next</Button>
                <label>Rows per page<select value={size} onChange={e => { setSize(Number(e.target.value)); setPage(1); }}>{[...new Set([10, 25, 50, 100, schema.limits.default_page_size, schema.limits.max_page_size])].filter(n => n <= schema.limits.max_page_size).sort((a,b) => a-b).map(n => <option key={n}>{n}</option>)}</select></label>
            </div>
        </>}
        {editor && metadata && <RowEditor key={JSON.stringify(editor)} columns={metadata.columns} primaryKey={metadata.primary_key} row={editor} busy={busy} onCancel={() => setEditor(undefined)} onSave={async values => {
            if (await run(() => editor === 'new' ? api.insert(table, values) : api.update(table, key(editor), values), 'Row saved.', true)) setEditor(undefined);
        }} />}
        {deleting && <div className='ds-editor'><h2>Delete this row?</h2><p>Primary key: <code>{JSON.stringify(key(deleting))}</code>. This removes the row from the database.</p><div className='ds-inline'><Button.Danger isLoading={busy} onClick={async () => { if (await run(() => api.delete(table, key(deleting)), 'Row deleted.', true)) setDeleting(undefined); }}>Confirm delete</Button.Danger><Button isSecondary disabled={busy} onClick={() => setDeleting(undefined)}>Cancel</Button></div></div>}
    </>;
}

function DataGrid({ columns, rows, actions }: { columns: string[]; rows: Row[]; actions?: (row: Row) => ReactNode }) {
    return <div className='ds-table-scroll'><table><thead><tr>{columns.map(c => <th key={c}>{c}</th>)}{actions && <th>Actions</th>}</tr></thead><tbody>
        {rows.map((row, i) => <tr key={i}>{columns.map(c => <td key={c}>{row[c] === null ? <span className='ds-null'>NULL</span> : String(row[c] ?? '')}</td>)}{actions && <td>{actions(row)}</td>}</tr>)}
        {!rows.length && <tr><td colSpan={columns.length + (actions ? 1 : 0)}>No rows.</td></tr>}
    </tbody></table></div>;
}

function RowEditor({ columns, primaryKey, row, busy, onSave, onCancel }: { columns: Column[]; primaryKey: string[]; row: Row | 'new'; busy: boolean; onSave: (values: Row) => Promise<void>; onCancel: () => void }) {
    const editing = row !== 'new';
    const fields = columns.filter(c => !c.extra.includes('GENERATED') && !(editing && primaryKey.includes(c.name)) && !(row === 'new' && c.extra.includes('auto_increment')));
    const [values, setValues] = useState<Row>(Object.fromEntries(fields.map(c => [c.name, editing ? row[c.name] : c.default ?? (c.nullable ? null : '')])));
    const [included, setIncluded] = useState<string[]>(fields.map(c => c.name));
    function submit(e: FormEvent) { e.preventDefault(); void onSave(Object.fromEntries(Object.entries(values).filter(([name]) => included.includes(name)))); }
    return <form className='ds-editor' onSubmit={submit}><h2>{editing ? 'Edit row' : 'Insert row'}</h2><p className='ds-muted'>Uncheck a column to leave it unchanged or use its database default.</p>
        <div className='ds-fields'>{fields.map(c => <div key={c.name} className='ds-field'>
            <label className='ds-check'><input type='checkbox' checked={included.includes(c.name)} onChange={e => setIncluded(e.target.checked ? [...included, c.name] : included.filter(n => n !== c.name))} />{c.name} <small>{c.type}</small></label>
            <input aria-label={`Value for ${c.name}`} disabled={!included.includes(c.name) || values[c.name] === null} value={String(values[c.name] ?? '')} onChange={e => setValues({ ...values, [c.name]: e.target.value })} />
            {c.nullable && <label className='ds-check'><input type='checkbox' checked={values[c.name] === null} disabled={!included.includes(c.name)} onChange={e => setValues({ ...values, [c.name]: e.target.checked ? null : '' })} />NULL</label>}
        </div>)}</div><div className='ds-inline'><Button type='submit' isLoading={busy} disabled={!included.length}>Save row</Button><Button isSecondary disabled={busy} onClick={onCancel}>Cancel</Button></div>
    </form>;
}

function Sql({ api, busy, run, write, schema }: Actions & { write: boolean; schema: Schema }) {
    const [sql, setSql] = useState('SELECT 1 AS connected;');
    const [result, setResult] = useState<QueryResult>();
    async function execute() { await run(async () => { setResult(await api.query(sql)); }, 'Query completed.', true); }
    return <><h2>SQL workspace</h2><p>Run one statement at a time. Results are limited to {schema.limits.max_result_rows.toLocaleString('en')} rows. {write ? 'Write access enabled.' : 'Read access only.'}</p>
        <label>SQL statement<textarea className='ds-sql' value={sql} onChange={e => setSql(e.target.value)} spellCheck={false} onKeyDown={e => { if ((e.ctrlKey || e.metaKey) && e.key === 'Enter' && !busy && sql.trim()) { e.preventDefault(); void execute(); } }} /></label>
        <div className='ds-toolbar'><Button isLoading={busy} disabled={!sql.trim()} onClick={execute}>Run query</Button><span className='ds-muted'>⌘ / Ctrl + Enter · {(new TextEncoder().encode(sql).length / 1024).toFixed(1)} / {(schema.limits.max_query_bytes / 1024).toFixed(0)} KiB</span></div>
        {result && <><p>{result.statement_type} · {result.type === 'read' ? `${result.row_count} rows returned` : `${result.affected_rows} rows affected`}</p>{result.type === 'read' && <DataGrid columns={result.columns} rows={result.rows} />}</>}
    </>;
}

function Import({ api, busy, run, write, schema }: Actions & { write: boolean; schema: Schema }) {
    const [sql, setSql] = useState(''); const [file, setFile] = useState<File>();
    const limit = file ? schema.limits.max_upload_bytes : schema.limits.max_text_import_bytes;
    const bytes = file?.size ?? new TextEncoder().encode(sql).length;
    return <><h2>Import SQL</h2><p>Upload a .sql file or paste SQL below. Limit: {(limit / 1024 / 1024).toFixed(1)} MiB. Imports run statements in order; a failure may leave earlier changes applied.</p>
        <RuntimeLimits schema={schema} importing />
        {!write && <Message>You can import read statements. Write statements require the write permission.</Message>}
        <label>SQL file<input type='file' accept='.sql,text/plain,application/sql' onChange={e => setFile(e.target.files?.[0])} /></label>
        <label>Or paste SQL<textarea className='ds-sql' disabled={!!file} value={sql} onChange={e => setSql(e.target.value)} spellCheck={false} /></label>
        {bytes > limit && <Message error>The import exceeds the configured size limit.</Message>}
        <Button isLoading={busy} disabled={!bytes || bytes > limit} onClick={() => run(async () => {
            const result = await api.import(sql, file); return result;
        }, 'Import completed. Refresh Browse to inspect the data.', true)}>Import SQL</Button>
    </>;
}

function Export({ api, busy, run, schema }: Actions & { schema: Schema }) {
    const [scope, setScope] = useState(''); const [format, setFormat] = useState('sql.gz');
    return <><h2>Export database</h2><p>Export scope is independent of the table open in Browse. Combined limit: {schema.limits.max_export_rows.toLocaleString('en')} rows.</p>
        <RuntimeLimits schema={schema} />
        <div className='ds-fields'><label>Export scope<select value={scope} onChange={e => { setScope(e.target.value); if (!e.target.value && format === 'csv') setFormat('sql.gz'); }}><option value=''>All tables</option>{schema.tables.map(t => <option key={t.name}>{t.name}</option>)}</select></label>
            <label>Format<select value={format} onChange={e => setFormat(e.target.value)}><option value='sql.gz'>SQL.gz (compressed SQL)</option><option value='sql'>SQL</option><option value='csv' disabled={!scope}>CSV (one table)</option></select></label></div>
        <p className='ds-muted'>SQL includes table structure and rows. CSV includes rows from one selected table. Routines, triggers and events are not included. Use a backup tool for full disaster recovery.</p>
        <Button isLoading={busy} onClick={() => run(() => api.export(format, scope || undefined), 'Export downloaded.')}>Download export</Button>
    </>;
}

function CreateTable({ api, busy, run, onCreated }: Actions & { onCreated: (name: string) => void }) {
    const [name, setName] = useState('');
    const [columns, setColumns] = useState<NewColumn[]>([{ name: 'id', type: 'integer', nullable: false, primary_key: true, auto_increment: true }, { name: '', type: 'short_text', nullable: false, primary_key: false, auto_increment: false }]);
    function change(index: number, patch: Partial<NewColumn>) { setColumns(columns.map((c, i) => i === index ? { ...c, ...patch } : c)); }
    return <form onSubmit={e => { e.preventDefault(); void run(() => api.createTable(name, columns), 'Table created.', true).then(ok => { if (ok) onCreated(name); }); }}>
        <h2>Create table</h2><label>Table name<input required maxLength={64} pattern='[A-Za-z_][A-Za-z0-9_]*' value={name} onChange={e => setName(e.target.value)} placeholder='e.g. player_settings' /></label>
        {columns.map((c, i) => <div className='ds-column-editor' key={i}>
            <label>Column name<input required aria-label={`Column ${i + 1} name`} maxLength={64} pattern='[A-Za-z_][A-Za-z0-9_]*' value={c.name} onChange={e => change(i, { name: e.target.value })} /></label>
            <label>Type<select aria-label={`Column ${i + 1} type`} value={c.type} onChange={e => change(i, { type: e.target.value })}>{Object.entries({ integer: 'Integer', big_integer: 'Big integer', decimal: 'Decimal (10,2)', short_text: 'Text (191)', long_text: 'Long text', boolean: 'Boolean', datetime: 'Date/time', timestamp: 'Timestamp' }).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
            <label className='ds-check'><input type='checkbox' checked={c.nullable} onChange={e => change(i, { nullable: e.target.checked })} />Nullable</label>
            <label className='ds-check'><input type='checkbox' checked={c.primary_key} onChange={e => change(i, { primary_key: e.target.checked })} />Primary key</label>
            <label className='ds-check'><input type='checkbox' checked={c.auto_increment} onChange={e => change(i, { auto_increment: e.target.checked })} />Auto increment</label>
            <Button size='small' isSecondary disabled={columns.length <= 1 || busy} onClick={() => setColumns(columns.filter((_, n) => n !== i))}>Remove</Button>
        </div>)}
        <div className='ds-toolbar'><Button isSecondary disabled={columns.length >= 50 || busy} onClick={() => setColumns([...columns, { name: '', type: 'short_text', nullable: false, primary_key: false, auto_increment: false }])}>Add column</Button><Button type='submit' isLoading={busy}>Create table</Button></div>
    </form>;
}

function RuntimeLimits({ schema, importing = false }: { schema: Schema; importing?: boolean }) {
    const l = schema.limits;
    const showTechnicalLimits = l.show_technical_limits !== false;
    if (!showTechnicalLimits && !l.limit_notice) return null;
    const bytes = (value: number | null) => value === null ? 'Unlimited' : value < 1024 ? `${value} bytes` : value < 1024 * 1024 ? `${(value / 1024).toFixed(0)} KiB` : `${(value / 1024 / 1024).toFixed(1)} MiB`;
    return <div className='ds-runtime'>
        {l.limit_notice && <div className='ds-limit-notice'><h3>Hosting limits</h3><p>{l.limit_notice}</p></div>}
        {showTechnicalLimits && <><h3>Current limits</h3><dl>
        {importing ? <>
            <div><dt>Extension import limit</dt><dd>{bytes(l.max_import_bytes)}</dd></div>
            <div><dt>Effective file upload limit</dt><dd>{bytes(l.max_upload_bytes)}</dd></div>
            <div><dt>PHP upload_max_filesize</dt><dd>{bytes(l.php_upload_max_filesize_bytes)}</dd></div>
            <div><dt>PHP post_max_size</dt><dd>{bytes(l.php_post_max_size_bytes)}</dd></div>
        </> : <div><dt>Combined export row limit</dt><dd>{l.max_export_rows.toLocaleString('en')} rows</dd></div>}
        <div><dt>PHP memory_limit</dt><dd>{bytes(l.php_memory_limit_bytes)}</dd></div>
        <div><dt>PHP max_execution_time</dt><dd>{l.php_max_execution_time_seconds ? `${l.php_max_execution_time_seconds} seconds` : 'Unlimited'}</dd></div>
    </dl><p className='ds-muted'>{importing ? 'PHP’s POST limit includes form overhead. A web server or proxy can impose a smaller limit.' : 'Exports use memory to build the download. Large rows can reach PHP’s memory or time limit before the row cap. Upload limits do not restrict downloads.'}</p></>}
    </div>;
}
