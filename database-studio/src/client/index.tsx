import './styles.css';
import { lazy, Suspense } from 'react';
import { useQuery } from '@tanstack/react-query';
import { definePterodactylExtension, useServerPermission } from '@pterodactyl/sdk';
import type { ScreenContext } from '@pterodactyl/sdk';
import { studioDatabasesQueryOptions } from './api';

function useStudioTabVisibility({ config, server }: ScreenContext) {
    const canRead = useServerPermission('ext.database-studio.read');
    const showTab = config.display_location === 'tab' || config.display_location === 'both';
    const databases = useQuery({
        ...studioDatabasesQueryOptions(server?.attributes.uuid ?? ''),
        enabled: showTab && canRead && !!server,
    });

    return showTab && canRead && databases.isSuccess && databases.data.length > 0;
}

const EmbeddedStudio = lazy(() => import('./screens/ServerScreen').then(module => ({ default: module.Studio })));
function InlineStudio() {
    const canRead = useServerPermission('ext.database-studio.read');
    if (!canRead) return null;
    return <div className='ds-embedded'><Suspense fallback={<p>Loading Database Studio…</p>}><EmbeddedStudio /></Suspense></div>;
}

export default definePterodactylExtension({
    setup({ screens, slots, config }) {
        screens.register('main', () => import('./screens/ServerScreen'), {
            visible: useStudioTabVisibility,
        });
        if (config.display_location === 'before') slots.register('server.databases.before', InlineStudio);
        if (config.display_location === 'after' || config.display_location === 'both') slots.register('server.databases.after', InlineStudio);
    },
});
