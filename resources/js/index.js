/**
 * Push plugin for NativePHP Mobile.
 *
 * Wraps the `PushNotification.*` bridge functions so the plugin can be used from
 * Inertia (Vue/React) the same way the core PHP facade is used from Livewire.
 *
 * Enrolling is asynchronous: the device token arrives as a native event —
 * subscribe with `onToken()`. Pushes themselves arrive on `onMessage()` and
 * `onTapped()`.
 *
 * @example
 * import { requestPermission, onToken, onTapped } from 'guppylab-plugin-push';
 */

const baseUrl = '/_native/api/call';

const TOKEN_EVENT = 'Native\\Mobile\\Events\\PushNotification\\TokenGenerated';
const MESSAGE_EVENT = 'Guppylab\\Push\\Events\\MessageReceived';
const TAPPED_EVENT = 'Guppylab\\Push\\Events\\NotificationTapped';

/**
 * Internal bridge call. Unwraps the `{ status, data }` envelope returned by the
 * core's native-call endpoint and throws on a native error.
 *
 * @private
 * @param {string} method
 * @param {Record<string, unknown>} params
 * @returns {Promise<any>}
 */
async function bridgeCall(method, params = {}) {
    const response = await fetch(baseUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        },
        body: JSON.stringify({ method, params }),
    });

    const result = await response.json();

    if (result.status === 'error') {
        throw new Error(result.message || 'Native call failed');
    }

    const nativeResponse = result.data;

    if (nativeResponse && nativeResponse.data !== undefined) {
        return nativeResponse.data;
    }

    return nativeResponse;
}

/**
 * Subscribe to a native event by class name suffix.
 *
 * @private
 * @param {string} suffix
 * @param {(payload: Record<string, any>) => void} callback
 * @returns {() => void} unsubscribe
 */
function subscribe(suffix, callback) {
    const handler = (event) => {
        const name = String(event?.detail?.event ?? '').replace(/^\\+/, '');

        if (!name.endsWith(suffix)) {
            return;
        }

        callback(event.detail.payload ?? {});
    };

    document.addEventListener('native-event', handler);

    return () => document.removeEventListener('native-event', handler);
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
        event: TOKEN_EVENT,
    });

    return { success: true };
}

/**
 * Current notification permission state. `provisional` and `ephemeral` are iOS
 * only — treat them as granted unless you specifically care.
 *
 * @returns {Promise<'granted' | 'denied' | 'not_determined' | 'provisional' | 'ephemeral' | 'unknown'>}
 */
export async function checkPermission() {
    const response = await bridgeCall('PushNotification.CheckPermission');

    return response?.status ?? 'not_determined';
}

/**
 * Last device token received in this process, or null when the device has not
 * enrolled yet. A missing token also kicks off a fetch, so the value may arrive
 * later through `onToken()`.
 *
 * @returns {Promise<string | null>}
 */
export async function getToken() {
    const response = await bridgeCall('PushNotification.GetToken');

    return response?.token ?? null;
}

/**
 * Hand the app's push settings to the native side.
 *
 * Normally unnecessary: the service provider does this on boot from
 * config/push.php. Use it to change the channel or the icon at runtime.
 *
 * @param {object} config
 * @returns {Promise<{ success: boolean }>}
 */
export async function configure(config) {
    await bridgeCall('PushNotification.Configure', config);

    return { success: true };
}

/**
 * Whether push can work on this device: false on an iOS simulator, and on an
 * Android build without google-services.json.
 *
 * @returns {Promise<{ supported: boolean, platform: string, reason: string | null }>}
 */
export async function support() {
    const response = await bridgeCall('PushNotification.IsSupported');

    return {
        supported: response?.supported ?? false,
        platform: response?.platform ?? 'unknown',
        reason: response?.reason || null,
    };
}

/** Shorthand for `(await support()).supported`. */
export async function isSupported() {
    return (await support()).supported;
}

/**
 * Delete the device token and stop receiving pushes — the logout call.
 *
 * Remove the token from your backend first, while you still know it: the device
 * cannot forget it on the server's behalf.
 *
 * @returns {Promise<{ success: boolean }>}
 */
export async function unenroll() {
    const response = await bridgeCall('PushNotification.Unenroll');

    return { success: response?.success ?? false };
}

/**
 * Set the app icon badge count. iOS only — Android has no platform badge API,
 * and the call reports `{ success: false, platform: 'android' }` there.
 *
 * @param {number} count
 * @returns {Promise<{ success: boolean, platform: string }>}
 */
export async function setBadge(count) {
    const response = await bridgeCall('PushNotification.SetBadge', {
        count: Math.max(0, Number(count) || 0),
    });

    return {
        success: response?.success ?? false,
        platform: response?.platform ?? 'unknown',
    };
}

/** Clear the app icon badge. */
export async function clearBadge() {
    return setBadge(0);
}

/**
 * Subscribe to device tokens. The callback receives the token and the optional
 * correlation id passed to `requestPermission()`.
 *
 * @param {(token: string, id: string | null) => void} callback
 * @returns {() => void} unsubscribe
 */
export function onToken(callback) {
    return subscribe('PushNotification\\TokenGenerated', (payload) => {
        if (!payload.token) {
            return;
        }

        callback(payload.token, payload.id ?? null);
    });
}

/**
 * Subscribe to incoming pushes, including data-only (silent) ones.
 *
 * @param {(message: { data: Record<string, any>, title: string | null, body: string | null }) => void} callback
 * @returns {() => void} unsubscribe
 */
export function onMessage(callback) {
    return subscribe('Push\\Events\\MessageReceived', (payload) => {
        let data = {};

        try {
            data = JSON.parse(payload.payload ?? '{}');
        } catch (_) {
            data = {};
        }

        callback({
            data,
            title: payload.title ?? null,
            body: payload.body ?? null,
        });
    });
}

/**
 * Subscribe to notification taps, including the tap that cold-started the app.
 *
 * @param {(tap: { data: Record<string, any>, link: string | null }) => void} callback
 * @returns {() => void} unsubscribe
 */
export function onTapped(callback) {
    return subscribe('Push\\Events\\NotificationTapped', (payload) => {
        let data = {};

        try {
            data = JSON.parse(payload.payload ?? '{}');
        } catch (_) {
            data = {};
        }

        callback({ data, link: payload.link ?? null });
    });
}

export default {
    requestPermission,
    checkPermission,
    getToken,
    configure,
    support,
    isSupported,
    unenroll,
    setBadge,
    clearBadge,
    onToken,
    onMessage,
    onTapped,
};
