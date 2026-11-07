    // Stripe cannot inherit page styles: use 'night' or 'stripe' to match the page.
    // An explicit data-theme or .dark/.light class wins; class-based themes drop .dark
    // for light mode, so then the painted background decides, then the OS preference.
    function stripeTheme(form, pixel) {
        const marked = form.closest('[data-theme="dark"], [data-theme="light"], .dark, .light');
        if (marked) return marked.matches('[data-theme="dark"], .dark') ? 'night' : 'stripe';
        for (let node = form; node; node = node.parentElement) {
            pixel.clearRect(0, 0, 1, 1);
            pixel.fillStyle = 'transparent';
            pixel.fillStyle = getComputedStyle(node).backgroundColor;
            pixel.fillRect(0, 0, 1, 1);
            const [r, g, b, a] = pixel.getImageData(0, 0, 1, 1).data;
            if (a === 255) return 0.299 * r + 0.587 * g + 0.114 * b < 128 ? 'night' : 'stripe';
        }
        return matchMedia('(prefers-color-scheme: dark)').matches ? 'night' : 'stripe';
    }

    // A theme can match the card fields to its own controls with these properties on or above the form:
    // --ae-stripe-primary, -text, -muted, -input (field background), -border, -danger and -radius.
    // Each one left unset keeps Stripe's own value.
    function stripeAppearance(form) {
        const pixel = document.createElement('canvas').getContext('2d', {willReadFrequently: true});
        const style = getComputedStyle(form);
        const read = name => style.getPropertyValue('--ae-stripe-' + name).trim();
        // Stripe takes plain colours, so the canvas resolves any CSS colour; an invalid value is skipped.
        const color = name => {
            const value = read(name);
            if (!value) return null;
            pixel.fillStyle = 'rgb(1, 2, 3)';
            const unset = pixel.fillStyle;
            pixel.fillStyle = value;
            return pixel.fillStyle === unset ? null : pixel.fillStyle;
        };
        const primary = color('primary'), text = color('text'), muted = color('muted'), input = color('input');
        const border = color('border'), danger = color('danger'), radius = read('radius');
        const variables = Object.fromEntries(Object.entries({
            colorPrimary: primary, colorText: text, colorTextSecondary: muted, colorTextPlaceholder: muted,
            colorBackground: input, colorDanger: danger, borderRadius: radius || null,
        }).filter(([, value]) => value));
        const rules = {};
        if (border) rules['.Input'] = {border: '1px solid ' + border, boxShadow: 'none'};
        if (border && primary) rules['.Input:focus'] = {borderColor: primary, boxShadow: '0 0 0 1px ' + primary};
        const appearance = {theme: stripeTheme(form, pixel)};
        if (Object.keys(variables).length) appearance.variables = variables;
        if (Object.keys(rules).length) appearance.rules = rules;
        return appearance;
    }

    // Follow later page theme changes; returns a cleanup for when the element is destroyed.
    function syncStripeTheme(form, elements) {
        let current = JSON.stringify(stripeAppearance(form));
        const update = () => {
            const appearance = stripeAppearance(form);
            if (JSON.stringify(appearance) === current) return;
            current = JSON.stringify(appearance);
            elements.update({appearance});
        };
        const observer = new MutationObserver(update);
        const scheme = matchMedia('(prefers-color-scheme: dark)');
        for (let node = form; node; node = node.parentElement) {
            observer.observe(node, {attributes: true, attributeFilter: ['class', 'data-theme', 'style']});
        }
        scheme.addEventListener('change', update);
        return () => {
            observer.disconnect();
            scheme.removeEventListener('change', update);
        };
    }
