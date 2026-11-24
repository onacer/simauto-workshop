document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-stock-cost-form]').forEach((form) => {
        const product = form.querySelector('[name="product_id"]');
        const supplier = form.querySelector('[name="supplier_id"]');
        const cost = form.querySelector('[name="unit_cost"]');
        const comparison = form.querySelector('[data-supplier-costs]');
        const reference = form.querySelector('[data-cost-reference]');
        let sequence = 0;
        let pending;
        let manuallyEdited = false;

        const enableChoices = () => {
            comparison.querySelectorAll('[data-use-supplier-cost]').forEach((button) => { button.hidden = false; });
        };
        const suggestCost = () => {
            const row = Array.from(comparison.querySelectorAll('[data-supplier-cost]'))
                .find((candidate) => candidate.dataset.supplierId === supplier.value);
            const value = row ? Number(row.dataset.cost) : NaN;
            if (row && Number.isFinite(value) && value >= 0) {
                if (!manuallyEdited) cost.value = row.dataset.cost;
                reference.textContent = `${form.dataset.referenceLabel} ${supplier.selectedOptions[0]?.textContent.trim() || ''} · ${row.dataset.costDate}`;
            } else {
                if (!manuallyEdited) cost.value = '';
                reference.textContent = form.dataset.unknownCost;
            }
            if (manuallyEdited) reference.textContent = `${form.dataset.manualCost} ${reference.textContent}`;
        };

        supplier.addEventListener('change', () => {
            manuallyEdited = false;
            cost.value = '';
            suggestCost();
        });
        cost.addEventListener('input', () => {
            manuallyEdited = true;
            suggestCost();
        });
        comparison.addEventListener('click', (event) => {
            const button = event.target.closest('[data-use-supplier-cost]');
            const row = button?.closest('[data-supplier-cost]');
            if (!row) return;
            supplier.value = row.dataset.supplierId;
            supplier.dispatchEvent(new Event('change', { bubbles: true }));
        });
        product.addEventListener('change', async () => {
            const request = ++sequence;
            pending?.abort();
            pending = new AbortController();
            manuallyEdited = false;
            cost.value = '';
            reference.textContent = form.dataset.unknownCost;
            comparison.textContent = form.dataset.loading;
            if (!product.value) {
                comparison.textContent = '';
                return;
            }
            const url = new URL(form.dataset.costUrl, window.location.href);
            url.searchParams.set('product_id', product.value);
            try {
                const response = await fetch(url, { signal: pending.signal, credentials: 'same-origin', cache: 'no-store' });
                if (!response.ok || response.redirected) throw new Error('Supplier costs unavailable');
                const html = await response.text();
                if (request !== sequence) return;
                comparison.innerHTML = html;
                enableChoices();
                // Preserve a manual value entered while this request was in flight.
                suggestCost();
            } catch (error) {
                if (request !== sequence || error.name === 'AbortError') return;
                comparison.textContent = form.dataset.costError;
                reference.textContent = form.dataset.unknownCost;
            }
        });
        enableChoices();
        suggestCost();
    });
});
