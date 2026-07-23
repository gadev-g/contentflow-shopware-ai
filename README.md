# ContentFlow AI for Shopware 6

Review-first AI workflows for Shopware products. The first installable release provides bulk product translation and Product SEO Intelligence with previews and explicit approval before Shopware's DAL is updated. It uses the same ContentFlow project, provider settings, glossary, tone of voice, usage ledger, and plan limits as the TYPO3 integration.

## Compatibility

- Shopware 6.6 and 6.7
- PHP 8.2 or newer
- A ContentFlow project API key

## Install from source

Copy this directory to `custom/plugins/ContentFlowShopwareAi`, then run:

```bash
bin/console plugin:refresh
bin/console plugin:install --activate ContentFlowShopwareAi
bin/console assets:install
bin/console theme:compile
bin/console cache:clear
bin/build-administration.sh
```

## Update an existing installation

After replacing the plugin files, rebuild routes, Administration assets and
Storefront assets together. Skipping one of these steps can leave Shopware with
an old HTTP method cache or a Storefront theme that does not contain the
shopping assistant.

```bash
bin/console plugin:refresh
bin/console plugin:update ContentFlowShopwareAi
bin/console assets:install
bin/console theme:compile
bin/console cache:clear
bin/build-administration.sh
```

Open **Extensions → My extensions → ContentFlow AI → Configure** and enter the ContentFlow API URL and the project API key. The API key is used by the Shopware backend and is not returned to the Administration UI.

## Translation workflow

1. Open **ContentFlow** in the Shopware Administration.
2. Select up to 25 products and the source and target locale.
3. Create the preview and review every translated field.
4. Enter the ID of the existing Shopware target language.
5. Approve the preview to write the selected translations through Shopware's DAL.

## Product SEO workflow

The same product selection can generate SEO titles, meta descriptions, keywords, and a readable Schema.org preview. Approval writes only Shopware's native translated SEO fields; arbitrary scripts are never injected into the storefront.

## Next product module

The shared ContentFlow API already exposes Shopware-scoped Asset Intelligence. Its native media selector and write adapter are the next plugin increment; plan enforcement is already performed by the API and cannot be bypassed by enabling UI code locally.

## Asset credits

The Storefront expand control uses the [Vergrößern icon from Flaticon](https://www.flaticon.com/de/kostenloses-icon/vergrossern_6469458).
