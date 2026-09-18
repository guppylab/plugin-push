// Type definitions for the Push plugin JS library.

export type PermissionStatus =
    | 'granted'
    | 'denied'
    | 'not_determined'
    /** iOS only: quiet notifications the user has not promoted yet. */
    | 'provisional'
    /** iOS only: App Clips. */
    | 'ephemeral'
    | 'unknown';

export interface SupportResult {
    supported: boolean;
    platform: 'ios' | 'android' | 'unknown';
    /** 'simulator', 'missing_google_services', or null when supported. */
    reason: string | null;
}

export interface PushMessage {
    /** The payload, minus transport keys such as "aps" and "gcm.message_id". */
    data: Record<string, any>;
    title: string | null;
    body: string | null;
}

export interface PushTap {
    data: Record<string, any>;
    /** URL found under one of the configured deep link keys, if any. */
    link: string | null;
}

export interface BadgeResult {
    success: boolean;
    platform: 'ios' | 'android' | 'unknown';
}

/** Ask for the notification permission and enroll the device. */
export function requestPermission(options?: { id?: string }): Promise<{ success: boolean }>;

/** Current notification permission state. */
export function checkPermission(): Promise<PermissionStatus>;

/** Last device token received, or null. */
export function getToken(): Promise<string | null>;

/** Hand push settings to the native side (the service provider does this on boot). */
export function configure(config: object): Promise<{ success: boolean }>;

/** Whether push can work on this device. */
export function support(): Promise<SupportResult>;

/** Shorthand for `(await support()).supported`. */
export function isSupported(): Promise<boolean>;

/** Delete the device token and stop receiving pushes — the logout call. */
export function unenroll(): Promise<{ success: boolean }>;

/** Set the app icon badge count. iOS only. */
export function setBadge(count: number): Promise<BadgeResult>;

/** Clear the app icon badge. */
export function clearBadge(): Promise<BadgeResult>;

/** Subscribe to device tokens. Returns an unsubscribe function. */
export function onToken(
    callback: (token: string, id: string | null) => void,
): () => void;

/** Subscribe to incoming pushes, including data-only ones. */
export function onMessage(callback: (message: PushMessage) => void): () => void;

/** Subscribe to notification taps, including a cold start. */
export function onTapped(callback: (tap: PushTap) => void): () => void;

declare const _default: {
    requestPermission: typeof requestPermission;
    checkPermission: typeof checkPermission;
    getToken: typeof getToken;
    configure: typeof configure;
    support: typeof support;
    isSupported: typeof isSupported;
    unenroll: typeof unenroll;
    setBadge: typeof setBadge;
    clearBadge: typeof clearBadge;
    onToken: typeof onToken;
    onMessage: typeof onMessage;
    onTapped: typeof onTapped;
};

export default _default;
