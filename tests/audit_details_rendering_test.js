'use strict';

// Node.js built-ins only: no browser, npm packages, database, or HTTP requests.
// Run the actual modal function against a minimal DOM test double. Every HTML
// parser sink throws; this tests safe rendering, NOT visual browser layout.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

const projectRoot = process.argv[2] || path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(projectRoot, 'audit_logs.php'), 'utf8');
const start = source.indexOf('function viewAuditDetails(btn) {');
const end = source.indexOf('function openExportModal()', start);
assert.ok(start >= 0 && end > start, 'Modal function boundaries changed; update this test.');
const modalCode = source.slice(start, end);

class TestNode {
    constructor(tagName = 'div') {
        this.tagName = tagName;
        this.className = '';
        this.childNodes = [];
        this.value = '';
    }
    set textContent(value) {
        this.childNodes = [];
        this.value = String(value);
    }
    get textContent() {
        return this.value + this.childNodes.map(child => child.textContent).join('');
    }
    set innerHTML(value) { throw new Error('innerHTML is not allowed in Audit Details.'); }
    set outerHTML(value) { throw new Error('outerHTML is not allowed in Audit Details.'); }
    insertAdjacentHTML() { throw new Error('HTML parsing is not allowed in Audit Details.'); }
    replaceChildren(...nodes) {
        this.value = '';
        this.childNodes = nodes;
    }
    appendChild(node) {
        assert.ok(node instanceof TestNode, 'Only DOM nodes can be appended.');
        this.childNodes.push(node);
        return node;
    }
    cloneNode(deep) {
        const copy = new TestNode(this.tagName);
        copy.className = this.className;
        copy.value = this.value;
        if (deep) copy.childNodes = this.childNodes.map(child => child.cloneNode(true));
        return copy;
    }
}

function fixture() {
    const nodes = new Map();
    const get = id => {
        if (!nodes.has(id)) nodes.set(id, new TestNode());
        return nodes.get(id);
    };
    const shown = [];
    const context = vm.createContext({
        document: { getElementById: get, createElement: tag => new TestNode(tag) },
        $: selector => ({
            text(value) { get(selector.slice(1)).textContent = value; },
            html() { throw new Error('jQuery.html is not allowed in Audit Details.'); }
        }),
        bootstrap: { Modal: class { constructor(node) { this.node = node; } show() { shown.push(this.node); } } }
    });
    vm.runInContext(modalCode, context, { timeout: 1000 });
    const timeline = new TestNode();
    const subject = new TestNode('span');
    subject.className = 'fw-bold text-main';
    subject.textContent = 'Mika & Co.';
    const text = new TestNode('#text');
    text.textContent = ' updated a record.';
    timeline.appendChild(subject);
    timeline.appendChild(text);
    const btn = {
        dataset: { logId: '17', user: 'Mika & Co.', action: 'UPDATE_RECORD', ip: '127.0.0.1',
            time: 'Sep 02, 2026', module: 'System Operations', desc: 'Status changed from Pending to Approved',
            sentence: '<img src=x onerror=alert(1)>' },
        querySelector: selector => selector === '.timeline-desc' ? timeline : null
    };
    const show = () => context.viewAuditDetails(btn);
    const values = () => get('techChangesSection').childNodes[0].childNodes.map(column => column.childNodes[0].childNodes[1]);
    return { get, btn, timeline, shown, show, values };
}

let passed = 0;
let failed = 0;
function test(label, check) {
    try { check(); passed++; console.log('PASS: ' + label); }
    catch (error) { failed++; console.error('FAIL: ' + label + ': ' + error.message); }
}

test('normal previous and updated values remain visible', () => {
    const f = fixture(); f.show();
    assert.deepEqual(f.values().map(n => n.textContent), ['Pending', 'Approved']);
    assert.equal(f.get('techDesc').textContent, f.btn.dataset.desc);
});

test('markup-like old/new values remain literal leaf text', () => {
    const f = fixture();
    const oldValue = '<img src=x onerror=alert(1)>';
    const newValue = '<svg onload=alert(1)></svg>';
    f.btn.dataset.desc = `Value changed from ${oldValue} to ${newValue}`; f.show();
    assert.deepEqual(f.values().map(n => n.textContent), [oldValue, newValue]);
    assert.ok(f.values().every(n => n.childNodes.length === 0));
});

test('quotes entities symbols and Unicode are not decoded or lost', () => {
    const f = fixture();
    const oldValue = '"A&B" <5 &lt;img&gt;';
    const newValue = "₱1,250 — José's folder";
    f.btn.dataset.desc = `Value changed from ${oldValue} to ${newValue}`; f.show();
    assert.deepEqual(f.values().map(n => n.textContent), [oldValue, newValue]);
});

test('non-change record clears values from the previous modal', () => {
    const f = fixture(); f.show();
    f.btn.dataset.desc = 'User signed in'; f.show();
    assert.equal(f.get('techChangesSection').childNodes.length, 0);
});

test('empty values and missing descriptions do not retain stale text', () => {
    const f = fixture();
    f.btn.dataset.desc = 'changed from  to '; f.show();
    assert.deepEqual(f.values().map(n => n.textContent), ['', '']);
    delete f.btn.dataset.desc; f.show();
    assert.equal(f.get('techChangesSection').childNodes.length, 0);
    assert.equal(f.get('techDesc').textContent, '');
});

test('two-column layout font and border classes are preserved', () => {
    const f = fixture(); f.show();
    const row = f.get('techChangesSection').childNodes[0];
    assert.equal(row.className, 'row mb-4 gx-3');
    assert.deepEqual(row.childNodes.map(n => n.className), ['col-sm-6', 'col-sm-6 mt-2 mt-sm-0']);
    for (const [i, color] of ['danger', 'success'].entries()) {
        const card = row.childNodes[i].childNodes[0];
        assert.equal(card.className, `border rounded p-3 bg-white h-100 shadow-sm border-light border-start border-${color} border-3`);
        assert.equal(card.childNodes[0].className, `fw-bold mb-1 fs-xs text-${color} text-uppercase`);
        assert.equal(card.childNodes[0].textContent, i === 0 ? 'PREVIOUS VALUE' : 'UPDATED VALUE');
        assert.equal(card.childNodes[1].className, 'fw-semibold mt-2 fs-md text-main');
    }
});

test('summary clones formatted children without reparsing data-sentence', () => {
    const f = fixture(); f.show();
    const summary = f.get('techHumanReadable');
    assert.equal(summary.textContent, 'Mika & Co. updated a record.');
    assert.equal(summary.childNodes.length, 2);
    assert.equal(summary.childNodes[0].className, 'fw-bold text-main');
    assert.notEqual(summary.childNodes[0], f.timeline.childNodes[0]);
    assert.equal(f.timeline.childNodes.length, 2);
});

test('summary fallback is plain text when timeline is absent', () => {
    const f = fixture(); f.btn.querySelector = () => null;
    f.btn.dataset.desc = '<img src=x onerror=alert(1)>'; f.show();
    assert.equal(f.get('techHumanReadable').textContent, f.btn.dataset.desc);
    assert.equal(f.get('techHumanReadable').childNodes.length, 0);
});

test('metadata remains text and modal still opens', () => {
    const f = fixture(); f.btn.dataset.user = '<img src=x>'; f.show();
    assert.equal(f.get('techUser').textContent, '<img src=x>');
    assert.equal(f.get('techLogId').textContent, '17');
    assert.equal(f.shown.length, 1);
    assert.equal(f.shown[0], f.get('auditDetailsModal'));
});

test('opening another changed record replaces rather than duplicates cards', () => {
    const f = fixture(); f.show();
    f.btn.dataset.desc = 'changed from Approved to Released'; f.show();
    assert.equal(f.get('techChangesSection').childNodes.length, 1);
    assert.deepEqual(f.values().map(n => n.textContent), ['Approved', 'Released']);
    assert.equal(f.get('techHumanReadable').childNodes.length, 2);
});

console.log(`\nResult: ${passed} passed, ${failed} failed. Isolated DOM tests; no browser or database writes.`);
process.exitCode = failed ? 1 : 0;
