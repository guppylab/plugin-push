import Foundation
import UIKit
import UserNotifications

// MARK: - Push Notification Manager
//
// Preenche a lacuna nativa do push no NativePHP Mobile: o core traz a facade
// PHP (PushNotifications::enroll/checkPermission/getToken) e a bridge JS, mas
// NÃO traz o handler nativo. O AppDelegate do core só encaminha o callback do
// APNs (didRegisterForRemoteNotificationsWithDeviceToken) via NotificationCenter
// — este manager consome esse post, converte o device token cru em hex e devolve
// ao PHP pelo evento TokenGenerated. Fluxo direto APNs (.p8), sem Firebase.
final class PushNotificationManager {
    static let shared = PushNotificationManager()

    /// Classe de evento PHP a despachar quando o token chega. Definida pelo
    /// enrollment (PushNotification.RequestPermission), com fallback pro core.
    private var pendingEvent: String?

    /// ID de correlação do enrollment (opcional), devolvido no TokenGenerated.
    private var pendingId: String?

    /// Último device token APNs (hex) recebido — servido por GetToken.
    private(set) var lastToken: String?

    private var observing = false

    private init() {}

    /// Registra (uma vez) os observers dos callbacks do APNs postados pelo
    /// AppDelegate do core. Idempotente: chamado no enrollment e no GetToken.
    func startObservingIfNeeded() {
        guard !observing else { return }
        observing = true

        NotificationCenter.default.addObserver(
            forName: .didRegisterForRemoteNotifications,
            object: nil,
            queue: .main
        ) { [weak self] note in
            guard let self,
                  let data = note.userInfo?["deviceToken"] as? Data else { return }
            let token = data.map { String(format: "%02x", $0) }.joined()
            self.lastToken = token
            self.dispatchToken(token)
        }

        NotificationCenter.default.addObserver(
            forName: .didFailToRegisterForRemoteNotifications,
            object: nil,
            queue: .main
        ) { note in
            let message = (note.userInfo?["error"] as? Error)?.localizedDescription ?? "desconhecido"
            print("PushNotification: falha ao registrar no APNs: \(message)")
        }
    }

    /// Guarda o evento/id do enrollment, pede autorização ao SO e, se concedida,
    /// dispara o registro remoto (que resulta no callback do AppDelegate).
    func requestPermission(id: String?, event: String) {
        pendingEvent = event
        pendingId = id
        startObservingIfNeeded()

        UNUserNotificationCenter.current().requestAuthorization(options: [.alert, .badge, .sound]) { granted, error in
            if let error = error {
                print("PushNotification.RequestPermission error: \(error.localizedDescription)")
            }
            guard granted else { return }
            DispatchQueue.main.async {
                UIApplication.shared.registerForRemoteNotifications()
            }
        }
    }

    private func dispatchToken(_ token: String) {
        let event = pendingEvent ?? "Native\\Mobile\\Events\\PushNotification\\TokenGenerated"
        var payload: [String: Any] = ["token": token]
        if let id = pendingId {
            payload["id"] = id
        }
        LaravelBridge.shared.send?(event, payload)
    }
}

// MARK: - Bridge Functions (namespace "PushNotification.*")
//
// Implementam os handlers nativos que a bridge JS do core chama:
//   PushNotification.RequestPermission | .CheckPermission | .GetToken
enum PushNotificationFunctions {

    // MARK: - PushNotification.RequestPermission
    //
    // Recebe { id, event } do PendingPushNotificationEnrollment. Retorna
    // { success: true } imediatamente (enrollment iniciado); o token real chega
    // depois, de forma assíncrona, no evento TokenGenerated.
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
    // Retorna { status } com o estado atual da permissão de notificação,
    // no vocabulário esperado pelo PHP (EnrollsPush::refreshPushState).
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
            semaphore.wait()

            return BridgeResponse.success(data: ["status": status])
        }
    }

    // MARK: - PushNotification.GetToken
    //
    // Retorna { token } com o último device token APNs (hex) já recebido, ou
    // um payload vazio (→ null no JS) se ainda não houve registro.
    final class GetToken: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            PushNotificationManager.shared.startObservingIfNeeded()

            if let token = PushNotificationManager.shared.lastToken {
                return BridgeResponse.success(data: ["token": token])
            }

            return BridgeResponse.success(data: [:])
        }
    }
}
