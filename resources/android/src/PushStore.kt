package com.guppylab.plugins.push

import android.content.Context
import android.util.Log
import org.json.JSONArray
import org.json.JSONObject

/**
 * Durable state shared between the bridge functions and the FCM service.
 *
 * The service that receives a push runs without an Activity and, on a cold
 * start, without a PHP runtime — it cannot ask the app how it wants
 * notifications rendered, and it cannot dispatch an event. So the settings that
 * come from config/push.php are persisted here when PHP hands them over, and
 * events raised while nothing can receive them are queued until an Activity is
 * resumed.
 */
object PushStore {

    private const val TAG = "PushNotification"
    private const val PREFS = "guppylab_push"

    private const val KEY_CONFIG = "config"
    private const val KEY_TOKEN = "token"
    private const val KEY_PENDING = "pending_events"

    const val EXTRA_PAYLOAD = "guppylab_push_payload"
    const val EXTRA_LINK = "guppylab_push_link"

    const val DEFAULT_TOKEN_EVENT = "Native\\Mobile\\Events\\PushNotification\\TokenGenerated"
    const val DEFAULT_MESSAGE_EVENT = "Guppylab\\Push\\Events\\MessageReceived"
    const val DEFAULT_TAPPED_EVENT = "Guppylab\\Push\\Events\\NotificationTapped"

    private fun prefs(context: Context) =
        context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    // MARK: - Configuration

    fun saveConfig(context: Context, config: JSONObject) {
        prefs(context).edit().putString(KEY_CONFIG, config.toString()).apply()
    }

    fun config(context: Context): JSONObject {
        val raw = prefs(context).getString(KEY_CONFIG, null) ?: return JSONObject()

        return runCatching { JSONObject(raw) }.getOrElse { JSONObject() }
    }

    fun channelId(context: Context): String =
        config(context).optJSONObject("channel")?.optString("id")?.takeIf { it.isNotEmpty() }
            ?: "default"

    fun channelName(context: Context): String =
        config(context).optJSONObject("channel")?.optString("name")?.takeIf { it.isNotEmpty() }
            ?: "Notifications"

    fun channelDescription(context: Context): String =
        config(context).optJSONObject("channel")?.optString("description") ?: ""

    fun channelImportance(context: Context): String =
        config(context).optJSONObject("channel")?.optString("importance")?.takeIf { it.isNotEmpty() }
            ?: "default"

    fun smallIconName(context: Context): String? =
        config(context).optJSONObject("android")?.optString("small_icon")?.takeIf { it.isNotEmpty() }

    fun accentColor(context: Context): String? =
        config(context).optJSONObject("android")?.optString("color")?.takeIf { it.isNotEmpty() }

    fun messageEvent(context: Context): String =
        config(context).optJSONObject("events")?.optString("message_received")?.takeIf { it.isNotEmpty() }
            ?: DEFAULT_MESSAGE_EVENT

    fun tappedEvent(context: Context): String =
        config(context).optJSONObject("events")?.optString("notification_tapped")?.takeIf { it.isNotEmpty() }
            ?: DEFAULT_TAPPED_EVENT

    fun deepLinkKeys(context: Context): List<String> {
        val keys = config(context).optJSONArray("deep_link_keys") ?: return listOf("link", "url")

        return (0 until keys.length()).mapNotNull { keys.optString(it).takeIf { key -> key.isNotEmpty() } }
            .ifEmpty { listOf("link", "url") }
    }

    // MARK: - Token

    var cachedToken: String? = null

    fun token(context: Context): String? {
        cachedToken?.let { return it }

        return prefs(context).getString(KEY_TOKEN, null)?.also { cachedToken = it }
    }

    fun saveToken(context: Context, token: String) {
        cachedToken = token
        prefs(context).edit().putString(KEY_TOKEN, token).apply()
    }

    fun clearToken(context: Context) {
        cachedToken = null
        prefs(context).edit().remove(KEY_TOKEN).apply()
    }

    // MARK: - Pending events

    /**
     * Hold an event until an Activity exists to dispatch it through.
     *
     * NativeActionCoordinator.dispatchEvent requires a FragmentActivity, so a
     * token rotated while the app was closed, or a push received in the
     * background, has nowhere to go at the moment it happens. Dropping it is how
     * a rotated token silently stops reaching the backend.
     */
    @Synchronized
    fun enqueue(context: Context, event: String, payloadJson: String) {
        val pending = pendingArray(context)

        pending.put(
            JSONObject().apply {
                put("event", event)
                put("payload", payloadJson)
            },
        )

        // Bound the queue: a device offline for a week should not replay a
        // hundred stale pushes the moment it opens.
        val trimmed = JSONArray()
        val start = maxOf(0, pending.length() - 50)
        for (index in start until pending.length()) {
            trimmed.put(pending.get(index))
        }

        prefs(context).edit().putString(KEY_PENDING, trimmed.toString()).apply()
    }

    @Synchronized
    fun drain(context: Context): List<Pair<String, String>> {
        val pending = pendingArray(context)

        if (pending.length() == 0) {
            return emptyList()
        }

        prefs(context).edit().remove(KEY_PENDING).apply()

        return (0 until pending.length()).mapNotNull { index ->
            val entry = pending.optJSONObject(index) ?: return@mapNotNull null
            val event = entry.optString("event").takeIf { it.isNotEmpty() } ?: return@mapNotNull null

            event to entry.optString("payload", "{}")
        }
    }

    private fun pendingArray(context: Context): JSONArray {
        val raw = prefs(context).getString(KEY_PENDING, null) ?: return JSONArray()

        return runCatching { JSONArray(raw) }.getOrElse {
            Log.w(TAG, "Discarding unreadable pending push events")
            JSONArray()
        }
    }
}
