<?php

namespace DatabaseStudio;

use DatabaseStudio\Services\DatabaseStudioService;
use Pterodactyl\Extensions\ExtensionProvider;
use DatabaseStudio\Support\ActivitySettingsDefinition;
use Illuminate\Translation\FileLoader;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettings;

class DatabaseStudioProvider extends ExtensionProvider
{
    public function register(): void
    {
        $this->app->bind(DatabaseStudioService::class, fn ($app) => new DatabaseStudioService(
            $app['config'], $app['encrypter'], $app['db'], new ExtensionSettings('database-studio'),
        ));
    }

    public function boot(): void
    {
        $this->registerApiRoutes();
        $this->registerPermissions('Database Studio', [
            'read' => 'Browse tables and run read queries.',
            'write' => 'Run write queries, create tables, and edit or delete rows.',
            'import' => 'Import SQL. Write statements also require the write permission.',
            'export' => 'Download table data or SQL exports.',
        ]);
        $runtime = $this->app->make(DatabaseStudioService::class)->runtimeLimits();
        $bytes = fn (?int $value): string => $value === null ? 'Unlimited' : number_format($value / 1024 / 1024, 1).' MiB';
        $runtimeHelp = sprintf(
            'Detected PHP limits: upload_max_filesize %s; post_max_size %s; memory_limit %s; max_execution_time %s. Effective import limits: file %s; pasted SQL %s. PHP POST overhead and web server/proxy limits may reduce usable sizes.',
            $bytes($runtime['php_upload_max_filesize_bytes']),
            $bytes($runtime['php_post_max_size_bytes']),
            $bytes($runtime['php_memory_limit_bytes']),
            $runtime['php_max_execution_time_seconds'] ? $runtime['php_max_execution_time_seconds'].' seconds' : 'Unlimited',
            $bytes($runtime['max_upload_bytes']),
            $bytes($runtime['max_text_import_bytes']),
        );
        $definitions = [
            ExtensionSettingDefinition::make('display_location', 'display_location', 'tab', ['required', 'in:tab,before,after,both'])
                ->field('select', [
                    ['value' => 'tab', 'label' => 'Separate server tab'],
                    ['value' => 'before', 'label' => 'Above the database list'],
                    ['value' => 'after', 'label' => 'Below the database list'],
                    ['value' => 'both', 'label' => 'Server tab and below the database list'],
                ])->label('Display location')->help('Choose where customers see Database Studio. Reload the server page after changing this setting.')->frontend(),
            ExtensionSettingDefinition::make('show_technical_limits', 'show_technical_limits', true, ['required', 'boolean'])
                ->field('toggle')->label('Show technical limits')
                ->help('Show detected PHP limits, effective import/export limits, and runtime guidance in Import and Export. The user-facing limit message remains visible. This changes visibility only; limits are still enforced. Reload the server page after saving.')
                ->normalizeUsing(fn ($value) => (bool) $value),
            ExtensionSettingDefinition::make('limit_notice', 'limit_notice', '', ['nullable', 'string', 'max:500'])
                ->textarea(500)->label('User-facing limit message')
                ->help('Optional message shown in both Import and Export, for example: "Imports: up to 2 MiB. Exports: up to 100,000 rows." Leave blank to hide it. This message remains visible when Show technical limits is disabled and does not change the enforced limits. Reload the server page after saving. '.$runtimeHelp)
                ->normalizeUsing(fn ($value) => trim((string) $value)),
        ];
        foreach ([
            'max_query_bytes' => [65536, 1048576, 'Query size limit (bytes)'],
            'max_import_bytes' => [2097152, 10485760, 'Import size limit (bytes)'],
            'max_result_rows' => [500, 5000, 'Query result row limit'],
            'max_export_rows' => [100000, 1000000, 'Combined export row limit'],
            'default_page_size' => [50, 100, 'Default page size'],
            'max_page_size' => [100, 500, 'Maximum page size'],
            'connect_timeout_seconds' => [5, 30, 'Connection timeout (seconds)'],
        ] as $key => [$default, $max, $label]) {
            $definitions[] = ExtensionSettingDefinition::make($key, $key, $default, ['required', 'integer', 'min:1', 'max:'.$max])
                ->field('number')->label($label)->normalizeUsing(fn ($value) => (int) $value);
        }
        $this->registerSettings(new ActivitySettingsDefinition($this->settings(), $definitions));
        // Add only extension-owned activity keys to the native feed's namespace.
        $this->callAfterResolving('translation.loader', function (FileLoader $loader): void {
            $loader->addPath($this->extensionPath('resources', 'activity'));
        });
    }
}
