// racklab-ref status-pill island.
//
// The server renders each `[[kind:id]]` cross-link as
//   <racklab-ref data-kind data-id class="racklab-ref racklab-ref--pending">[[kind:id]]</racklab-ref>
// This island upgrades every pending element into a live, RBAC-filtered
// status pill by calling the resolver endpoint
//   GET /plugins/docs/refs/resolve/{kind}/{id}
// which returns { data: { status, label, url, detail, rbac_visible } }.
//
// Resolution is per-element and idempotent (an in-flight/own element is
// marked so it is not resolved twice). The Page Visibility API gates the
// optional refresh so hidden tabs do not poll.

type RefStatus = 'resolved' | 'redacted' | 'not_found' | 'unsupported';

interface ResolvedRefData {
    kind: string;
    id: string;
    status: RefStatus;
    label: string | null;
    url: string | null;
    detail: string | null;
    rbac_visible: boolean;
}

const STATE_CLASSES = [
    'racklab-ref--pending',
    'racklab-ref--resolved',
    'racklab-ref--redacted',
    'racklab-ref--missing',
    'racklab-ref--unsupported',
    'racklab-ref--error',
];

function setState(el: HTMLElement, state: string): void {
    el.classList.remove(...STATE_CLASSES);
    el.classList.add('racklab-ref', `racklab-ref--${state}`);
    el.dataset.refState = state;
}

function labelFor(kind: string): string {
    // Capitalise the kind for the redacted/missing fallbacks.
    return kind.charAt(0).toUpperCase() + kind.slice(1);
}

function applyResolved(el: HTMLElement, data: ResolvedRefData): void {
    if (data.status === 'resolved') {
        setState(el, 'resolved');
        const text = data.detail ? `${data.label} · ${data.detail}` : (data.label ?? `${data.kind}:${data.id}`);
        if (data.url) {
            const a = document.createElement('a');
            a.href = data.url;
            a.className = 'racklab-ref__link';
            a.textContent = text;
            el.replaceChildren(a);
        } else {
            el.textContent = text;
        }
        el.title = `${data.kind}: ${data.id}`;
        return;
    }

    if (data.status === 'redacted') {
        setState(el, 'redacted');
        el.textContent = `${labelFor(data.kind)} (redacted)`;
        el.title = 'You do not have access to this reference.';
        return;
    }

    if (data.status === 'not_found') {
        setState(el, 'missing');
        el.textContent = `${labelFor(data.kind)} (missing)`;
        el.title = 'This reference no longer exists.';
        return;
    }

    setState(el, 'unsupported');
    el.title = `Unsupported reference kind: ${data.kind}`;
}

async function resolveOne(el: HTMLElement): Promise<void> {
    const kind = el.dataset.kind;
    const id = el.dataset.id;
    if (!kind || !id || el.dataset.refResolving === 'true') {
        return;
    }
    el.dataset.refResolving = 'true';

    try {
        const res = await fetch(
            `/plugins/docs/refs/resolve/${encodeURIComponent(kind)}/${encodeURIComponent(id)}`,
            { headers: { Accept: 'application/json' }, credentials: 'same-origin' },
        );
        if (!res.ok) {
            setState(el, 'error');
            return;
        }
        const body = (await res.json()) as { data: ResolvedRefData };
        applyResolved(el, body.data);
    } catch {
        setState(el, 'error');
    } finally {
        el.dataset.refResolving = 'false';
    }
}

export function mountRackLabRefs(root: ParentNode = document): void {
    const els = root.querySelectorAll<HTMLElement>('racklab-ref.racklab-ref--pending');
    els.forEach(el => { void resolveOne(el); });
}

declare global {
    interface Window {
        RackLab?: {
            console?: {
                mountNoVncViewer?: (options: unknown) => unknown;
                mountXtermConsole?: (options: unknown) => unknown;
            };
            docs?: {
                mountRackLabRefs?: typeof mountRackLabRefs;
            };
        };
    }
}

if (typeof window !== 'undefined') {
    window.RackLab = window.RackLab ?? {};
    window.RackLab.docs = window.RackLab.docs ?? {};
    window.RackLab.docs.mountRackLabRefs = mountRackLabRefs;

    // Module scripts are deferred, so the DOM is parsed by now.
    mountRackLabRefs();
    // Re-scan after Livewire SPA navigations.
    document.addEventListener('livewire:navigated', () => mountRackLabRefs());
}
