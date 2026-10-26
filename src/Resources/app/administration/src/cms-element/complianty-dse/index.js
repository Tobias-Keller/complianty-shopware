import './component';
import './config';
import './preview';

Shopware.Service('cmsService').registerCmsElement({
    name: 'complianty-dse',
    label: 'sw-cms.elements.compliantyDse.label',
    component: 'sw-cms-el-complianty-dse',
    previewComponent: 'sw-cms-el-preview-complianty-dse',
    configComponent: 'sw-cms-el-config-complianty-dse',
    defaultConfig: {},
});
