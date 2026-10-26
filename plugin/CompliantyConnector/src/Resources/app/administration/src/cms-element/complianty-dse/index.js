import './component';
import './config';
import './preview';
import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

Shopware.Locale.extend('de-DE', deDE);
Shopware.Locale.extend('en-GB', enGB);

Shopware.Service('cmsService').registerCmsElement({
    name: 'complianty-dse',
    label: 'sw-cms.elements.compliantyDse.label',
    component: 'sw-cms-el-complianty-dse',
    previewComponent: 'sw-cms-el-preview-complianty-dse',
    configComponent: 'sw-cms-el-config-complianty-dse',
    defaultConfig: {},
});
