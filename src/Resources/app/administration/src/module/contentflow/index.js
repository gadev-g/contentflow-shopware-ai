import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

Shopware.Component.register(
    'contentflow-overview',
    () => import('./page/contentflow-overview'),
);

Shopware.Module.register('contentflow-ai', {
    type: 'plugin',
    name: 'ContentFlow',
    title: 'contentflow.general.title',
    description: 'contentflow.general.description',
    color: '#218cff',
    icon: 'regular-sparkles',
    snippets: {
        'de-DE': deDE,
        'en-GB': enGB,
    },
    routes: {
        overview: {
            components: {
                default: 'contentflow-overview',
            },
            path: 'overview',
            meta: {
                privilege: 'product.viewer',
            },
        },
    },
    navigation: [{
        id: 'contentflow-ai',
        parent: 'sw-content',
        label: 'contentflow.general.title',
        color: '#218cff',
        path: 'contentflow.ai.overview',
        icon: 'regular-sparkles',
        position: 110,
        privilege: 'product.viewer',
    }],
});
