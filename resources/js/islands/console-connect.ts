/**
 * RackLab console-connect glue.
 *
 * Binds the "Connect" button rendered by the DeploymentConsolePane Livewire
 * component to the real, audited console-grant flow:
 *
 *   click → POST /api/v1/deployments/{id}/console-grant (session + CSRF)
 *         → mount the xterm / noVNC island seam with the issued grant.
 *
 * The browser never holds Proxmox credentials — only the short-lived,
 * proxy-scoped grant JWT. Mounting the island transitions the pane through
 * connecting → connected (the real `@xterm/xterm` / `@novnc/novnc` WebSocket
 * attach against the console proxy is the island's own responsibility).
 */
import { mountNoVncViewer, type NoVncSession } from './novnc-viewer';
import { mountXtermConsole, type XtermSession } from './xterm-console';

interface ConsoleGrant {
    grant_id: string;
    deployment_id: string;
    console_kind: string;
    jwt: string;
}

const BOUND = 'data-racklab-console-bound';

export function initConsoleConnect(root: ParentNode = document): void {
    const panes = root.querySelectorAll<HTMLElement>('[data-testid="console-pane"][data-deployment-id]');

    panes.forEach((pane) => {
        const button = pane.querySelector<HTMLButtonElement>('[data-testid="console-connect"]');

        if (button === null || button.getAttribute(BOUND) === 'true') {
            return;
        }

        button.setAttribute(BOUND, 'true');
        button.addEventListener('click', () => {
            void connect(pane, button);
        });
    });
}

async function connect(pane: HTMLElement, button: HTMLButtonElement): Promise<void> {
    const deploymentId = pane.dataset.deploymentId ?? '';
    const consoleKind = pane.dataset.consoleKind ?? 'vnc';
    const status = pane.querySelector<HTMLElement>('[data-testid="console-status"]');

    if (deploymentId === '') {
        return;
    }

    button.disabled = true;
    setStatus(pane, status, 'connecting', 'Requesting console grant...');

    try {
        const grant = await requestGrant(deploymentId, consoleKind);
        const container = consoleContainer(pane, consoleKind);

        if (container === null) {
            setStatus(pane, status, 'error', 'Console surface unavailable.');

            return;
        }

        // The console proxy WebSocket path is owned by the proxy deployment;
        // pass the grant so the island attaches once the proxy URL is known.
        const websocketUrl = consoleProxyUrl(grant.grant_id);

        const session: XtermSession | NoVncSession = consoleKind === 'vnc'
            ? mountNoVncViewer({ container, websocketUrl, ticket: grant.jwt, statusElement: status })
            : mountXtermConsole({ container, websocketUrl, ticket: grant.jwt, statusElement: status });

        pane.dataset.connectionState = session.isConnected() ? 'connected' : 'connecting';
    } catch {
        button.disabled = false;
        setStatus(pane, status, 'error', 'Could not start the console session.');
    }
}

async function requestGrant(deploymentId: string, consoleKind: string): Promise<ConsoleGrant> {
    const response = await fetch(`/deployments/${encodeURIComponent(deploymentId)}/console-grant`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({ console_kind: consoleKind }),
    });

    if (!response.ok) {
        throw new Error(`console grant failed: ${response.status}`);
    }

    const payload = (await response.json()) as { data: ConsoleGrant };

    return payload.data;
}

function consoleContainer(pane: HTMLElement, consoleKind: string): HTMLElement | null {
    const selector = consoleKind === 'vnc' ? '[data-testid="novnc-viewer"]' : '[data-testid="xterm-console"]';

    return pane.querySelector<HTMLElement>(selector);
}

function consoleProxyUrl(grantId: string): string {
    const scheme = window.location.protocol === 'https:' ? 'wss:' : 'ws:';

    return `${scheme}//${window.location.host}/console-proxy/${encodeURIComponent(grantId)}`;
}

function setStatus(pane: HTMLElement, status: HTMLElement | null, state: string, message: string): void {
    pane.dataset.connectionState = state;

    if (status !== null) {
        status.dataset.status = state;
        status.textContent = message;
    }
}

function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

if (typeof window !== 'undefined') {
    const boot = (): void => initConsoleConnect();

    document.addEventListener('DOMContentLoaded', boot);
    document.addEventListener('livewire:navigated', boot);

    // Already-interactive page (e.g. wire:navigate into the detail page).
    if (document.readyState !== 'loading') {
        boot();
    }
}
