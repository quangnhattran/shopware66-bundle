import template from './bundle-detail.html.twig';
const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('bundle-detail', {
    template,
    inject: ['repositoryFactory'],
    mixins: [
        Mixin.getByName('notification')
    ],
    metaInfo() {
        return {
            title: this.$createTitle()
        };
    },
    data() {
        return {
            bundle: null,
            products: null,
            isLoading: false,
            processSuccess: false,
            repository: null,
            productRepository: null,
            productCriteria: this.createProductCriteria()
        };
    },
    computed: {
        options() {
            return [
                { value: 'absolute', name: this.$tc('bundle.detail.absoluteText') },
                { value: 'percentage', name: this.$tc('bundle.detail.percentageText') }
            ];
        }
    },
    created() {
        this.createdComponent();
    },
    methods: {
        createdComponent() {
            this.repository = this.repositoryFactory.create('bundle');
            this.productRepository = this.repositoryFactory.create('product');
            this.productRepository.search(new Criteria(), Shopware.Context.api).then((result) => {
                this.products = result;
                console.log(this.products);
            })
            this.getBundle();
        },

        getBundle() {
            this.repository.get(this.$route.params.id, Shopware.Context.api).then((entity) => {
                this.bundle = entity;
            });
        },

        onClickSave() {
            this.isLoading = true;
            this.repository.save(this.bundle, Shopware.Context.api).then(() => {
                this.getBundle();
                this.isLoading = false;
                this.processSuccess = true;
            }).catch((exception) => {
                this.createNotificationError({
                    title: this.$tc('bundle.detail.errorTitle'),
                    message: exception
                });
                this.isLoading = false;
            });
        },

        saveFinish() {
            this.processSuccess = false;
        },

        createProductCriteria() {
            const criteria = new Criteria();
            criteria.addFilter(Criteria.equals('active', true)); // Example: only active products
            return criteria;
        }
    }
});