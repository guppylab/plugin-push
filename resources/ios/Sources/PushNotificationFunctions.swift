import Foundation
import UIKit
import UserNotifications

// MARK: - Push Notification Manager
//
// Fills the native gap the core leaves open. nativephp/mobile ships the PHP
// facade (PushNotifications::enroll/checkPermission/getToken), the JS bridge and
// the TokenGenerated event, but no native handler: its AppDelegate only forwards
// the APNs callbacks over NotificationCenter. This manager consumes those posts,
// turns the raw device token into hex and hands it back to PHP — direct APNs
// (.p8), no Firebase — and, beyond the core's API, delivers what a push actually
// contains: its payload, and the tap that brought the app forward.

final class PushNotificationManager: NSObject, UNUserNotificationCenterDelegate {
    static let shared = PushNotificationManager()

    // MARK: Configuration pushed over from config/push.php

    private var foregroundPresentation: [String] = ["banner", "sound", "badge"]
    private var deepLinkKeys: [String] = ["link", "url"]
    private var provisional = false
    private var messageReceivedEvent = "Guppylab\\Push\\Events\\MessageReceived"
    private var notificationTappedEvent = "Guppylab\\Push\\Events\\NotificationTapped"

    // MARK: Enrolment state

    /// PHP event class to dispatch when the token arrives. Set by the enrolment
    /// (PushNotification.RequestPermission), with the core's class as fallback.
    private var pendingEvent: String?

    /// Correlation id of the enrolment, echoed back on TokenGenerated.
    private var pendingId: String?

    /// Last APNs device token (hex) received — served by GetToken.
    private(set) var lastToken: String?

    // MARK: Delegate chain

    /// The delegate installed before us, if any. A push build usually also has
    /// the core's local-notification plugin, which installs its own delegate and
    /// forwards remote notifications on. Install order between plugins is not
    /// guaranteed, so we chain in the same way and forward anything that is not
    /// a remote notification. That makes the order irrelevant.
    private weak var previousDelegate: UNUserNotificationCenterDelegate?
    private var installed = false
    private var observing = false

    /// Events raised before the PHP bridge was ready — a notification tap on a
    /// cold start arrives well before the runtime exists. Queued, then flushed.
    private var pendingDispatches: [(event: String, payload: [String: Any])] = []
    private var flushTimer: Timer?

    private override init() {}

    // MARK: - Installation

    /// Install the notification delegate and the APNs observers. Called from the
    /// plugin's init function during app start, so a tap on a cold start is
    /// captured, and again (idempotently) from the bridge functions.
    func install() {
        startObservingIfNeeded()

        guard !installed else { return }
        installed = true

        let center = UNUserNotificationCenter.current()
        previousDelegate = center.delegate
        center.delegate = self
    }

    /// Subscribe (once) to the APNs callbacks posted by the core's AppDelegate.
    func startObservingIfNeeded() {
        guard !observing else { return }
        observing = true

        NotificationCenter.default.addObserver(
            forName: .didRegisterForRemoteNotifications,
            object: nil,
            queue: .main
        ) { [weak self] note in
            guard let self, let data = note.userInfo?["deviceToken"] as? Data else { return }
            let token = data.map { String(format: "%02x", $0) }.joined()
            self.lastToken = token
            self.dispatchToken(token)
        }

        NotificationCenter.default.addObserver(
            forName: .didFailToRegisterForRemoteNotifications,
            object: nil,
            queue: .main
        ) { note in
            let message = (note.userInfo?["error"] as? Error)?.localizedDescription ?? "unknown"
            print("PushNotification: APNs registration failed: \(message)")
        }

        // Data-only (silent) pushes, forwarded by the core's AppDelegate.
        NotificationCenter.default.addObserver(
            forName: .didReceiveRemoteNotification,
            object: nil,
            queue: .main
        ) { [weak self] note in
            guard let self,
                  let payload = note.userInfo?["payload"] as? [AnyHashable: Any] else { return }
            self.dispatchMessage(payload)
        }
    }

    // MARK: - Configuration

    func configure(_ parameters: [String: Any]) {
        if let ios = parameters["ios"] as? [String: Any] {
            if let presentation = ios["foreground_presentation"] as? [String] {
                foregroundPresentation = presentation
            }
            provisional = (ios["provisional"] as? Bool) ?? false
        }

        if let keys = parameters["deep_link_keys"] as? [String] {
            deepLinkKeys = keys
        }

        if let events = parameters["events"] as? [String: Any] {
            messageReceivedEvent = (events["message_received"] as? String) ?? messageReceivedEvent
            notificationTappedEvent = (events["notification_tapped"] as? String) ?? notificationTappedEvent
        }

        install()
    }

    // MARK: - Enrolment

    /// Remember the enrolment's event/id, ask the OS for authorization and, if
    /// granted, register for remote notifications — which lands back in the
    /// AppDelegate callback observed above.
    func requestPermission(id: String?, event: String) {
        pendingEvent = event
        pendingId = id
        install()

        var options: UNAuthorizationOptions = [.alert, .badge, .sound]
        if provisional {
            options.insert(.provisional)
        }

        UNUserNotificationCenter.current().requestAuthorization(options: options) { granted, error in
            if let error = error {
                print("PushNotification.RequestPermission error: \(error.localizedDescription)")
            }
            guard granted else { return }
            DispatchQueue.main.async {
                UIApplication.shared.registerForRemoteNotifications()
            }
        }
    }

    /// Stop receiving pushes and forget the token. The backend still has to
    /// forget it too — the device cannot do that on its behalf.
    func unenroll() {
        lastToken = nil
        pendingEvent = nil
        pendingId = nil

        DispatchQueue.main.async {
            UIApplication.shared.unregisterForRemoteNotifications()
        }
    }

    func setBadge(_ count: Int) {
        DispatchQueue.main.async {
            if #available(iOS 16.0, *) {
                UNUserNotificationCenter.current().setBadgeCount(count)
            } else {
                UIApplication.shared.applicationIconBadgeNumber = count
            }
        }
    }

    // MARK: - UNUserNotificationCenterDelegate

    /// Without this, iOS shows nothing while the app is in the foreground and
    /// the platform looks broken next to Android, which renders its own.
    func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        willPresent notification: UNNotification,
        withCompletionHandler completionHandler: @escaping (UNNotificationPresentationOptions) -> Void
    ) {
        let userInfo = notification.request.content.userInfo

        // Local notifications belong to whoever else is in the chain.
        guard userInfo["aps"] != nil else {
            if let previous = previousDelegate,
               previous.responds(to: #selector(UNUserNotificationCenterDelegate.userNotificationCenter(_:willPresent:withCompletionHandler:))) {
                previous.userNotificationCenter?(center, willPresent: notification, withCompletionHandler: completionHandler)
            } else {
                completionHandler([])
            }
            return
        }

        dispatchMessage(
            userInfo,
            title: notification.request.content.title,
            body: notification.request.content.body
        )

        completionHandler(presentationOptions())
    }

    func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        didReceive response: UNNotificationResponse,
        withCompletionHandler completionHandler: @escaping () -> Void
    ) {
        let userInfo = response.notification.request.content.userInfo

        guard userInfo["aps"] != nil else {
            if let previous = previousDelegate,
               previous.responds(to: #selector(UNUserNotificationCenterDelegate.userNotificationCenter(_:didReceive:withCompletionHandler:))) {
                previous.userNotificationCenter?(center, didReceive: response, withCompletionHandler: completionHandler)
            } else {
                completionHandler()
            }
            return
        }

        let link = deepLink(in: userInfo)

        dispatch(notificationTappedEvent, [
            "payload": json(from: strip(userInfo)),
            "link": link as Any,
        ])

        completionHandler()
    }

    private func presentationOptions() -> UNNotificationPresentationOptions {
        var options: UNNotificationPresentationOptions = []

        for option in foregroundPresentation {
            switch option {
            case "banner": options.insert(.banner)
            case "list": options.insert(.list)
            case "sound": options.insert(.sound)
            case "badge": options.insert(.badge)
            default: break
            }
        }

        return options
    }

    // MARK: - Event dispatch

    private func dispatchToken(_ token: String) {
        let event = pendingEvent ?? "Native\\Mobile\\Events\\PushNotification\\TokenGenerated"
        var payload: [String: Any] = ["token": token]
        if let id = pendingId {
            payload["id"] = id
        }

        dispatch(event, payload)
    }

    private func dispatchMessage(
        _ userInfo: [AnyHashable: Any],
        title: String? = nil,
        body: String? = nil
    ) {
        let aps = userInfo["aps"] as? [String: Any]
        let alert = aps?["alert"] as? [String: Any]

        dispatch(messageReceivedEvent, [
            "payload": json(from: strip(userInfo)),
            "title": (title ?? alert?["title"] as? String) as Any,
            "body": (body ?? alert?["body"] as? String) as Any,
        ])
    }

    /// Send an event to PHP, or hold it until the bridge exists.
    ///
    /// A notification tapped on a cold start reaches the delegate long before
    /// the PHP runtime is up; dispatching straight away would drop it, which is
    /// exactly the case deep links care about.
    private func dispatch(_ event: String, _ payload: [String: Any]) {
        DispatchQueue.main.async {
            guard let send = LaravelBridge.shared.send else {
                self.pendingDispatches.append((event: event, payload: payload))
                self.scheduleFlush()
                return
            }

            send(event, payload)
        }
    }

    private func scheduleFlush() {
        guard flushTimer == nil else { return }

        flushTimer = Timer.scheduledTimer(withTimeInterval: 0.5, repeats: true) { [weak self] timer in
            guard let self else { return timer.invalidate() }
            guard let send = LaravelBridge.shared.send else { return }

            let queued = self.pendingDispatches
            self.pendingDispatches.removeAll()
            queued.forEach { send($0.event, $0.payload) }

            timer.invalidate()
            self.flushTimer = nil
        }
    }

    // MARK: - Payload helpers

    /// Drop transport keys so the app sees its own data, not APNs plumbing.
    private func strip(_ userInfo: [AnyHashable: Any]) -> [String: Any] {
        var data: [String: Any] = [:]

        for (key, value) in userInfo {
            guard let key = key as? String else { continue }
            guard !["aps", "gcm.message_id", "google.c.a.e", "google.c.sender.id"].contains(key) else { continue }
            data[key] = value
        }

        return data
    }

    private func deepLink(in userInfo: [AnyHashable: Any]) -> String? {
        let nested = userInfo["data"] as? [String: Any]

        for key in deepLinkKeys {
            if let link = userInfo[key] as? String, !link.isEmpty {
                return link
            }
            if let link = nested?[key] as? String, !link.isEmpty {
                return link
            }
        }

        return nil
    }

    private func json(from data: [String: Any]) -> String {
        guard JSONSerialization.isValidJSONObject(data),
              let encoded = try? JSONSerialization.data(withJSONObject: data),
              let string = String(data: encoded, encoding: .utf8) else {
            return "{}"
        }

        return string
    }
}

// MARK: - Plugin init
//
// Registered as ios.init_function so the delegate is installed during app start,
// before any bridge function is called. A notification tapped on a cold start is
// then captured rather than lost.

func initGuppylabPush() {
    PushNotificationManager.shared.install()
}

// MARK: - Bridge Functions (namespace "PushNotification.*")

enum PushNotificationFunctions {

    // MARK: - PushNotification.RequestPermission
    //
    // Receives { id, event } from PendingPushNotificationEnrollment and returns
    // immediately: the token arrives later on the TokenGenerated event.
    final class RequestPermission: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let id = parameters["id"] as? String
            let event = parameters["event"] as? String
                ?? "Native\\Mobile\\Events\\PushNotification\\TokenGenerated"

            PushNotificationManager.shared.requestPermission(id: id, event: event)

            return BridgeResponse.success(data: ["success": true])
        }
    }

    // MARK: - PushNotification.CheckPermission
    //
    // Returns { status } in the vocabulary PHP expects.
    final class CheckPermission: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            var status = "unknown"
            let semaphore = DispatchSemaphore(value: 0)

            UNUserNotificationCenter.current().getNotificationSettings { settings in
                switch settings.authorizationStatus {
                case .notDetermined: status = "not_determined"
                case .denied: status = "denied"
                case .authorized: status = "granted"
                case .provisional: status = "provisional"
                case .ephemeral: status = "ephemeral"
                @unknown default: status = "unknown"
                }
                semaphore.signal()
            }

            // Bounded: this runs on the bridge thread, and a wait without a
            // deadline would hang the call rather than answer "unknown".
            if semaphore.wait(timeout: .now() + 5) == .timedOut {
                status = "unknown"
            }

            return BridgeResponse.success(data: ["status": status])
        }
    }

    // MARK: - PushNotification.GetToken
    //
    // Last APNs device token (hex), or an empty payload (→ null in PHP/JS).
    final class GetToken: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            PushNotificationManager.shared.install()

            if let token = PushNotificationManager.shared.lastToken {
                return BridgeResponse.success(data: ["token": token])
            }

            return BridgeResponse.success(data: [:])
        }
    }

    // MARK: - PushNotification.Configure
    //
    // Receives the app's push settings from config/push.php.
    final class Configure: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            PushNotificationManager.shared.configure(parameters)

            return BridgeResponse.success(data: ["success": true])
        }
    }

    // MARK: - PushNotification.Unenroll
    //
    // Logout: stop receiving pushes and forget the token.
    final class Unenroll: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            PushNotificationManager.shared.unenroll()

            return BridgeResponse.success(data: ["success": true])
        }
    }

    // MARK: - PushNotification.SetBadge
    final class SetBadge: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let count = (parameters["count"] as? NSNumber)?.intValue ?? 0

            PushNotificationManager.shared.setBadge(count)

            return BridgeResponse.success(data: ["success": true, "platform": "ios"])
        }
    }

    // MARK: - PushNotification.IsSupported
    //
    // Remote notifications need a real device: the simulator cannot register
    // with APNs unless it is signed in to an Apple account on a host that
    // supports it, so an app can explain itself instead of waiting forever.
    final class IsSupported: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            #if targetEnvironment(simulator)
            let simulator = true
            #else
            let simulator = false
            #endif

            return BridgeResponse.success(data: [
                "supported": !simulator,
                "platform": "ios",
                "reason": simulator ? "simulator" : "",
            ])
        }
    }
}
