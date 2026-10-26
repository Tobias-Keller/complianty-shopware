import template from './sw-cms-el-complianty-dse.html.twig';

Shopware.Component.register('sw-cms-el-complianty-dse', {
    template,

    mixins: [Shopware.Mixin.getByName('cms-element')],

    created() {
        this.initElementConfig('complianty-dse');
    },
});
