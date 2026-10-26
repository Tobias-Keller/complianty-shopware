import template from './sw-cms-el-config-complianty-dse.html.twig';

Shopware.Component.register('sw-cms-el-config-complianty-dse', {
    template,

    mixins: [Shopware.Mixin.getByName('cms-element')],

    created() {
        this.initElementConfig('complianty-dse');
    },
});
