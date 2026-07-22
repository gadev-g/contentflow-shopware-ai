import template from './contentflow-overview.html.twig';
import './contentflow-overview.scss';

const { Mixin } = Shopware;
const { Criteria, EntityCollection } = Shopware.Data;

export default {
    template,
    inject: ['repositoryFactory'],
    mixins: [Mixin.getByName('notification')],
    data() {
        return {
            selectedProducts: new EntityCollection('/product', 'product', Shopware.Context.api),
            availableLanguages: new EntityCollection('/language', 'language', Shopware.Context.api),
            categoryId: '',
            sourceLanguageId: '',
            targetLanguageId: '',
            sourceLanguage: 'de',
            targetLanguage: 'en',
            provider: '',
            providerOptions: [],
            preview: null,
            seoPreview: null,
            coverage: null,
            languagesLoading: false,
            coverageLoading: false,
            providersLoading: false,
            providerSaving: false,
            loading: false,
            seoLoading: false,
            saving: false,
        };
    },
    computed: {
        productRepository() {
            return this.repositoryFactory.create('product');
        },
        productCriteria() {
            const criteria = new Criteria(1, 50);
            criteria.addFilter(Criteria.equals('parentId', null));
            criteria.addSorting(Criteria.sort('name', 'ASC'));
            criteria.addAssociation('cover.media');

            if (this.categoryId) {
                criteria.addFilter(Criteria.equals('categories.id', this.categoryId));
            }

            return criteria;
        },
        categoryRepository() {
            return this.repositoryFactory.create('category');
        },
        categoryCriteria() {
            const criteria = new Criteria(1, 100);
            criteria.addSorting(Criteria.sort('name', 'ASC'));

            return criteria;
        },
        languageRepository() {
            return this.repositoryFactory.create('language');
        },
        currentCoverage() {
            return this.coverage?.summary?.find((item) => item.id === this.targetLanguageId) || null;
        },
    },
    watch: {
        categoryId() {
            this.selectedProducts = new EntityCollection('/product', 'product', Shopware.Context.api);
        },
        sourceLanguageId() {
            this.sourceLanguage = this.localeCodeForLanguage(this.sourceLanguageId, 'de');
        },
        targetLanguageId() {
            this.targetLanguage = this.localeCodeForLanguage(this.targetLanguageId, 'en');
            this.loadCoverage();
        },
    },
    created() {
        this.loadLanguages();
        this.loadProviderSettings();
    },
    methods: {
        async loadProviderSettings() {
            this.providersLoading = true;

            try {
                const response = await Shopware.Application.getContainer('init').httpClient.post(
                    '/_action/contentflow/connection',
                );

                this.providerOptions = (response.data.providers || []).map((provider) => ({
                    value: provider,
                    label: this.providerLabel(provider),
                }));
                this.provider = response.data.provider || this.providerOptions[0]?.value || '';
            } catch (error) {
                this.createNotificationError({ message: error.response?.data?.message || 'Die ContentFlow-Provider konnten nicht geladen werden.' });
            } finally {
                this.providersLoading = false;
            }
        },
        async saveProvider() {
            this.providerSaving = true;

            try {
                await Shopware.Application.getContainer('init').httpClient.post(
                    '/_action/contentflow/settings/provider',
                    { provider: this.provider },
                );
                this.createNotificationSuccess({ message: 'Der Standard-Provider wurde gespeichert.' });
            } catch (error) {
                this.createNotificationError({ message: error.response?.data?.error?.message || error.message });
            } finally {
                this.providerSaving = false;
            }
        },
        providerLabel(provider) {
            return {
                openai: 'OpenAI',
                anthropic: 'Anthropic',
                ollama: 'Ollama',
            }[provider] || provider;
        },
        async loadLanguages() {
            this.languagesLoading = true;

            try {
                const criteria = new Criteria(1, 500);
                criteria.addAssociation('locale');

                this.availableLanguages = await this.languageRepository.search(criteria, Shopware.Context.api);
                this.sourceLanguageId = this.findLanguageId('de') || this.availableLanguages[0]?.id || '';
                this.targetLanguageId = this.findLanguageId('en') || this.availableLanguages[1]?.id || this.sourceLanguageId;
            } catch (error) {
                this.createNotificationError({ message: 'Die vorhandenen Shopware-Sprachen konnten nicht geladen werden.' });
            } finally {
                this.languagesLoading = false;
            }
        },
        findLanguageId(languageCode) {
            return this.availableLanguages.find((language) => language.locale?.code?.toLowerCase().startsWith(languageCode))?.id;
        },
        localeCodeForLanguage(languageId, fallback) {
            return this.availableLanguages.get(languageId)?.locale?.code || fallback;
        },
        formatSchema(schema) {
            return JSON.stringify(schema || {}, null, 2);
        },
        coveragePercent(value, total) {
            const numericTotal = Number(total || 0);

            if (numericTotal === 0) {
                return 0;
            }

            return Math.round((Number(value || 0) / numericTotal) * 100);
        },
        async loadCoverage() {
            if (!this.targetLanguageId) {
                return;
            }

            this.coverageLoading = true;

            try {
                const response = await Shopware.Application.getContainer('init').httpClient.post(
                    '/_action/contentflow/coverage',
                    { languageId: this.targetLanguageId },
                );
                this.coverage = response.data;
            } catch (error) {
                this.createNotificationError({
                    message: error.response?.data?.error?.message || 'Die Content Coverage konnte nicht geladen werden.',
                });
            } finally {
                this.coverageLoading = false;
            }
        },
        previewProductImage(reference) {
            const productId = String(reference || '').replace(/^product:/, '');
            const product = this.selectedProducts.find((item) => item.id === productId);

            return product?.cover?.media?.url || null;
        },
        async createPreview() {
            this.loading = true;
            this.preview = null;

            try {
                const response = await Shopware.Application.getContainer('init').httpClient.post(
                    '/_action/contentflow/products/translate-preview',
                    {
                        ids: this.selectedProducts.map((product) => product.id),
                        sourceLanguage: this.sourceLanguage,
                        targetLanguage: this.targetLanguage,
                    },
                );
                this.preview = response.data;
                this.createNotificationSuccess({ message: 'Die Übersetzungsvorschau ist fertig.' });
            } catch (error) {
                this.createNotificationError({ message: error.response?.data?.error?.message || error.message });
            } finally {
                this.loading = false;
            }
        },
        async approve() {
            this.saving = true;

            try {
                await Shopware.Application.getContainer('init').httpClient.post(
                    '/_action/contentflow/products/approve',
                    { languageId: this.targetLanguageId, records: this.preview.records },
                );
                this.createNotificationSuccess({ message: 'Die freigegebenen Übersetzungen wurden gespeichert.' });
                this.preview = null;
            } catch (error) {
                this.createNotificationError({ message: error.response?.data?.error?.message || error.message });
            } finally {
                this.saving = false;
            }
        },
        async createSeoPreview() {
            this.seoLoading = true;
            this.seoPreview = null;

            try {
                const response = await Shopware.Application.getContainer('init').httpClient.post(
                    '/_action/contentflow/products/seo-preview',
                    { ids: this.selectedProducts.map((product) => product.id), language: this.sourceLanguage },
                );
                this.seoPreview = response.data;
                this.createNotificationSuccess({ message: 'Die SEO-Vorschau ist fertig.' });
            } catch (error) {
                this.createNotificationError({ message: error.response?.data?.error?.message || error.message });
            } finally {
                this.seoLoading = false;
            }
        },
        async approveSeo() {
            this.saving = true;

            try {
                await Shopware.Application.getContainer('init').httpClient.post(
                    '/_action/contentflow/products/seo-approve',
                    { languageId: this.targetLanguageId, records: this.seoPreview.records },
                );
                this.createNotificationSuccess({ message: 'Die SEO-Daten wurden gespeichert.' });
                this.seoPreview = null;
            } catch (error) {
                this.createNotificationError({ message: error.response?.data?.error?.message || error.message });
            } finally {
                this.saving = false;
            }
        },
    },
};
