class ContentFlowAssistant {
    constructor(element) {
        this.element = element;
        this.panel = element.querySelector('.contentflow-assistant__panel');
        this.messages = element.querySelector('.contentflow-assistant__messages');
        this.form = element.querySelector('form');
        this.expandButton = element.querySelector('[data-contentflow-expand]');
        this.history = this.restoreHistory();

        element.querySelector('.contentflow-assistant__toggle').addEventListener('click', () => this.open());
        element.querySelector('[data-contentflow-close]').addEventListener('click', () => this.close());
        this.expandButton.addEventListener('click', () => this.toggleFullscreen());
        this.form.addEventListener('submit', (event) => this.submit(event));
        this.history.forEach((entry) => {
            this.addMessage(entry.content, entry.role, entry.products || [], entry.suggestions || [], entry.comparison || null);
        });
    }

    open() {
        this.panel.hidden = false;
        window.requestAnimationFrame(() => {
            this.messages.scrollTop = this.messages.scrollHeight;
        });
    }

    close() {
        this.panel.hidden = true;
        this.panel.classList.remove('contentflow-assistant__panel--fullscreen');
        this.expandButton.setAttribute('aria-pressed', 'false');
        this.expandButton.setAttribute('aria-label', 'Auf Vollbild vergrößern');
        document.body.classList.remove('contentflow-assistant-open-fullscreen');
    }

    toggleFullscreen() {
        const fullscreen = this.panel.classList.toggle('contentflow-assistant__panel--fullscreen');
        this.expandButton.setAttribute('aria-pressed', String(fullscreen));
        this.expandButton.setAttribute(
            'aria-label',
            fullscreen ? 'Vollbild verkleinern' : 'Auf Vollbild vergrößern',
        );
        document.body.classList.toggle('contentflow-assistant-open-fullscreen', fullscreen);
        window.requestAnimationFrame(() => {
            this.messages.scrollTop = this.messages.scrollHeight;
        });
    }

    async submit(event) {
        event.preventDefault();
        const input = this.form.elements.message;
        const message = input.value.trim();

        if (!message) {
            return;
        }

        this.addMessage(message, 'user');
        this.history.push({ role: 'user', content: message });
        this.persistHistory();
        input.value = '';
        const thinkingMessage = this.addThinkingMessage();
        this.setBusy(true);

        try {
            const response = await fetch('/contentflow/assistant', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({
                    message,
                    history: this.history.slice(-10),
                    language: navigator.language,
                }),
            });
            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.error?.message || 'Der Berater ist gerade nicht erreichbar.');
            }

            this.addMessage(data.reply, 'assistant', data.products || [], data.suggestions || [], data.comparison || null);
            this.history.push({
                role: 'assistant',
                content: data.reply,
                products: data.products || [],
                suggestions: data.suggestions || [],
                comparison: data.comparison || null,
                type: data.type || 'product_results',
            });
            this.history = this.history.slice(-10);
            this.persistHistory();
        } catch (error) {
            this.addMessage(error.message, 'assistant');
        } finally {
            thinkingMessage.remove();
            this.setBusy(false);
        }
    }

    addThinkingMessage() {
        const item = document.createElement('article');
        item.className = 'contentflow-assistant__message contentflow-assistant__message--assistant contentflow-assistant__message--thinking';
        item.setAttribute('role', 'status');
        item.setAttribute('aria-label', 'Der KI-Berater denkt nach');

        const spinner = document.createElement('span');
        spinner.className = 'contentflow-assistant__spinner';
        spinner.setAttribute('aria-hidden', 'true');

        const text = document.createElement('span');
        text.textContent = 'Ich denke nach …';

        item.appendChild(spinner);
        item.appendChild(text);
        this.messages.appendChild(item);
        this.messages.scrollTop = this.messages.scrollHeight;

        return item;
    }

    setBusy(busy) {
        this.form.setAttribute('aria-busy', String(busy));
        this.form.elements.message.disabled = busy;
        this.form.querySelector('button[type="submit"]').disabled = busy;
    }

    addMessage(text, role, products = [], suggestions = [], comparison = null) {
        const item = document.createElement('article');
        item.className = `contentflow-assistant__message contentflow-assistant__message--${role}`;
        const content = document.createElement('div');
        content.className = 'contentflow-assistant__message-content';

        if (role === 'assistant') {
            content.appendChild(this.createAssistantIcon());
        }

        content.appendChild(this.createReply(text, products));
        item.appendChild(content);

        if (comparison && Array.isArray(comparison.products) && comparison.products.length) {
            item.appendChild(this.createComparison(comparison));
        }

        if (products.length) {
            const productGrid = document.createElement('div');
            productGrid.className = 'contentflow-assistant__products';

            products.forEach((product) => {
                const card = document.createElement('article');
            card.className = 'contentflow-assistant__product';

                const visualLink = document.createElement('a');
                visualLink.className = 'contentflow-assistant__product-visual';
                visualLink.href = `/detail/${encodeURIComponent(product.id)}`;

                if (product.image_url) {
                    const image = document.createElement('img');
                    image.src = product.image_url;
                    image.alt = product.title;
                    image.loading = 'lazy';
                    visualLink.appendChild(image);
                } else {
                    visualLink.appendChild(this.createAssistantIcon('contentflow-assistant__product-placeholder'));
                }

                const details = document.createElement('div');
                details.className = 'contentflow-assistant__product-details';
                const category = document.createElement('small');
                category.className = 'contentflow-assistant__product-category';
                category.textContent = product.category || product.manufacturer || 'Produkt';

            const link = document.createElement('a');
            link.className = 'contentflow-assistant__product-link';
            link.href = `/detail/${encodeURIComponent(product.id)}`;
            link.textContent = product.title;

                details.appendChild(category);
                details.appendChild(link);

            if (product.reason) {
                const reason = document.createElement('small');
                reason.className = 'contentflow-assistant__product-reason';
                reason.textContent = product.reason;
                    details.appendChild(reason);
                }

                if (Number.isFinite(Number(product.price))) {
                    const price = document.createElement('strong');
                    price.className = 'contentflow-assistant__product-price';
                    price.textContent = new Intl.NumberFormat(navigator.language, {
                        style: 'currency',
                        currency: product.currency || 'EUR',
                    }).format(Number(product.price));
                    details.appendChild(price);
            }

            const select = document.createElement('button');
            select.type = 'button';
            select.className = 'contentflow-assistant__product-select';
            select.textContent = 'Auswählen';
            select.addEventListener('click', () => this.confirmProduct(product.id, product.title));
                details.appendChild(select);

                card.appendChild(visualLink);
                card.appendChild(details);
                productGrid.appendChild(card);
            });

            item.appendChild(productGrid);
        }

        if (suggestions.length) {
            const chips = document.createElement('div');
            chips.className = 'contentflow-assistant__suggestions';

            suggestions.forEach((suggestion) => {
                const chip = document.createElement('button');
                chip.type = 'button';
                chip.textContent = suggestion;
                chip.addEventListener('click', () => {
                    this.form.elements.message.value = suggestion;
                    this.form.requestSubmit();
                });
                chips.appendChild(chip);
            });
            item.appendChild(chips);
        }

        this.messages.appendChild(item);
        this.messages.scrollTop = this.messages.scrollHeight;
    }

    createComparison(comparison) {
        const wrapper = document.createElement('div');
        wrapper.className = 'contentflow-assistant__comparison';

        comparison.products.forEach((product) => {
            const section = document.createElement('section');
            section.className = 'contentflow-assistant__comparison-product';
            const title = document.createElement('strong');
            title.className = 'contentflow-assistant__comparison-title';
            title.textContent = product.name || 'Produkt';
            section.appendChild(title);

            const list = document.createElement('dl');
            (comparison.criteria || []).forEach((criterion) => {
                const label = document.createElement('dt');
                label.textContent = criterion;
                const value = document.createElement('dd');
                value.textContent = product.values?.[criterion] ?? 'Keine Angabe';
                if (product.values?.[criterion] == null) {
                    value.classList.add('contentflow-assistant__comparison-missing');
                }
                list.appendChild(label);
                list.appendChild(value);
            });
            section.appendChild(list);

            if (Array.isArray(product.highlights) && product.highlights.length) {
                const highlights = document.createElement('ul');
                highlights.className = 'contentflow-assistant__comparison-highlights';
                product.highlights.forEach((highlight) => {
                    const item = document.createElement('li');
                    item.textContent = highlight;
                    highlights.appendChild(item);
                });
                section.appendChild(highlights);
            }
            wrapper.appendChild(section);
        });

        return wrapper;
    }

    createReply(text, products = []) {
        const reply = document.createElement('div');
        reply.className = 'contentflow-assistant__reply';
        let list = null;
        let parentItem = null;

        String(text).split(/\r?\n/).forEach((line) => {
            const listItem = line.match(/^(\s*)[-•]\s+(.+)$/u);

            if (listItem) {
                const nested = listItem[1].length > 0 && parentItem;
                if (nested) {
                    let nestedList = Array.from(parentItem.children)
                        .find((child) => child.tagName === 'UL');
                    if (!nestedList) {
                        nestedList = document.createElement('ul');
                        parentItem.appendChild(nestedList);
                    }
                    const item = document.createElement('li');
                    this.appendReplyText(item, listItem[2], products);
                    nestedList.appendChild(item);
                    return;
                }

                if (!list) {
                    list = document.createElement('ul');
                    reply.appendChild(list);
                }
                const item = document.createElement('li');
                this.appendReplyText(item, listItem[2], products);
                list.appendChild(item);
                parentItem = item;
                return;
            }

            list = null;
            parentItem = null;
            if (!line.trim()) {
                return;
            }
            const paragraph = document.createElement('p');
            this.appendReplyText(paragraph, line, products);
            reply.appendChild(paragraph);
        });

        return reply;
    }

    appendReplyText(element, text, products) {
        const titles = products
            .map((product) => String(product.title || '').trim())
            .filter(Boolean)
            .sort((left, right) => right.length - left.length);
        let remainder = String(text);

        while (remainder) {
            let matchIndex = -1;
            let matchTitle = '';

            titles.forEach((title) => {
                const index = remainder.indexOf(title);
                if (index >= 0 && (matchIndex < 0 || index < matchIndex)) {
                    matchIndex = index;
                    matchTitle = title;
                }
            });

            if (matchIndex < 0) {
                element.appendChild(document.createTextNode(remainder));
                break;
            }
            if (matchIndex > 0) {
                element.appendChild(document.createTextNode(remainder.slice(0, matchIndex)));
            }

            const strong = document.createElement('strong');
            strong.textContent = matchTitle;
            element.appendChild(strong);
            remainder = remainder.slice(matchIndex + matchTitle.length);
        }
    }

    createAssistantIcon(className = 'contentflow-assistant__answer-icon') {
        const icon = document.createElement('span');
        icon.className = className;
        icon.setAttribute('aria-hidden', 'true');
        icon.innerHTML = `
            <svg viewBox="0 0 24 24">
                <path d="M12 2.5 13.8 7a5 5 0 0 0 2.8 2.8l4.5 1.8-4.5 1.8a5 5 0 0 0-2.8 2.8L12 20.7l-1.8-4.5a5 5 0 0 0-2.8-2.8l-4.5-1.8 4.5-1.8A5 5 0 0 0 10.2 7L12 2.5Z"></path>
                <path d="m19 2 .7 1.8a2 2 0 0 0 1.1 1.1l1.8.7-1.8.7a2 2 0 0 0-1.1 1.1L19 9.2l-.7-1.8a2 2 0 0 0-1.1-1.1l-1.8-.7 1.8-.7a2 2 0 0 0 1.1-1.1L19 2Z"></path>
            </svg>
        `;

        return icon;
    }

    async confirmProduct(productId, title) {
        if (!window.confirm(`${title} in den Warenkorb legen?`)) {
            return;
        }

        const response = await fetch('/contentflow/assistant', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({
                message: `Ja, ${title} in den Warenkorb legen`,
                history: this.history.slice(-10),
                language: navigator.language,
                product_id: productId,
                confirmed: true,
                quantity: 1,
            }),
        });
        const data = await response.json();
        this.addMessage(
            data.cart_updated
                ? 'Das Produkt wurde in den Warenkorb gelegt.'
                : (data.error?.message || 'Das Produkt konnte nicht hinzugefügt werden.'),
            'assistant',
        );
    }

    restoreHistory() {
        try {
            const history = JSON.parse(sessionStorage.getItem('contentflowAssistantHistory') || '[]');

            return Array.isArray(history) ? history.slice(-10) : [];
        } catch (error) {
            return [];
        }
    }

    persistHistory() {
        sessionStorage.setItem('contentflowAssistantHistory', JSON.stringify(this.history.slice(-10)));
    }
}

document.querySelectorAll('[data-contentflow-assistant]').forEach((element) => new ContentFlowAssistant(element));

const search = new URLSearchParams(window.location.search).get('search');
const trackSearchEvent = (type, query, productId = null, resultCount = 0) => fetch('/contentflow/search/event', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    body: JSON.stringify({
        type,
        event_key: crypto.randomUUID(),
        query,
        product_id: productId,
        result_count: resultCount,
    }),
    keepalive: true,
});

if (search && document.body.classList.contains('is-act-search')) {
    const resultCount = document.querySelectorAll('.product-box').length;
    sessionStorage.setItem('contentflowSearchQuery', search);
    trackSearchEvent('impression', search, null, resultCount);

    document.querySelectorAll('.product-box').forEach((box) => {
        let information = {};

        try {
            information = JSON.parse(box.dataset.productInformation || '{}');
        } catch (error) {
            information = {};
        }

        box.querySelectorAll('a').forEach((link) => {
            link.addEventListener('click', () => trackSearchEvent('click', search, information.id));
        });
        box.querySelectorAll('form').forEach((form) => {
            form.addEventListener('submit', () => {
                trackSearchEvent('cart', search, information.id);
                const products = JSON.parse(sessionStorage.getItem('contentflowSearchCart') || '[]');
                sessionStorage.setItem(
                    'contentflowSearchCart',
                    JSON.stringify([...new Set([...products, information.id])].filter(Boolean)),
                );
            });
        });
    });
}

if (document.querySelector('[data-order-number]')) {
    const query = sessionStorage.getItem('contentflowSearchQuery') || '';
    const products = JSON.parse(sessionStorage.getItem('contentflowSearchCart') || '[]');
    products.forEach((productId) => trackSearchEvent('purchase', query, productId));
    sessionStorage.removeItem('contentflowSearchCart');
}
