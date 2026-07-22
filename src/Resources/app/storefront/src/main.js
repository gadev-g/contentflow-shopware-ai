class ContentFlowAssistant {
    constructor(element) {
        this.element = element;
        this.panel = element.querySelector('.contentflow-assistant__panel');
        this.messages = element.querySelector('.contentflow-assistant__messages');
        this.form = element.querySelector('form');
        this.history = [];
        element.querySelector('.contentflow-assistant__toggle').addEventListener('click', () => this.open());
        element.querySelector('[data-contentflow-close]').addEventListener('click', () => this.close());
        this.form.addEventListener('submit', (event) => this.submit(event));
    }

    open() { this.panel.hidden = false; }
    close() { this.panel.hidden = true; }

    async submit(event) {
        event.preventDefault();
        const input = this.form.elements.message;
        const message = input.value.trim();
        if (!message) return;
        this.addMessage(message, 'user');
        this.history.push({ role: 'user', content: message });
        input.value = '';

        try {
            const response = await fetch('/contentflow/assistant', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ message, history: this.history.slice(-10) }),
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.error?.message || 'Der Berater ist gerade nicht erreichbar.');
            this.addMessage(data.reply, 'assistant', data.products || []);
            this.history.push({ role: 'assistant', content: data.reply });
        } catch (error) {
            this.addMessage(error.message, 'assistant');
        }
    }

    addMessage(text, role, products = []) {
        const item = document.createElement('article');
        item.className = `contentflow-assistant__message contentflow-assistant__message--${role}`;
        const paragraph = document.createElement('p');
        paragraph.textContent = text;
        item.appendChild(paragraph);
        products.forEach((product) => {
            const card = document.createElement('div');
            card.className = 'contentflow-assistant__product';
            const link = document.createElement('a');
            link.className = 'contentflow-assistant__product-link';
            link.href = `/detail/${encodeURIComponent(product.id)}`;
            link.textContent = product.title;
            const select = document.createElement('button');
            select.type = 'button';
            select.className = 'contentflow-assistant__product-select';
            select.textContent = 'Auswählen';
            select.addEventListener('click', () => this.confirmProduct(product.id, product.title));
            card.appendChild(link);
            card.appendChild(select);
            item.appendChild(card);
        });
        this.messages.appendChild(item);
        this.messages.scrollTop = this.messages.scrollHeight;
    }

    async confirmProduct(productId, title) {
        if (!window.confirm(`${title} in den Warenkorb legen?`)) return;
        const response = await fetch('/contentflow/assistant', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ message: title, product_id: productId, confirmed: true, quantity: 1 }),
        });
        const data = await response.json();
        this.addMessage(data.cart_updated ? 'Das Produkt wurde in den Warenkorb gelegt.' : (data.error?.message || 'Das Produkt konnte nicht hinzugefügt werden.'), 'assistant');
    }
}

document.querySelectorAll('[data-contentflow-assistant]').forEach((element) => new ContentFlowAssistant(element));

const search = new URLSearchParams(window.location.search).get('search');
const trackSearchEvent = (type, query, productId = null, resultCount = 0) => fetch('/contentflow/search/event', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    body: JSON.stringify({ type, event_key: crypto.randomUUID(), query, product_id: productId, result_count: resultCount }),
    keepalive: true,
});

if (search && document.body.classList.contains('is-act-search')) {
    const resultCount = document.querySelectorAll('.product-box').length;
    sessionStorage.setItem('contentflowSearchQuery', search);
    trackSearchEvent('impression', search, null, resultCount);

    document.querySelectorAll('.product-box').forEach((box) => {
        let information = {};
        try { information = JSON.parse(box.dataset.productInformation || '{}'); } catch (error) { information = {}; }
        box.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => trackSearchEvent('click', search, information.id)));
        box.querySelectorAll('form').forEach((form) => form.addEventListener('submit', () => {
            trackSearchEvent('cart', search, information.id);
            const products = JSON.parse(sessionStorage.getItem('contentflowSearchCart') || '[]');
            sessionStorage.setItem('contentflowSearchCart', JSON.stringify([...new Set([...products, information.id])].filter(Boolean)));
        }));
    });
}

if (document.querySelector('[data-order-number]')) {
    const query = sessionStorage.getItem('contentflowSearchQuery') || '';
    const products = JSON.parse(sessionStorage.getItem('contentflowSearchCart') || '[]');
    products.forEach((productId) => trackSearchEvent('purchase', query, productId));
    sessionStorage.removeItem('contentflowSearchCart');
}
