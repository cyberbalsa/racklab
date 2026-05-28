/**
 * RackLab noVNC island.
 *
 * M4 sub-slice 4 ships the mount/unmount seam. M4 sub-slice 5 wires it
 * to a real `@novnc/novnc` `RFB` instance pointed at the
 * ProviderConsoleProxy localhost socket.
 *
 * The browser never holds Proxmox credentials. The grant JWT plus the
 * proxy-issued ticket are the only secrets handed to JS.
 */

export interface NoVncMountOptions {
    container: HTMLElement;
    websocketUrl: string;
    ticket: string;
    /** Optional ARIA live-region element used to announce status changes. */
    statusElement?: HTMLElement | null;
    /** Default Ctrl+Alt+Shift+Q. Pressed while the canvas has focus to release it. */
    focusReleaseShortcut?: string;
}

export interface NoVncSession {
    disconnect(): void;
    isConnected(): boolean;
}

/**
 * Mount a noVNC viewer into `container`. The current implementation is a
 * deterministic stub that emits a status string and exposes a disconnect()
 * callback. The real `@novnc/novnc` RFB instance plugs in via sub-slice 5.
 */
export function mountNoVncViewer(options: NoVncMountOptions): NoVncSession {
    const { container, websocketUrl, statusElement } = options;
    let connected = true;

    container.dataset.connectionState = 'connecting';
    container.dataset.targetWs = websocketUrl;
    announce(statusElement, 'Connecting noVNC console...');

    // Placeholder: in S5 we construct a real RFB instance here.
    queueMicrotask(() => {
        if (!connected) return;
        container.dataset.connectionState = 'connected';
        announce(statusElement, 'noVNC console connected.');
    });

    return {
        disconnect(): void {
            connected = false;
            container.dataset.connectionState = 'disconnected';
            announce(statusElement, 'noVNC console disconnected.');
        },
        isConnected(): boolean {
            return connected;
        },
    };
}

function announce(target: HTMLElement | null | undefined, message: string): void {
    if (target) {
        target.textContent = message;
    }
}

// Self-register so the Vite entry bundle keeps mountNoVncViewer through
// tree-shaking. The window.RackLab type lives in racklab-global.d.ts.
if (typeof window !== 'undefined') {
    window.RackLab = window.RackLab ?? {};
    window.RackLab.console = window.RackLab.console ?? {};
    window.RackLab.console.mountNoVncViewer = mountNoVncViewer;
}
