# ContentFlow AI for Shopware 6

Review-first AI workflows for Shopware products. The plugin provides bulk product translation, Product SEO Intelligence and native Content Coverage with previews and explicit approval before Shopware's DAL is updated. It uses the same ContentFlow project, provider settings, glossary, tone of voice, usage ledger and plan limits as the TYPO3 integration.

## Compatibility

- Shopware 6.6 and 6.7
- PHP 8.2 or newer
- A ContentFlow project API key

## Install with Composer

Register the GitHub repository in your Shopware project and install the plugin:

```bash
composer config repositories.contentflow-shopware vcs https://github.com/gadev-g/contentflow-shopware-ai.git
composer require contentflow/shopware-ai:dev-main
bin/console plugin:refresh
bin/console plugin:install --activate ContentFlowShopwareAi
bin/console cache:clear
```

With DDEV, prefix Composer with `ddev` and run console commands through `ddev exec`.

## Install from source

Copy this directory to `custom/plugins/ContentFlowShopwareAi`, then run:

```bash
bin/console plugin:refresh
bin/console plugin:install --activate ContentFlowShopwareAi
bin/console cache:clear
bin/build-administration.sh
```

Open **Extensions → My extensions → ContentFlow AI → Configure** and enter the ContentFlow API URL and project API key. The API key is used by the Shopware backend and is never returned to the Administration UI.

## Translation workflow

1. Open **ContentFlow** in the Shopware Administration.
2. Select a category, up to 25 products and the source and target language.
3. Create the preview and review every translated field.
4. Approve the preview to write the selected translations through Shopware's DAL.

## Product SEO workflow

The same product selection can generate SEO titles, meta descriptions, keywords and a readable Schema.org preview. Approval writes only Shopware's native translated SEO fields; arbitrary scripts are never injected into the storefront.

## Content Coverage

Content Coverage reads Shopware's native product, translation, language, media and media-translation tables. It reports translation, SEO and media-metadata completeness per language and lists products that still need editorial work. Coverage checks remain local to Shopware and do not consume AI usage.

## Planned module

The shared ContentFlow API already exposes Shopware-scoped Asset Intelligence. Its native media selector and write adapter are planned for a later plugin release; plan enforcement is performed by the API and cannot be bypassed by enabling UI code locally.
