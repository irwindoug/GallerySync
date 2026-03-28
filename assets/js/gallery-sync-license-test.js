document.addEventListener('DOMContentLoaded', () => {
    const validateButton = document.getElementById('gallery-sync-refresh-license-btn');
    const purchaseButton = document.getElementById('gallery-sync-purchase-license-btn');
    const result = document.getElementById('gallery-sync-refresh-license-result');
    const input = document.getElementById('gallery-sync-license-key');

    if (!result || !window.GallerySyncCommon) {
        return;
    }

    const setResult = (text, tone) => {
        result.textContent = text;
        result.style.color = tone === 'success' ? '#2d7a2d' : tone === 'error' ? '#9b2c2c' : '#6b7280';
    };

    if (validateButton) {
        validateButton.addEventListener('click', async () => {
            const licenseKey = input ? input.value.trim() : '';

            if (!licenseKey) {
                setResult('Enter a license key to validate.', 'error');
                return;
            }

            setResult('Validating license...', 'neutral');

            try {
                const data = await GallerySyncCommon.apiFetch(
                    '/license/validate',
                    {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ license_key: licenseKey }),
                    },
                    false
                );

                if (data && data.valid) {
                    setResult('License is active.', 'success');
                } else {
                    const reason = data && data.error ? String(data.error) : 'invalid';
                    setResult('License inactive (' + reason + ').', 'error');
                }
            } catch (err) {
                setResult('Validation failed. Check API settings.', 'error');
            }
        });
    }

    if (purchaseButton) {
        purchaseButton.addEventListener('click', async () => {
            setResult('Creating checkout session...', 'neutral');

            try {
                const data = await GallerySyncCommon.apiFetch(
                    '/license/checkout-session',
                    {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({}),
                    },
                    false
                );

                if (data && data.checkout_url) {
                    window.open(data.checkout_url, '_blank', 'noopener');
                    setResult('Checkout opened in a new tab.', 'success');
                    return;
                }

                setResult('Checkout URL was not returned.', 'error');
            } catch (err) {
                setResult('Failed to start checkout.', 'error');
            }
        });
    }
});
