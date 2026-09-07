const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync('public/scripts/app.js', 'utf8');
const start = source.indexOf('    const syncOperationLines =');
const end = source.indexOf('    document.querySelectorAll(".dynamic-operation-form")', start);
const context = vm.createContext({});
vm.runInContext(source.slice(start, end) + '\nglobalThis.sync = syncOperationLines;', context);
function row(type, qty, price, purchase, discount = 0) {
    const selected = {dataset: {productType: type, purchasePrice: String(purchase)}};
    const fields = {
        '.line-product': {selectedOptions: [selected], addEventListener() {}},
        '.line-qty': {value: String(qty)}, '.line-price': {value: String(price)},
        '.line-discount': {value: String(discount)}, '.line-total': {},
    };
    return {dataset: {lineType: type === 'stockable' ? 'product' : 'service'}, querySelector: key => fields[key]};
}
for (const [rows, expectedTotal, expectedMargin] of [
    [[row('service', 1, 100, 0)], 100, 100],
    [[row('service', 2, 100, 0)], 200, 200],
    [[row('service', 1, 100, 0, 10)], 90, 90],
    [[row('stockable', 1, 150, 100)], 150, 50],
    [[row('stockable', 3, 150, 100)], 450, 150],
    [[row('stockable', 3, 150, 100), row('service', 1, 100, 0, 10)], 540, 240],
]) {
    const total = {}, margin = {};
    context.sync({querySelector: key => key === '[data-total-ttc]' ? total : margin, querySelectorAll: () => rows});
    assert.equal(total.textContent, `${expectedTotal.toFixed(2)} DH`);
    assert.equal(margin.textContent, `${expectedMargin.toFixed(2)} DH`);
}
console.log('TTC preview: 6 scenarios passed');
