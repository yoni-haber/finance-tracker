const errors = '[data-action-error], [data-flux-error]';
const messages = '[data-action-feedback]';
const dialogs = '[data-flux-modal] > dialog:not([data-flux-flyout]):not([data-flux-modal-overflow])';

const firstVisible = (root, selector) => [...root.querySelectorAll(selector)]
    .find((element) => element.getClientRects().length > 0);

export function revealFeedback(root) {
    const dialog = root.querySelector('dialog[open]');
    const scope = dialog ?? root;
    const target = firstVisible(scope, errors) ?? firstVisible(scope, messages);

    if (!target) return;

    target.setAttribute('tabindex', '-1');
    target.focus({ preventScroll: true });
    target.scrollIntoView({ block: dialog ? 'nearest' : 'start', behavior: 'instant' });
}

export function installUiFeedback({ document, Livewire, MutationObserver, requestAnimationFrame }) {
    const heights = new WeakMap();
    const stabiliseDialogs = () => {
        document.querySelectorAll(dialogs).forEach((dialog) => {
            if (dialog.open && !heights.has(dialog)) {
                heights.set(dialog, dialog.style.height);
                // Layout height excludes Flux's opening scale animation.
                dialog.style.height = `${dialog.offsetHeight}px`;
            } else if (!dialog.open && heights.has(dialog)) {
                dialog.style.height = heights.get(dialog);
                heights.delete(dialog);
            }
        });
    };

    const observer = new MutationObserver(stabiliseDialogs);
    observer.observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['open'] });
    stabiliseDialogs();

    Livewire.hook('commit', ({ component, commit, succeed }) => {
        // Field updates and background polling must not interrupt typing or scrolling.
        if (!commit.calls.some(({ method }) => !['$refresh', 'checkImportStatus'].includes(method))) return;

        succeed(() => requestAnimationFrame(() => revealFeedback(component.el)));
    });

    document.addEventListener('livewire:navigated', () => requestAnimationFrame(() => revealFeedback(document)));
}
