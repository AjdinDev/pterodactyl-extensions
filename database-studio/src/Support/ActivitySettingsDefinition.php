<?php

declare(strict_types=1);

namespace DatabaseStudio\Support;

use Illuminate\Support\Facades\DB;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;

/** Audit native settings saves using field names only, including secret fields. */
final class ActivitySettingsDefinition extends ExtensionSettingsDefinition
{
    public function update(array $input): void
    {
        $input = $this->withoutBlankSecrets($input);
        DB::transaction(function () use ($input): void {
            parent::update($input);
            $user = request()->user();
            if ($input !== [] && $user instanceof User) {
                Activity::event('admin:'.$this->extension().'.settings-update')->actor($user)
                    ->property('fields', array_keys($input))->log();
            }
        });
    }
}
