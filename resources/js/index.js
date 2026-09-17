// Push plugin — JavaScript library for NativePHP Mobile.
//
// Wraps the three `PushNotification.*` bridge functions so the plugin can be
// used from Inertia (Vue/React) the same way the core PHP facade is used from
// Livewire. Enrolling is asynchronous: the device token arrives as a native
// event — subscribe with `onToken()`.

/**
 * Low-level bridge call. Guarded so it is a no-op outside the native runtime.
 * @param {string} method
 * @param {Record<string, unknown>} params
 * @returns {Promise<any>}
 */
async function bridgeCall(method, params = {}) {
    const response = await fetch('/_native/api/call', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ method, params }),
    });

    return response.json();
}

/**
 * Ask for the notification permission and enroll the device (APNs on iOS, FCM
 * on Android). The token is delivered via the core's `TokenGenerated` event —
 * listen with `onToken()`.
 *
 * @param {{ id?: string }} [options] correlation id echoed back on the event
 * @returns {Promise<{ success: boolean }>}
 */
export async function requestPermission(options = {}) {
    await bridgeCall('PushNotification.RequestPermission', {
        id: options.id ?? null,
        event: 'Native\\Mobile\\Events\\PushNotification\\TokenGenerated',
    });

    return { success: true };
}

/**
 * Current notification permission state.
 *
 * @returns {Promise<'granted' | 'denied' | 'not_determined'>}
 */
export async function checkPermission() {
    const response = await bridgeCall('PushNotification.CheckPermission');

    return response?.status ?? 'not_determined';
}

/**
 * Last device token received in this process, or null when the device has not
 * enrolled yet. On Android a missing token also kicks off a fetch, so the
 * value may arrive later through `onToken()`.
 *
 * @returns {Promise<string | null>}
 */
export async function getToken() {
    const response = await bridgeCall('PushNotification.GetToken');

    return response?.token ?? null;
}

/**
 * Subscribe to device tokens. The callback receives the token and the optional
 * correlation id passed to `requestPermission()`.
 *
 * @param {(token: string, id: string | null) => void} callback
 * @returns {() => void} unsubscribe
 */
export function onToken(callback) {
    const handler = (event) => {
        const name = String(event?.detail?.event ?? '').replace(/^\\+/, '');
        if (!name.endsWith('PushNotification\\TokenGenerated')) {
            return;
        }

        const payload = event.detail.payload ?? {};
        if (! payload.token) {
            return;
        }

        callback(payload.token, payload.id ?? null);
    };

    document.addEventListener('native-event', handler);

    return () => document.removeEventListener('native-event', handler);
}

export default { requestPermission, checkPermission, getToken, onToken };
