import assert from 'node:assert/strict';
import { test } from 'node:test';
import { installUiFeedback, revealFeedback } from '../../resources/js/ui-feedback.js';

// Small browser-interface doubles keep these tests independent of a browser or DOM package.
function message(kind, visible = true) {
    return {
        kind, visible, events: [],
        getClientRects() { return this.visible ? [{}] : []; },
        setAttribute(...args) { this.events.push(['attribute', ...args]); },
        focus(options) { this.events.push(['focus', options]); },
        scrollIntoView(options) { this.events.push(['scroll', options]); },
    };
}

function root(elements = [], dialog = null) {
    return {
        querySelector(selector) {
            assert.equal(selector, 'dialog[open]');
            return dialog?.open ? dialog : null;
        },
        querySelectorAll(selector) {
            const kinds = selector.includes('data-action-error') ? ['error', 'flux-error'] : ['success'];
            return elements.filter((element) => kinds.includes(element.kind));
        },
    };
}

function modal({ open = true, height = 540, styleHeight = '', elements = [] } = {}) {
    return Object.assign(root(elements), { open, offsetHeight: height, style: { height: styleHeight } });
}

function assertRevealed(element, block = 'nearest') {
    assert.deepEqual(element.events, [
        ['attribute', 'tabindex', '-1'],
        ['focus', { preventScroll: true }],
        ['scroll', { block, behavior: 'instant' }],
    ]);
}

function runtime(page = root(), dialogList = []) {
    const frames = [];
    const listeners = new Map();
    let mutation;
    let commit;
    let observation;
    const queryMessages = page.querySelectorAll;
    const document = Object.assign(page, {
        body: {},
        addEventListener(name, callback) { listeners.set(name, callback); },
        querySelectorAll(selector) {
            if (selector.startsWith('[data-flux-modal]')) {
                assert.equal(selector, '[data-flux-modal] > dialog:not([data-flux-flyout]):not([data-flux-modal-overflow])');
                return dialogList;
            }
            return queryMessages(selector);
        },
    });
    installUiFeedback({
        document,
        Livewire: { hook(name, callback) { assert.equal(name, 'commit'); commit = callback; } },
        MutationObserver: class {
            constructor(callback) { mutation = callback; }
            observe(target, options) { observation = { target, options }; }
        },
        requestAnimationFrame(callback) { frames.push(callback); },
    });
    return {
        document, listeners, frames, dialogList,
        mutate: () => mutation(),
        observation: () => observation,
        commit(methods, component = page) {
            let success;
            commit({ component: { el: component }, commit: { calls: methods.map((method) => ({ method })) }, succeed(callback) { success = callback; } });
            return success;
        },
        paint() { frames.splice(0).forEach((callback) => callback()); },
    };
}

test('page errors take priority over successes and use minimal scrolling', () => {
    const error = message('error');
    const success = message('success');
    revealFeedback(root([success, error]));
    assertRevealed(error);
    assert.deepEqual(success.events, []);
});

test('hidden errors and successes are skipped; the first visible success is revealed', () => {
    const hiddenError = message('error', false);
    const hiddenSuccess = message('success', false);
    const success = message('success');
    const laterSuccess = message('success');
    revealFeedback(root([hiddenError, hiddenSuccess, success, laterSuccess]));
    assertRevealed(success);
    for (const element of [hiddenError, hiddenSuccess, laterSuccess]) assert.deepEqual(element.events, []);
});

test('the first visible Flux validation error is revealed inside the open dialog', () => {
    const hidden = message('error', false);
    const error = message('flux-error');
    const laterError = message('error');
    const pageError = message('error');
    revealFeedback(root([pageError], modal({ elements: [hidden, error, laterError] })));
    assertRevealed(error, 'nearest');
    for (const element of [hidden, laterError, pageError]) assert.deepEqual(element.events, []);
});

test('dialog success is revealed within the dialog without focusing page feedback', () => {
    const success = message('success');
    const pageSuccess = message('success');
    revealFeedback(root([pageSuccess], modal({ elements: [success] })));
    assertRevealed(success, 'nearest');
    assert.deepEqual(pageSuccess.events, []);
});

test('an open dialog without feedback never steals focus for a background message', () => {
    const success = message('success');
    revealFeedback(root([success], modal()));
    assert.deepEqual(success.events, []);
    revealFeedback(root());
});

test('closed dialog validation is ignored and page feedback is revealed', () => {
    const hiddenError = message('error', false);
    const success = message('success');
    revealFeedback(root([hiddenError, success], modal({ open: false, elements: [hiddenError] })));
    assertRevealed(success);
    assert.deepEqual(hiddenError.events, []);
});

test('modal content and validation changes retain opening height until close; reopening measures afresh', () => {
    const dialog = modal({ styleHeight: 'auto' });
    const closed = modal({ open: false, height: 400 });
    const app = runtime(root(), [dialog, closed]);
    assert.equal(dialog.style.height, '540px');
    assert.equal(closed.style.height, '');
    assert.deepEqual(app.observation(), {
        target: app.document.body,
        options: { subtree: true, childList: true, attributes: true, attributeFilter: ['open'] },
    });
    dialog.offsetHeight = 300;
    app.mutate();
    assert.equal(dialog.style.height, '540px');
    dialog.offsetHeight = 800;
    app.mutate();
    assert.equal(dialog.style.height, '540px');
    dialog.open = false;
    app.mutate();
    assert.equal(dialog.style.height, 'auto');
    app.mutate();
    assert.equal(dialog.style.height, 'auto');
    dialog.open = true;
    app.mutate();
    assert.equal(dialog.style.height, '800px');
    closed.open = true;
    app.mutate();
    assert.equal(closed.style.height, '400px');
    closed.open = false;
    app.mutate();
    assert.equal(closed.style.height, '');
});

test('dialogs introduced by navigation are stabilised when opened', () => {
    const app = runtime();
    const dialog = modal({ height: 220 });
    app.dialogList.push(dialog);
    app.mutate();
    assert.equal(dialog.style.height, '220px');
});

test('successful action feedback is processed after render and modal closure', () => {
    const success = message('success');
    const error = message('error');
    const dialog = modal({ elements: [error] });
    const page = root([success], dialog);
    const app = runtime(page);
    const succeed = app.commit(['save']);
    assert.equal(app.frames.length, 0);
    succeed();
    assert.deepEqual(success.events, []);
    dialog.open = false;
    app.paint();
    assertRevealed(success);
    assert.deepEqual(error.events, []);
});

test('failed validation leaves the modal open and brings its errors into view', () => {
    const error = message('error');
    const page = root([], modal({ elements: [error] }));
    const app = runtime(page);
    app.commit(['save'])();
    app.paint();
    assertRevealed(error, 'nearest');
});

test('field updates, polling and refreshes leave focus and scrolling alone', () => {
    const success = message('success');
    const app = runtime(root([success]));
    for (const methods of [[], ['$refresh'], ['checkImportStatus'], ['$refresh', 'checkImportStatus']]) {
        assert.equal(app.commit(methods), undefined);
    }
    app.paint();
    assert.equal(app.frames.length, 0);
    assert.deepEqual(success.events, []);
});

test('an action batched with a field refresh still reveals feedback', () => {
    const error = message('error');
    const app = runtime(root([error]));
    app.commit(['$refresh', 'confirmDelete'])();
    app.paint();
    assertRevealed(error);
});

test('transport failures do not reveal stale feedback without a successful response', () => {
    const success = message('success');
    const app = runtime(root([success]));
    app.commit(['save']);
    app.paint();
    assert.deepEqual(success.events, []);
});

test('navigation reveals redirected success messages on the destination page', () => {
    const success = message('success');
    const app = runtime(root([success]));
    app.listeners.get('livewire:navigated')();
    assert.deepEqual(success.events, []);
    app.paint();
    assertRevealed(success);
});
