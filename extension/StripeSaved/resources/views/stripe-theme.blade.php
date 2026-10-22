    // Stripe cannot inherit page styles: use 'night' or 'stripe' to match the page.
    // An explicit data-theme or .dark/.light class wins; class-based themes drop .dark
    // for light mode, so then the painted background decides, then the OS preference.
    function stripeAppearance(form) {
        const marked = form.closest('[data-theme="dark"], [data-theme="light"], .dark, .light');
        if (marked) return {theme: marked.matches('[data-theme="dark"], .dark') ? 'night' : 'stripe'};
        const pixel = document.createElement('canvas').getContext('2d', {willReadFrequently: true});
        for (let node = form; node; node = node.parentElement) {
            pixel.clearRect(0, 0, 1, 1);
            pixel.fillStyle = 'transparent';
            pixel.fillStyle = getComputedStyle(node).backgroundColor;
            pixel.fillRect(0, 0, 1, 1);
            const [r, g, b, a] = pixel.getImageData(0, 0, 1, 1).data;
            if (a === 255) return {theme: 0.299 * r + 0.587 * g + 0.114 * b < 128 ? 'night' : 'stripe'};
        }
        return {theme: matchMedia('(prefers-color-scheme: dark)').matches ? 'night' : 'stripe'};
    }

    // Follow later page theme changes; returns a cleanup for when the element is destroyed.
    function syncStripeTheme(form, elements) {
        let theme = stripeAppearance(form).theme;
        const update = () => {
            const appearance = stripeAppearance(form);
            if (appearance.theme === theme) return;
            theme = appearance.theme;
            elements.update({appearance});
        };
        const observer = new MutationObserver(update);
        const scheme = matchMedia('(prefers-color-scheme: dark)');
        for (const node of [document.documentElement, document.body]) {
            observer.observe(node, {attributes: true, attributeFilter: ['class', 'data-theme', 'style']});
        }
        scheme.addEventListener('change', update);
        return () => {
            observer.disconnect();
            scheme.removeEventListener('change', update);
        };
    }
