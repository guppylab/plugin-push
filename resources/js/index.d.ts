// Type definitions for the Push plugin JS library.

export type PermissionStatus = 'granted' | 'denied' | 'not_determined';

export interface RequestPermissionOptions {
    /** Correlation id echoed back on the TokenGenerated event. */
    id?: string;
}

/**
 * Ask for the notification permission and enroll the device. The token arrives
 * via `onToken`.
 */
export function requestPermission(
    options?: RequestPermissionOptions,
): Promise<{ success: boolean }>;

/** Current notification permission state. */
export function checkPermission(): Promise<PermissionStatus>;

/** Last device token received, or null when the device has not enrolled yet. */
export function getToken(): Promise<string | null>;

/** Subscribe to device tokens. Returns an unsubscribe function. */
export function onToken(
    callback: (token: string, id: string | null) => void,
): () => void;

declare const _default: {
    requestPermission: typeof requestPermission;
    checkPermission: typeof checkPermission;
    getToken: typeof getToken;
    onToken: typeof onToken;
};

export default _default;
