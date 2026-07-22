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
            selectedRuleProducts: new EntityCollection('/product', 'product', Shopware.Context.api),
            availableLanguages: new EntityCollection('/language', 'language', Shopware.Context.api),
            sourceLanguageId: '',
            targetLanguageId: '',
            sourceLanguage: 'de',
            targetLanguage: 'en',
            provider: '',
            providerOptions: [],
            preview: null,
            seoPreview: null,
            languagesLoading: false,
            providersLoading: false,
            providerSaving: false,
            loading: false,
            seoLoading: false,
            saving: false,
            searchLoading: false,
            searchSyncing: false,
            searchAnalytics: null,
            searchQuery: '',
            searchTest: null,
            searchRule: { name: '', type: 'pin', query: '', product_ids: [], priority: 100, active: true },
            searchRuleSynonyms: '',
            coverage: null,
            coverageLoading: false,
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

            return criteria;
        },
        languageRepository() {
            return this.repositoryFactory.create('language');
        },
    },
    watch: {
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
        this.loadSearchAnalytics();
    },
    methods: {
        apiRequestConfig() {
            return {
                headers: {
                    Accept: 'application/json',
                    Authorization: `Bearer ${Shopware.Service('loginService').getToken()}`,
                    'Content-Type': 'application/json',
                    'sw-language-id': Shopware.Context.api.languageId,
                },
            };
        },
        async loadProviderSettings() {
            this.providersLoading = true;

            try {
                const response = await Shopware.Application.getContainer('init').httpClient.post(
                    '/_action/contentflow/connection',
                    {},
                    this.apiRequestConfig(),
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
                    this.apiRequestConfig(),
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
        rankingReasonLabel(reason) {
            return {
                exact_phrase: 'Exakte Wortgruppe',
                token_match: 'Suchbegriffe erkannt',
                shopware_match: 'Shopware-Treffer',
                merchant_pin: 'Vom Händler angeheftet',
                merchant_boost: 'Durch Händlerregel verstärkt',
            }[reason] || reason;
        },
        queryRate(value, searches) {
            if (!searches) return '0 %';

            return `${Math.round((value / searches) * 100)} %`;
        },
        coverageRate(value, total) {
            if (!total) return 0;

            return Math.round((value / total) * 100);
        },
        async loadCoverage() {
            if (!this.targetLanguageId) return;

            this.coverageLoading = true;

            try {
                const response = await Shopware.Application.getContainer('init').httpClient.get(
                    `/_action/contentflow/coverage?languageId=${encodeURIComponent(this.targetLanguageId)}`,
                    this.apiRequestConfig(),
                );
                this.coverage = response.data;
            } catch (error) {
                this.coverage = null;
            } finally {
                this.coverageLoading = false;
            }
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
                    this.apiRequestConfig(),
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
                    this.apiRequestConfig(),
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
                    this.apiRequestConfig(),
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
                    this.apiRequestConfig(),
                );
                this.createNotificationSuccess({ message: 'Die SEO-Daten wurden gespeichert.' });
                this.seoPreview = null;
            } catch (error) {
                this.createNotificationError({ message: error.response?.data?.error?.message || error.message });
            } finally {
                this.saving = false;
            }
        },
        async loadSearchAnalytics() {
            this.searchLoading = true;

            try {
                const response = await Shopware.Application.getContainer('init').httpClient.get(
                    '/_action/contentflow/search/analytics',
                    this.apiRequestConfig(),
                );
                this.searchAnalytics = response.data;
            } catch (error) {
                this.searchAnalytics = null;
            } finally {
                this.searchLoading = false;
            }
        },
        async syncSearchCatalog() {
            this.searchSyncing = true;

            try {
                const response = await Shopware.Application.getContainer('init').httpClient.post('/_action/contentflow/search/sync', {
                    language: this.sourceLanguageId || 'de-DE',
                }, this.apiRequestConfig());
                this.createNotificationSuccess({ message: `${response.data.saved || 0} Produkte wurden für die KI-Suche synchronisiert.` });
            } catch (error) {
                this.createNotificationError({ message: error.response?.data?.error?.message || error.message });
            } finally {
                this.searchSyncing = false;
            }
        },
        async testAiSearch() {
            if (!this.searchQuery.trim()) return;
            this.searchLoading = true;

            try {
                const response = await Shopware.Application.getContainer('init').httpClient.post('/_action/contentflow/search/test', {
                    query: this.searchQuery,
                    sales_channel_id: 'default',
                    language: this.sourceLanguageId || 'de-DE',
                    limit: 10,
                }, this.apiRequestConfig());
                this.searchTest = response.data;
            } catch (error) {
                this.createNotificationError({ message: error.response?.data?.error?.message || error.message });
            } finally {
                this.searchLoading = false;
            }
        },
        async saveSearchRule() {
            try {
                const values = this.searchRule.type === 'synonym'
                    ? this.searchRuleSynonyms.split(',').map((value) => value.trim()).filter(Boolean)
                    : this.selectedRuleProducts.map((product) => product.id);
                const payload = {
                    ...this.searchRule,
                    product_ids: this.searchRule.type === 'synonym' ? [] : values,
                    configuration: this.searchRule.type === 'synonym' ? { synonyms: values } : {},
                };
                await Shopware.Application.getContainer('init').httpClient.post(
                    '/_action/contentflow/search/rules',
                    payload,
                    this.apiRequestConfig(),
                );
                this.createNotificationSuccess({ message: 'Die Suchregel wurde gespeichert.' });
                this.resetSearchRule();
                await this.loadSearchAnalytics();
            } catch (error) {
                this.createNotificationError({ message: error.response?.data?.error?.message || error.message });
            }
        },
        async editSearchRule(rule) {
            const productIds = this.jsonArray(rule.product_ids);
            const configuration = this.jsonObject(rule.configuration);

            this.searchRule = {
                id: rule.id,
                name: rule.name,
                type: rule.rule_type,
                query: rule.query_pattern,
                product_ids: productIds,
                priority: Number(rule.priority || 100),
                active: rule.active !== false,
            };
            this.searchRuleSynonyms = Array.isArray(configuration.synonyms) ? configuration.synonyms.join(', ') : '';
            this.selectedRuleProducts = new EntityCollection('/product', 'product', Shopware.Context.api);

            if (productIds.length) {
                const criteria = new Criteria(1, productIds.length);
                criteria.addFilter(Criteria.equalsAny('id', productIds));
                this.selectedRuleProducts = await this.productRepository.search(criteria, Shopware.Context.api);
            }
        },
        async deleteSearchRule(rule) {
            if (!window.confirm(`Regel „${rule.name}“ wirklich löschen?`)) return;

            try {
                await Shopware.Application.getContainer('init').httpClient.delete(
                    `/_action/contentflow/search/rules/${encodeURIComponent(rule.id)}`,
                    this.apiRequestConfig(),
                );
                this.createNotificationSuccess({ message: 'Die Suchregel wurde gelöscht.' });
                if (this.searchRule.id === rule.id) this.resetSearchRule();
                await this.loadSearchAnalytics();
            } catch (error) {
                this.createNotificationError({ message: error.response?.data?.error?.message || error.message });
            }
        },
        resetSearchRule() {
            this.searchRule = { name: '', type: 'pin', query: '', product_ids: [], priority: 100, active: true };
            this.searchRuleSynonyms = '';
            this.selectedRuleProducts = new EntityCollection('/product', 'product', Shopware.Context.api);
        },
        jsonArray(value) {
            if (Array.isArray(value)) return value;
            try {
                const parsed = JSON.parse(value || '[]');
                return Array.isArray(parsed) ? parsed : [];
            } catch (error) {
                return [];
            }
        },
        jsonObject(value) {
            if (value && typeof value === 'object' && !Array.isArray(value)) return value;
            try {
                const parsed = JSON.parse(value || '{}');
                return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
            } catch (error) {
                return {};
            }
        },
    },
};
