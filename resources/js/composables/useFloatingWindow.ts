export interface FloatingWindowOptions {
    width?: number;
    height?: number;
    title?: string;
    isHtml?: boolean;
}

/**
 * Vue 3 Composable for opening dynamic chromeless floating windows.
 */
export function useFloatingWindow() {
    /**
     * Open any URL or raw HTML in a standalone chromeless window.
     *
     * @param urlOrHtml - Target URL path OR raw HTML string
     * @param options - Window configuration
     * @returns Window instance or null
     */
    function openWindow(urlOrHtml: string, options: FloatingWindowOptions = {}): Window | null {
        const width = options.width ?? 1100;
        const height = options.height ?? 750;
        const title = options.title ?? ('Win_' + Math.random().toString(36).substring(2, 9));
        const isHtml = options.isHtml ?? false;

        const left = Math.round((screen.width - width) / 2);
        const top = Math.round((screen.height - height) / 2);

        const features = [
            `width=${width}`,
            `height=${height}`,
            `left=${left}`,
            `top=${top}`,
            'popup=yes',        // Strips tabs & browser navigation bar in modern browsers
            'menubar=no',
            'toolbar=no',
            'location=no',
            'status=no',
            'resizable=yes',
            'scrollbars=yes'
        ].join(',');

        let target = urlOrHtml;
        if (isHtml) {
            const blob = new Blob([urlOrHtml], { type: 'text/html' });
            target = URL.createObjectURL(blob);
        }

        return window.open(target, title, features);
    }

    /**
     * Helper specifically for rendering in-memory HTML strings dynamically.
     */
    function openHtml(htmlString: string, options: Omit<FloatingWindowOptions, 'isHtml'> = {}): Window | null {
        return openWindow(htmlString, { ...options, isHtml: true });
    }

    return {
        openWindow,
        openHtml,
    };
}
