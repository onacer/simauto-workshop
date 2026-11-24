const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');

// Exercise the shipped event handlers, including out-of-order network responses.
class Element {
    constructor(value = '') { this.value = value; this.listeners = {}; this.dataset = {}; this.hidden = true; }
    addEventListener(type, handler) { (this.listeners[type] ||= []).push(handler); }
    dispatchEvent(event) { for (const handler of this.listeners[event.type] || []) handler(event); return true; }
    async emit(type, event = {}) { await Promise.all((this.listeners[type] || []).map(handler => handler({type, ...event}))); }
}
const a = {dataset: {supplierId: '10', cost: '105', costDate: '2026-11-24 10:00:00'}};
const b = {dataset: {supplierId: '20', cost: '90', costDate: '2026-11-24 11:00:00'}};
const zero = {dataset: {supplierId: '10', cost: '0', costDate: '2026-11-24 12:00:00'}};
const product = new Element('1');
const supplier = new Element('');
Object.defineProperty(supplier, 'selectedOptions', {get: () => [{textContent: supplier.value === '10' ? 'A' : supplier.value === '20' ? 'B' : 'Unknown'}]});
const cost = new Element('');
const reference = new Element();
const comparison = new Element();
comparison.rows = [b, a];
comparison.buttons = [new Element(), new Element()];
comparison.querySelectorAll = selector => selector === '[data-supplier-cost]' ? comparison.rows : comparison.buttons;
Object.defineProperty(comparison, 'textContent', {get: () => comparison.text || '', set: value => { comparison.text = value; comparison.rows = []; }});
const htmlFixtures = {oil: [b, a], zero: [zero], none: []};
Object.defineProperty(comparison, 'innerHTML', {set: value => { comparison.rows = htmlFixtures[value]; }});
const form = new Element();
form.dataset = {costUrl: '/stock/supplier-costs', referenceLabel: 'Reference', manualCost: 'Manual', unknownCost: 'Unknown cost', loading: 'Loading', costError: 'Unavailable'};
const nodes = {'[name="product_id"]': product, '[name="supplier_id"]': supplier, '[name="unit_cost"]': cost, '[data-supplier-costs]': comparison, '[data-cost-reference]': reference};
form.querySelector = selector => nodes[selector];
const pending = [];
vm.runInNewContext(fs.readFileSync('public/scripts/stock-supplier-costs.js', 'utf8'), {
    document: {addEventListener: (type, callback) => callback(), querySelectorAll: () => [form]},
    window: {location: {href: 'http://localhost/stock'}}, URL, Event, AbortController,
    fetch: (url, options) => new Promise((resolve, reject) => pending.push({url, options, resolve, reject})),
});
const reply = (request, html) => request.resolve({ok: true, redirected: false, text: async () => html});

(async () => {
    assert.equal(supplier.value, '', 'Cheapest supplier must not be chosen automatically.');
    assert.equal(cost.value, '');
    assert.ok(comparison.buttons.every(button => button.hidden === false));

    supplier.value = '10';
    await supplier.emit('change');
    assert.equal(cost.value, '105');
    assert.match(reference.textContent, /A.*2026-11-24/);
    supplier.value = '20';
    await supplier.emit('change');
    assert.equal(cost.value, '90');
    cost.value = '92.50';
    await cost.emit('input');
    assert.equal(cost.value, '92.50');
    assert.match(reference.textContent, /^Manual/);
    supplier.value = '30';
    await supplier.emit('change');
    assert.equal(cost.value, '', 'A supplier without history must not inherit another supplier’s price.');

    const button = {closest: () => a};
    await comparison.emit('click', {target: {closest: () => button}});
    assert.equal(supplier.value, '10');
    assert.equal(cost.value, '105');

    product.value = '2';
    const oldLoad = product.emit('change');
    assert.equal(cost.value, '', 'Product changes clear the previous cost immediately.');
    assert.equal(pending[0].url.searchParams.get('product_id'), '2');
    product.value = '3';
    const latestLoad = product.emit('change');
    assert.equal(pending[0].options.signal.aborted, true);
    reply(pending[1], 'zero');
    await latestLoad;
    assert.equal(cost.value, '0', 'Explicit zero is a known cost.');
    reply(pending[0], 'oil');
    await oldLoad;
    assert.equal(cost.value, '0', 'An older response must not replace the selected product’s costs.');

    product.value = '4';
    const manualLoad = product.emit('change');
    cost.value = '77';
    await cost.emit('input');
    reply(pending[2], 'oil');
    await manualLoad;
    assert.equal(cost.value, '77', 'Keep a manual value entered while loading.');
    assert.match(reference.textContent, /^Manual/);

    product.value = '5';
    const switchingSupplier = product.emit('change');
    supplier.value = '20';
    await supplier.emit('change');
    reply(pending[3], 'oil');
    await switchingSupplier;
    assert.equal(cost.value, '90', 'Resolve against the supplier selected now, not the one selected when loading began.');

    product.value = '6';
    const unknownLoad = product.emit('change');
    reply(pending[4], 'none');
    await unknownLoad;
    assert.equal(cost.value, '');
    assert.equal(reference.textContent, 'Unknown cost');

    product.value = '7';
    const failedLoad = product.emit('change');
    cost.value = '65';
    await cost.emit('input');
    pending[5].reject(new Error('Offline'));
    await failedLoad;
    assert.equal(cost.value, '65');
    assert.equal(comparison.textContent, 'Unavailable');

    product.value = '8';
    const redirectedLoad = product.emit('change');
    pending[6].resolve({ok: true, redirected: true});
    await redirectedLoad;
    assert.equal(comparison.textContent, 'Unavailable', 'Do not insert a login page after session expiration.');
    assert.equal(cost.value, '');
    console.log('Supplier cost interactions: selection, zero, manual editing, races, supplier changes and errors passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
