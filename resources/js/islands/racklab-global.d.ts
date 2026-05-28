/**
 * Single canonical declaration of the `window.RackLab` island registry.
 *
 * Each island module self-registers its mount function here; centralizing the
 * type avoids conflicting per-file `declare global` blocks (which tsc rejects
 * when more than one island is loaded into the same bundle).
 */
import type { mountNoVncViewer } from './novnc-viewer';
import type { mountRackLabRefs } from './racklab-ref';
import type { mountXtermConsole } from './xterm-console';

declare global {
    interface Window {
        RackLab?: {
            console?: {
                mountNoVncViewer?: typeof mountNoVncViewer;
                mountXtermConsole?: typeof mountXtermConsole;
            };
            docs?: {
                mountRackLabRefs?: typeof mountRackLabRefs;
            };
        };
    }
}

export {};
