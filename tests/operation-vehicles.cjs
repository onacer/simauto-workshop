const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');

// Run the real combobox and dependency handlers, including their change events.
class Element {
    constructor() {
        this.dataset = {};
        this.children = [];
        this.listeners = {};
        this.value = '';
        this.textContent = '';
        this.classes = new Set();
        this.classList = {
            add: value => this.classes.add(value),
            remove: value => this.classes.delete(value),
            contains: value => this.classes.has(value),
        };
    }
    addEventListener(type, callback) { (this.listeners[type] ||= []).push(callback); }
    dispatchEvent(event) {
        for (const callback of this.listeners[event.type] || []) callback(event);
    }
    setAttribute() {}
    select() {}
    append(...children) { this.children.push(...children); }
    appendChild(child) { this.append(child); }
    replaceChildren(...children) { this.children = children; }
    set innerHTML(value) { this.children = []; }
    insertAdjacentElement(position, element) { this.combo = element; }
    closest() { return this.form; }
    querySelectorAll() { return this.children; }
    contains(element) { return this.children.includes(element); }
}
class Select extends Element {
    constructor(options, value = '') { super(); this.children = options; this.value = value; }
    get options() { return this.children; }
    get selectedOptions() { return this.options.filter(option => option.value === this.value); }
}
function option(value, text, clientId) {
    const item = new Element();
    item.value = value;
    item.textContent = text;
    if (clientId !== undefined) item.dataset.clientId = clientId;
    return item;
}
function setup(clientId = '', vehicleId = '') {
    const client = new Select([
        option('', 'Choisir le client'), option('1', 'Abdessamad'),
        option('2', 'Autre client'), option('3', 'Deux véhicules'), option('4', 'Sans véhicule'),
    ], clientId);
    client.dataset.operationClient = '';
    const vehicle = new Select([
        option('', 'Choisir le véhicule'),
        option('11', 'AA-11 Hyundai Accent', '1'),
        option('22', 'BB-22 Hyundai Accent', '2'),
        option('31', 'CC-31 Hyundai Accent', '3'),
        option('32', 'CC-32 Mercedes Classe C', '3'),
        option('99', 'Unlinked Hyundai Accent'),
    ], vehicleId);
    const form = {querySelector: () => client};
    client.form = vehicle.form = form;
    const document = new Element();
    document.documentElement = {dir: 'ltr'};
    document.createElement = () => new Element();
    document.querySelectorAll = selector => {
        if (selector === 'select[data-combobox]') return [client, vehicle];
        if (selector === '[data-vehicle-select]') return [vehicle];
        return [];
    };
    const window = new Element();
    const source = fs.readFileSync('public/scripts/app.js', 'utf8');
    const start = source.indexOf('    const normalize =');
    const end = source.indexOf('    const syncLiveSearch =', start);
    const context = vm.createContext({document, window, Event, queueMicrotask});
    vm.runInContext(source.slice(start, end) + '\nenhanceCombobox(); syncDependentSelects();', context);
    return {client, vehicle, window};
}
function fire(element, type, extra = {}) {
    element.dispatchEvent({type, preventDefault() {}, ...extra});
}
function input(select) { return select.combo.children[0]; }
function choices(select) { return select.combo.children[1].children; }
function choose(select, value) {
    fire(input(select), 'focus');
    const button = choices(select).find(item => item.dataset.value === value);
    assert.ok(button, `Missing choice ${value}`);
    fire(button, 'mousedown');
}
function vehicleIds(vehicle) { return vehicle.options.map(option => option.value).filter(Boolean); }

const {client, vehicle, window} = setup();
assert.deepEqual(vehicleIds(vehicle), [], 'No client must expose no vehicles');
choose(client, '1');
assert.deepEqual(vehicleIds(vehicle), ['11']);
assert.equal(vehicle.value, '11', 'A single linked vehicle is selected');
fire(input(vehicle), 'focus');
assert.deepEqual(choices(vehicle).map(item => item.dataset.value), ['', '11']);
input(vehicle).value = 'Hyundai Accent';
fire(input(vehicle), 'input');
assert.deepEqual(choices(vehicle).map(item => item.dataset.value), ['', '11'], 'A shared model never expands ownership');
const staleButton = choices(vehicle).find(item => item.dataset.value === '11');

choose(client, '2');
assert.deepEqual(vehicleIds(vehicle), ['22']);
assert.equal(vehicle.value, '22');
assert.equal(input(vehicle).value, 'BB-22 Hyundai Accent');
fire(staleButton, 'mousedown');
assert.equal(vehicle.value, '22', 'An obsolete choice cannot restore another client\'s vehicle');
choose(client, '3');
assert.deepEqual(vehicleIds(vehicle), ['31', '32']);
assert.equal(vehicle.value, '', 'Changing client clears the previous vehicle');
choose(vehicle, '32');
fire(input(vehicle), 'focus');
assert.deepEqual(choices(vehicle).map(item => item.dataset.value), ['', '31', '32'], 'Opening a selected field shows all linked vehicles');
input(client).value = 'Abdessamad';
fire(input(client), 'input');
assert.equal(client.value, '', 'Typing a different client invalidates the previous selection');
assert.equal(input(client).value, 'Abdessamad', 'The search text survives dependency refresh');
assert.deepEqual(vehicleIds(vehicle), []);
assert.equal(vehicle.value, '');
choose(client, '1');
choose(client, '4');
assert.deepEqual(vehicleIds(vehicle), []);
assert.equal(vehicle.value, '');
choose(client, '');
assert.deepEqual(vehicleIds(vehicle), []);

// Edit forms retain a matching saved vehicle; stale foreign vehicles are removed.
const edit = setup('3', '32');
assert.deepEqual(vehicleIds(edit.vehicle), ['31', '32']);
assert.equal(edit.vehicle.value, '32');
const stale = setup('3', '22');
assert.equal(stale.vehicle.value, '');
fire(input(edit.vehicle), 'focus');
input(edit.vehicle).value = 'Mercedes';
fire(input(edit.vehicle), 'input');
fire(input(edit.vehicle), 'keydown', {key: 'ArrowDown'});
fire(input(edit.vehicle), 'keydown', {key: 'ArrowDown'});
fire(input(edit.vehicle), 'keydown', {key: 'Enter'});
assert.equal(edit.vehicle.value, '32', 'Keyboard selection uses the filtered results');
input(edit.vehicle).value = 'unfinished search';
fire(input(edit.vehicle), 'input');
fire(input(edit.vehicle), 'blur');
assert.equal(input(edit.vehicle).value, 'CC-32 Mercedes Classe C', 'Unconfirmed text cannot impersonate a selected vehicle');
client.value = '2';
fire(window, 'pageshow');
assert.deepEqual(vehicleIds(vehicle), ['22'], 'History restoration resynchronizes ownership');
console.log('Operation vehicles: client selection, shared models, switching, edits and history passed');
