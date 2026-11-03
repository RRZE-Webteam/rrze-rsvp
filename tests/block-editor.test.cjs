const assert = require('node:assert/strict');
const { test } = require('node:test');
const { webcrypto } = require('node:crypto');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const ts = require('typescript');
const React = require('react');
const { renderToString } = require('react-dom/server');

/** Load the pure editor helpers from their TypeScript source with a controllable browser crypto API. */
function loadIdentity(crypto = webcrypto) {
    const source = readFileSync(path.join(__dirname, '../src/blocks/rsvp/identity.ts'), 'utf8');
    const result = ts.transpileModule(source, {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2020 },
    });
    const context = { exports: {}, crypto, Uint8Array };
    vm.runInNewContext(result.outputText, context);
    return context.exports;
}

/** Run the actual hooks' first render without effects, matching the moment before requests start. */
function initialOfferState(hookName, ...args) {
    const source = readFileSync(path.join(__dirname, '../src/blocks/rsvp/use-offers.ts'), 'utf8');
    const result = ts.transpileModule(source, {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2020 },
    });
    const context = { exports: {},
        /** Resolve only the external runtime dependencies needed by the isolated hook render. */
        require(name) {
        if (name === '@wordpress/element') return React;
        if (name === '@wordpress/i18n') return { __: message => message };
        if (name === '@wordpress/api-fetch') return {};
        throw new Error('Unexpected hook dependency: ' + name);
    } };
    vm.runInNewContext(result.outputText, context);
    let state;
    /** Capture the hook result without rendering the editor or running network effects. */
    function Probe() { state = context.exports[hookName](...args); return null; }
    renderToString(React.createElement(Probe));
    return state;
}

test('enabled offer searches announce loading on their first render, before effects run', () => {
    const state = initialOfferState('useOffers', 'course');
    assert.equal(state.loading, true);
    assert.equal(state.items.length, 0);
    assert.equal(state.error, '');
    assert.equal(initialOfferState('useOffers', '').loading, false);
});

test('a configured preview starts as loading without an empty or stale preview', () => {
    const state = initialOfferState('usePreview', 'seat', 42);
    assert.equal(state.loading, true);
    assert.equal(state.data, null);
    assert.equal(state.error, '');
    assert.equal(initialOfferState('usePreview', 'seat', 0).loading, false);
});

test('new and converted blocks receive separate valid routing UUIDs', () => {
    const { createInstanceId, isInstanceId } = loadIdentity();
    const generated = Array.from({ length: 50 }, () => createInstanceId());
    assert.equal(new Set(generated).size, generated.length);
    assert.ok(generated.every(value => isInstanceId(value)));
    for (const invalid of ['', 'client-id', generated[0] + '-2', '../' + generated[0]]) {
        assert.equal(isInstanceId(invalid), false);
    }
});

test('UUID generation supports browsers exposing getRandomValues without randomUUID', () => {
    const { createInstanceId, isInstanceId } = loadIdentity({ getRandomValues: value => webcrypto.getRandomValues(value) });
    const uuid = createInstanceId();
    assert.ok(isInstanceId(uuid));
    assert.match(uuid, /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/);
});

test('duplication preserves the mounted original even when a copy precedes it', () => {
    const { instanceOwner } = loadIdentity();
    assert.equal(instanceOwner(['original', 'copy'], 'original'), 'original');
    assert.equal(instanceOwner(['copy', 'original'], 'original'), 'original');
    assert.equal(instanceOwner(['first', 'second']), 'first');
    assert.equal(instanceOwner(['remaining-copy'], 'deleted-original'), 'remaining-copy');
    assert.equal(instanceOwner([]), undefined);
});

test('legacy course conversion keeps the chosen course and discards unrelated attributes', () => {
    const { legacyCourseAttributes, isInstanceId } = loadIdentity();
    const original = { courseId: 42, unrelated: 'not copied' };
    const converted = legacyCourseAttributes(original);
    assert.equal(converted.mode, 'course');
    assert.equal(converted.resourceId, 42);
    assert.ok(isInstanceId(converted.instanceId));
    assert.deepEqual(Object.keys(converted).sort(), ['instanceId', 'mode', 'resourceId']);
    assert.deepEqual(original, { courseId: 42, unrelated: 'not copied' });
    assert.equal(legacyCourseAttributes({ courseId: '42' }).resourceId, 42);
    for (const value of [undefined, null, true, -3, 1.5, Infinity, '42x', {}, Number.MAX_SAFE_INTEGER + 1]) {
        assert.equal(legacyCourseAttributes({ courseId: value }).resourceId, 0);
    }
});
