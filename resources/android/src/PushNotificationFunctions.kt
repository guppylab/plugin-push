package com.guppylab.plugins.push

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.util.Log
import androidx.core.app.ActivityCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import androidx.fragment.app.FragmentActivity
import com.google.firebase.messaging.FirebaseMessaging
import com.nativephp.mobile.utils.NativeActionCoordinator
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import org.json.JSONObject

/**
 * Handlers nativos de push no Android (FCM). Preenchem os mesmos bridge
 * functions PushNotification.* que o iOS implementa via APNs. O token FCM é
 * entregue de forma assíncrona pelo evento TokenGenerated do core.
 */
object PushNotificationFunctions {

    private const val TAG = "PushNotification"
    private const val PERMISSION_REQUEST_CODE = 10_002
    private const val TOKEN_EVENT = "Native\\Mobile\\Events\\PushNotification\\TokenGenerated"

    /** Último token FCM conhecido no processo — servido por GetToken. */
    @Volatile
    var lastToken: String? = null
        internal set

    /**
     * Pede POST_NOTIFICATIONS (API 33+) e busca o token FCM. Em API < 33 a
     * permissão é concedida na instalação. Recebe { id, event } do enrollment.
     */
    class RequestPermission(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val id = parameters["id"] as? String
            val event = parameters["event"] as? String ?: TOKEN_EVENT

            Handler(Looper.getMainLooper()).post {
                val needsRuntimePermission = Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU
                val alreadyGranted = !needsRuntimePermission || ContextCompat.checkSelfPermission(
                    activity, Manifest.permission.POST_NOTIFICATIONS,
                ) == PackageManager.PERMISSION_GRANTED

                if (alreadyGranted) {
                    fetchToken(activity, id, event)
                } else {
                    // Dispara o prompt. O token é buscado depois, quando o app
                    // reconcilia (getToken no poll do soft-ask) — o resultado do
                    // prompt chega no MainActivity.onRequestPermissionsResult do core.
                    ActivityCompat.requestPermissions(
                        activity, arrayOf(Manifest.permission.POST_NOTIFICATIONS), PERMISSION_REQUEST_CODE,
                    )
                }
            }

            return BridgeResponse.success(mapOf("success" to true))
        }
    }

    /**
     * Estado da permissão de notificação, no vocabulário esperado pelo PHP
     * (granted|denied|not_determined). No Android 13+ é uma permissão de runtime:
     * antes de pedir, `areNotificationsEnabled()` já é false — mapear isso como
     * "denied" faz o soft-ask mandar o usuário pra Ajustes em vez de mostrar o
     * botão "Ativar". Por isso distinguimos "nunca pedido" (not_determined) de
     * "negado", via checkSelfPermission + shouldShowRequestPermissionRationale.
     */
    class CheckPermission(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val status = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                val granted = ContextCompat.checkSelfPermission(
                    activity, Manifest.permission.POST_NOTIFICATIONS,
                ) == PackageManager.PERMISSION_GRANTED

                when {
                    granted -> "granted"
                    // rationale=true → o usuário já negou uma vez (dá pra pedir de
                    // novo). false + não concedida → nunca pedido → deixamos o botão
                    // "Ativar" aparecer; o requestPermissions mostra o prompt.
                    activity.shouldShowRequestPermissionRationale(Manifest.permission.POST_NOTIFICATIONS) -> "denied"
                    else -> "not_determined"
                }
            } else {
                // Pré-33: concedida na instalação; só respeita o desligar em Ajustes.
                if (NotificationManagerCompat.from(activity).areNotificationsEnabled()) "granted" else "denied"
            }

            return BridgeResponse.success(mapOf("status" to status))
        }
    }

    /** Último token FCM já recebido (ou vazio → null no JS/PHP). */
    class GetToken(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            // Se ainda não temos token em memória, pede um (chega via evento).
            if (lastToken == null) {
                fetchToken(context, null, TOKEN_EVENT)
            }
            val token = lastToken
            return if (token.isNullOrEmpty()) {
                BridgeResponse.success(emptyMap())
            } else {
                BridgeResponse.success(mapOf("token" to token))
            }
        }
    }

    /** Busca o token FCM e o despacha ao PHP no main thread. */
    internal fun fetchToken(context: Context?, id: String?, event: String) {
        FirebaseMessaging.getInstance().token.addOnCompleteListener { task ->
            if (!task.isSuccessful) {
                Log.e(TAG, "Falha ao obter token FCM", task.exception)
                return@addOnCompleteListener
            }
            val token = task.result ?: return@addOnCompleteListener
            lastToken = token
            dispatchToken(context, token, id, event)
        }
    }

    /** Despacha TokenGenerated{token,id} no main thread. */
    internal fun dispatchToken(context: Context?, token: String, id: String?, event: String) {
        // dispatchEvent exige FragmentActivity; um Context de serviço/app não serve.
        val target = context as? FragmentActivity ?: return
        Handler(Looper.getMainLooper()).post {
            val payload = JSONObject().apply {
                put("token", token)
                if (id != null) put("id", id)
            }
            NativeActionCoordinator.dispatchEvent(target, event, payload.toString())
        }
    }
}
