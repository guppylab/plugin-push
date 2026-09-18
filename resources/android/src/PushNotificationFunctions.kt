package com.guppylab.plugins.push

import android.Manifest
import android.app.Activity
import android.app.Application
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.util.Log
import androidx.core.app.ActivityCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import androidx.fragment.app.FragmentActivity
import com.google.firebase.messaging.FirebaseMessaging
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import com.nativephp.mobile.utils.NativeActionCoordinator
import org.json.JSONObject

/**
 * Native push handlers for Android (FCM). They back the same PushNotification.*
 * bridge functions the iOS side implements over APNs, so the PHP and JS APIs do
 * not branch on platform.
 */
object PushNotificationFunctions {

    private const val TAG = "PushNotification"
    private const val PERMISSION_REQUEST_CODE = 10_002

    /** Last FCM token seen in this process — served by GetToken. */
    val lastToken: String?
        get() = PushStore.cachedToken

    // MARK: - PushNotification.RequestPermission

    /**
     * Ask for POST_NOTIFICATIONS (API 33+) and fetch the FCM token. Below 33 the
     * permission is granted at install time. Receives { id, event } from the
     * enrolment.
     */
    class RequestPermission(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val id = parameters["id"] as? String
            val event = parameters["event"] as? String ?: PushStore.DEFAULT_TOKEN_EVENT

            Handler(Looper.getMainLooper()).post {
                val needsRuntimePermission = Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU
                val alreadyGranted = !needsRuntimePermission || ContextCompat.checkSelfPermission(
                    activity, Manifest.permission.POST_NOTIFICATIONS,
                ) == PackageManager.PERMISSION_GRANTED

                if (alreadyGranted) {
                    fetchToken(activity, id, event)
                } else {
                    // Fire the prompt. The token is fetched once the permission
                    // result comes back through the core's MainActivity; until
                    // then the enrolment is simply pending.
                    ActivityCompat.requestPermissions(
                        activity, arrayOf(Manifest.permission.POST_NOTIFICATIONS), PERMISSION_REQUEST_CODE,
                    )
                }
            }

            return BridgeResponse.success(mapOf("success" to true))
        }
    }

    // MARK: - PushNotification.CheckPermission

    /**
     * Notification permission state in the vocabulary PHP expects
     * (granted|denied|not_determined).
     *
     * On Android 13+ this is a runtime permission: before it is asked,
     * areNotificationsEnabled() is already false, and reporting that as "denied"
     * sends a soft-ask straight to Settings instead of showing an "Enable"
     * button. So "never asked" is told apart from "denied" via
     * checkSelfPermission plus shouldShowRequestPermissionRationale.
     */
    class CheckPermission(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val status = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                val granted = ContextCompat.checkSelfPermission(
                    activity, Manifest.permission.POST_NOTIFICATIONS,
                ) == PackageManager.PERMISSION_GRANTED

                when {
                    granted -> "granted"
                    // rationale=true → already refused once (we may ask again).
                    // false and not granted → never asked, so let the "Enable"
                    // button show; requestPermissions will raise the prompt.
                    activity.shouldShowRequestPermissionRationale(Manifest.permission.POST_NOTIFICATIONS) -> "denied"
                    else -> "not_determined"
                }
            } else {
                // Pre-33: granted at install; only respects being switched off
                // in Settings.
                if (NotificationManagerCompat.from(activity).areNotificationsEnabled()) "granted" else "denied"
            }

            return BridgeResponse.success(mapOf("status" to status))
        }
    }

    // MARK: - PushNotification.GetToken

    /** Last FCM token (or an empty payload → null in PHP/JS). */
    class GetToken(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val token = PushStore.token(context)

            // Nothing cached: ask FCM. The answer is asynchronous and arrives on
            // the TokenGenerated event.
            if (token.isNullOrEmpty()) {
                fetchToken(context, null, PushStore.DEFAULT_TOKEN_EVENT)

                return BridgeResponse.success(emptyMap())
            }

            return BridgeResponse.success(mapOf("token" to token))
        }
    }

    // MARK: - PushNotification.Configure

    /** Persist the settings from config/push.php for the FCM service to read. */
    class Configure(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val config = JSONObject()

            listOf("channel", "android", "ios", "events").forEach { key ->
                (parameters[key] as? JSONObject)?.let { config.put(key, it) }
            }
            (parameters["deep_link_keys"] as? Any)?.let { config.put("deep_link_keys", it) }

            PushStore.saveConfig(context, config)
            ensureChannel(context)

            return BridgeResponse.success(mapOf("success" to true))
        }
    }

    // MARK: - PushNotification.Unenroll

    /**
     * Logout: delete the FCM registration token so this device stops receiving
     * the previous user's pushes. The backend still has to forget the token.
     */
    class Unenroll(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            PushStore.clearToken(context)

            FirebaseMessaging.getInstance().deleteToken()
                .addOnFailureListener { Log.e(TAG, "Failed to delete FCM token", it) }

            return BridgeResponse.success(mapOf("success" to true))
        }
    }

    // MARK: - PushNotification.SetBadge

    /**
     * No-op on Android. There is no platform badge API: launchers implement
     * their own, and most derive the count from the notifications themselves.
     * Reported rather than silently ignored so a caller can tell.
     */
    class SetBadge(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            return BridgeResponse.success(mapOf("success" to false, "platform" to "android"))
        }
    }

    // MARK: - PushNotification.IsSupported

    /**
     * FCM needs Google Play services and a google-services.json baked into the
     * build. Without either, enrolment never produces a token — better to say so
     * than to leave the app waiting.
     */
    class IsSupported(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val senderId = context.resources.getIdentifier(
                "gcm_defaultSenderId", "string", context.packageName,
            )
            val configured = senderId != 0

            return BridgeResponse.success(
                mapOf(
                    "supported" to configured,
                    "platform" to "android",
                    "reason" to if (configured) "" else "missing_google_services",
                ),
            )
        }
    }

    // MARK: - Token plumbing

    /** Fetch the FCM token and hand it to PHP. */
    internal fun fetchToken(context: Context?, id: String?, event: String) {
        val appContext = context?.applicationContext ?: return

        FirebaseMessaging.getInstance().token.addOnCompleteListener { task ->
            if (!task.isSuccessful) {
                Log.e(TAG, "Failed to obtain FCM token", task.exception)
                return@addOnCompleteListener
            }

            val token = task.result ?: return@addOnCompleteListener
            PushStore.saveToken(appContext, token)

            val payload = JSONObject().apply {
                put("token", token)
                if (id != null) put("id", id)
            }

            dispatch(context, event, payload.toString())
        }
    }

    /**
     * Send an event to PHP, or queue it until an Activity exists.
     *
     * dispatchEvent needs a FragmentActivity. A token rotated while the app was
     * closed, or a push handled by the FCM service, has none — queuing is what
     * keeps those from being dropped.
     */
    internal fun dispatch(context: Context?, event: String, payloadJson: String) {
        val activity = context as? FragmentActivity ?: PushLifecycle.currentActivity

        if (activity == null) {
            context?.let { PushStore.enqueue(it, event, payloadJson) }

            return
        }

        Handler(Looper.getMainLooper()).post {
            NativeActionCoordinator.dispatchEvent(activity, event, payloadJson)
        }
    }

    /** Create the notification channel described by the app's config. */
    internal fun ensureChannel(context: Context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) {
            return
        }

        val manager = context.getSystemService(android.app.NotificationManager::class.java) ?: return
        val channelId = PushStore.channelId(context)

        // Android freezes a channel's importance once it exists, by design, so
        // an app cannot escalate itself after the fact. Only create.
        if (manager.getNotificationChannel(channelId) != null) {
            return
        }

        val importance = when (PushStore.channelImportance(context)) {
            "min" -> android.app.NotificationManager.IMPORTANCE_MIN
            "low" -> android.app.NotificationManager.IMPORTANCE_LOW
            "high" -> android.app.NotificationManager.IMPORTANCE_HIGH
            else -> android.app.NotificationManager.IMPORTANCE_DEFAULT
        }

        manager.createNotificationChannel(
            android.app.NotificationChannel(channelId, PushStore.channelName(context), importance).apply {
                description = PushStore.channelDescription(context)
            },
        )
    }
}

/**
 * Keeps track of the Activity the bridge can dispatch through, flushes whatever
 * was queued while there was none, and turns a notification tap into an event.
 */
object PushLifecycle : Application.ActivityLifecycleCallbacks {

    @Volatile
    var currentActivity: FragmentActivity? = null
        private set

    private var registered = false

    fun install(context: Context) {
        if (registered) return

        val application = context.applicationContext as? Application ?: return
        application.registerActivityLifecycleCallbacks(this)
        registered = true
    }

    override fun onActivityResumed(activity: Activity) {
        val fragmentActivity = activity as? FragmentActivity ?: return
        currentActivity = fragmentActivity

        handleLaunchIntent(fragmentActivity)
        flush(fragmentActivity)
    }

    override fun onActivityPaused(activity: Activity) {
        if (currentActivity === activity) {
            currentActivity = null
        }
    }

    /**
     * A notification tapped on a cold start reaches the app as extras on the
     * launch intent. Consume them once — clearing them afterwards, or every
     * resume would replay the same tap.
     */
    private fun handleLaunchIntent(activity: FragmentActivity) {
        val intent = activity.intent ?: return
        val payload = intent.getStringExtra(PushStore.EXTRA_PAYLOAD) ?: return
        val link = intent.getStringExtra(PushStore.EXTRA_LINK)

        intent.removeExtra(PushStore.EXTRA_PAYLOAD)
        intent.removeExtra(PushStore.EXTRA_LINK)

        val event = JSONObject().apply {
            put("payload", payload)
            if (link != null) put("link", link)
        }

        PushNotificationFunctions.dispatch(activity, PushStore.tappedEvent(activity), event.toString())
    }

    private fun flush(activity: FragmentActivity) {
        PushStore.drain(activity).forEach { (event, payload) ->
            PushNotificationFunctions.dispatch(activity, event, payload)
        }
    }

    override fun onActivityCreated(activity: Activity, savedInstanceState: Bundle?) {}

    override fun onActivityStarted(activity: Activity) {}

    override fun onActivityStopped(activity: Activity) {}

    override fun onActivitySaveInstanceState(activity: Activity, outState: Bundle) {}

    override fun onActivityDestroyed(activity: Activity) {}
}

/**
 * Registered as android.init_function, so the lifecycle observer is in place
 * from app start and anything queued while the app was closed is delivered on
 * the first resume.
 */
fun initGuppylabPush(context: Context) {
    PushLifecycle.install(context)
    PushNotificationFunctions.ensureChannel(context)
}
