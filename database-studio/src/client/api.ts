import { http, httpErrorToHuman } from '@pterodactyl/sdk';

export type Cell = string | number | boolean | null;
export type Row = Record<string, Cell>;
export interface Column { name: string; type: string; nullable: boolean; default: Cell; key: string; extra: string; comment: string }
export interface Table { name: string; type: string; columns: Column[]; primary_key: string[] }
export interface Schema { database: string; tables: Table[]; limits: { show_technical_limits: boolean; limit_notice: string; max_query_bytes: number; max_import_bytes: number; max_result_rows: number; max_export_rows: number; default_page_size: number; max_page_size: number; max_upload_bytes: number; max_text_import_bytes: number; php_upload_max_filesize_bytes: number | null; php_post_max_size_bytes: number | null; php_memory_limit_bytes: number | null; php_max_execution_time_seconds: number } }
export interface Rows { table: string; columns: Column[]; primary_key: string[]; rows: Row[]; pagination: { total: number; count: number; per_page: number; current_page: number; total_pages: number } }
export interface QueryResult { type: 'read' | 'write'; statement_type: string; columns: string[]; rows: Row[]; row_count: number; affected_rows: number }
export interface ImportResult { statements: number; write_statements: number; affected_rows: number }
export interface NewColumn { name: string; type: string; nullable: boolean; primary_key: boolean; auto_increment: boolean }
export const errorMessage = httpErrorToHuman;

export function studioDatabasesQueryOptions(server: string) {
    return {
        queryKey: ['database-studio', server, 'databases'],
        queryFn: studioApi(server).databases,
        retry: false,
        refetchInterval: 10_000,
    };
}

export function studioApi(server: string, database?: number) {
    const base = `/api/client/servers/${encodeURIComponent(server)}/extensions/database-studio/databases`;
    const db = `${base}/${database}`;
    return {
        databases: async () => (await http.get<{ databases: { id: number; name: string }[] }>(base)).data.databases,
        schema: async () => (await http.get<Schema>(`${db}/schema`)).data,
        rows: async (table: string, page: number, per_page: number, order_by?: string, order_direction = 'asc') =>
            (await http.get<Rows>(`${db}/rows`, { params: { table, page, per_page, order_by, order_direction } })).data,
        query: async (sql: string) => (await http.post<QueryResult>(`${db}/query`, { sql })).data,
        import: async (sql: string, file?: File) => {
            const data = new FormData();
            if (file) data.append('file', file); else data.append('sql', sql);
            return (await http.post<ImportResult>(`${db}/import`, data)).data;
        },
        createTable: async (name: string, columns: NewColumn[]) => (await http.post(`${db}/tables`, { name, columns })).data,
        insert: async (table: string, values: Row) => (await http.post(`${db}/rows`, { table, values })).data,
        update: async (table: string, key: Row, values: Row) => (await http.patch(`${db}/rows`, { table, key, values })).data,
        delete: async (table: string, key: Row) => (await http.delete(`${db}/rows`, { data: { table, key } })).data,
        export: async (format: string, table?: string) => {
            try {
                const response = await http.get<Blob>(`${db}/export`, { params: { format, table }, responseType: 'blob' });
                const filename = /filename="([^"]+)"/.exec(response.headers['content-disposition'] ?? '')?.[1] ?? `database.${format}`;
                const url = URL.createObjectURL(response.data);
                const link = document.createElement('a'); link.href = url; link.download = filename;
                document.body.append(link); link.click(); link.remove();
                setTimeout(() => URL.revokeObjectURL(url), 1000);
            } catch (cause) {
                const response = (cause as { response?: { data?: Blob } }).response;
                if (response?.data instanceof Blob) {
                    const body = await response.data.text();
                    try { throw new Error(JSON.parse(body).errors?.[0]?.detail ?? 'Export failed.'); }
                    catch (error) { if (error instanceof SyntaxError) throw cause; throw error; }
                }
                throw cause;
            }
        },
    };
}
