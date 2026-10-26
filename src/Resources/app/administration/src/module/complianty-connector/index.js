import './page/complianty-connector-page';
import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

Shopware.Module.register('complianty-connector', {
    type: 'plugin',
    name: 'complianty-connector',
    title: 'complianty-connector.general.title',
    description: 'complianty-connector.general.description',
    color: '#0f766e',
    icon: 'regular-cog',

    snippets: {
        'de-DE': deDE,
        'en-GB': enGB,
    },

    routes: {
        index: {
            component: 'complianty-connector-page',
            path: 'index',
            meta: {
                parentPath: 'sw.settings.index.plugins',
                privilege: 'system_config:read',
            },
        },
    },

    settingsItem: {
        group: 'plugins',
        to: 'complianty.connector.index',
        icon: 'regular-cog',
        privilege: 'system_config:read',
    },
});
