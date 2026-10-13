# Database Studio

Standalone Pterodactyl v2 Panel extension for browsing, querying, editing, importing, and exporting server databases. It uses the official PHP provider and React SDK, stock server/database models, and the selected database's customer credentials. It requires no Panel core edits or Wings patches.

## Compatibility

Locally verified on 2026-10-13 against stock `pterodactyl/panel` `2.0-develop`, commit `8e07ac97ab42d6b65b144ebf81d5f47da4f4fad1`, with SDK `2.0.0-beta.4`. This is a prototype against that pre-release snapshot; the manifest's version range is not evidence of testing every v2 release. See the official [extension guide](https://docs.pterodactyl.io/v2/extensions) and [building guide](https://docs.pterodactyl.io/v2/extensions/building).

Panel must reach the managed MySQL/MariaDB endpoint, and PHP needs PDO MySQL and zlib for compressed exports. Local verification used MySQL 8.4. MariaDB and other MySQL versions have not been exercised here. Operations connect directly from Panel and make no Wings API calls. No live Wings/game-server test was performed.

## Features and permissions

- Browse schema, paginate/sort rows, and run one SQL statement.
- Create basic tables and insert/edit/delete rows. Editing/deletion require the full primary key; primary keys cannot be edited through the row form.
- Import a plain `.sql` file or pasted SQL. Statements run sequentially; failure can leave earlier changes applied.
- Export all tables or one table as SQL or SQL.gz. CSV requires one selected table and escapes spreadsheet formulas. Export scope starts at All tables independently of Browse.
- Record activity metadata and SQL hashes without SQL text or decrypted credentials.

Grant subusers the native permissions under **Database Studio**:

| Permission | Capability |
| --- | --- |
| `ext.database-studio.read` | Required for every operation, browsing, and read queries |
| `ext.database-studio.write` | Write SQL, table creation, and row mutations |
| `ext.database-studio.import` | SQL import; writes also require `write` |
| `ext.database-studio.export` | SQL/SQL.gz/CSV downloads |

The backend enforces permissions and resolves each database through the current server relationship. Read queries use a MySQL read-only session, including permitted read-only imports. The parser conservatively classifies CTEs as writes. Query scripts, executable comments, and server filesystem SQL are rejected. Customer database grants remain the database isolation boundary.

## Native administrator settings

Open **Admin → Extensions → Database Studio → Settings**:

| Value | Placement |
| --- | --- |
| `tab` (default) | Separate Database Studio server tab |
| `before` | Above the stock Databases page |
| `after` | Below the stock Databases page |
| `both` | Separate tab and below the stock Databases page |

Reload the server page after saving. Placements use native screen visibility and `server.databases.before/after` slots. In this Panel snapshot the stock footer is part of the page content, so the lower slot appears after that footer.

The separate server tab appears only when the current server has at least one database and the user has Studio read permission. It stays hidden while the database list is loading or unavailable. The list refreshes every 10 seconds while the page is active, so creating the first database shows the tab and deleting the last one hides it. Embedded placements retain their create-a-database guidance.

Settings also control query bytes (default 65,536), import bytes (2,097,152), returned query rows (500), combined export rows (100,000), pagination (50 default / 100 maximum), and connection timeout (5 seconds). Query results remain capped with an explicit larger SQL `LIMIT`. Export limits apply across all selected tables and reject an oversized export rather than silently truncate it.

**Show technical limits** is enabled by default. Turn it off to hide the **Current limits** values and runtime guidance in both Import and Export. The custom **Hosting limits** message remains visible when configured. This controls visibility only; import/export limits and validation remain enforced. Reload the server page after saving.

When enabled, Import and Export show the active web PHP memory and execution-time limits. Import also shows `upload_max_filesize`, `post_max_size`, and the effective upload ceiling: the smallest of Studio's limit and applicable PHP limits. Pasted SQL does not use `upload_max_filesize`. POST overhead and a reverse proxy can reduce usable size. PHP upload limits do not constrain exports.

The native Settings dialog also shows these detected PHP values and effective import ceilings under **User-facing limit message**. A superadmin can enter an optional plain-text notice (up to 500 characters) displayed under **Hosting limits** in both Import and Export, including when **Show technical limits** is disabled. It is informational only: it does not change PHP settings or Studio's enforced caps. Leave the field blank to hide the notice. Reopen Settings to refresh its detected values; reload the server page after saving a message.

## Build and install

From this source directory, with Node 22.12+ and adjacent `../tooling/` SDK tarball:

```sh
npm ci
npm run typecheck
npm run build
```

From a configured stock Panel checkout:

```sh
php artisan p:extension:doctor /absolute/path/to/database-studio
php artisan p:extension:pack /absolute/path/to/database-studio --output=/absolute/path/to/database-studio-0.1.0.pteroext
php artisan p:extension:install /absolute/path/to/database-studio-0.1.0.pteroext --enable
```

Use `--force` on pack and `--replace` on install when intentionally updating a prototype. Reload after installation. There are no additional Composer runtime dependencies or extension migrations. The SDK tarball is a development dependency only; the installed UI resolves shared React/SDK modules through Panel's import map. See [SDK provenance](../tooling/README.md).

For this configured MacBook instance, [local/rebuild.sh](../local/rebuild.sh) checks, builds, doctors, packages, and installs this extension only. [Local setup and verification](../local/README.md) documents start/stop and real HTTP/MySQL checks.

## Prototype boundaries

Exports include structure and rows, not routines, triggers, or events. SQL.gz is a gzip download without `Content-Encoding`. Binary/generated-column/view export round trips have not been verified. Imports accept plain SQL, not compressed uploads or every vendor-specific dump syntax. This is a data-management tool, not a full disaster-recovery backup replacement.

Exports are assembled in memory; large row values can hit PHP memory/time limits before the row cap. Row/result caps do not guarantee a cheap SQL execution plan or terminate long-running queries. A failed import has no automatic rollback of previous statements. Review these boundaries before production use.

## License

[Apache License 2.0 with Commons Clause 1.0](LICENSE). This extension is
source-available. Commercial integration into a larger product is permitted
when that product's value does not derive entirely or substantially from this
extension. Selling or repackaging the extension itself is restricted; the same
condition covers hosting and consulting/support services. The full license
text governs use and distribution.

Retain [NOTICE](NOTICE) for third-party attribution. The copies in
`resources/legal/` ship in stock `.pteroext` archives. npm metadata points to
`LICENSE`; Composer uses `proprietary` because the combined terms do not have a
standard SPDX identifier. Third-party components retain their original licenses.

## Activity logs

Records SQL execution, import/export, table creation, and row changes in native server activity. Metadata includes SQL hashes and counts, never SQL text, row values, results, or credentials.

Native administrator settings saves record field names only, including secret
field names without their values. English activity descriptions ship
with the package. Activity permissions and retention are controlled by the host
Panel. Existing historical logs are retained.
