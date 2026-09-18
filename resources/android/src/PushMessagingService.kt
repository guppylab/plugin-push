package com.guppylab.plugins.push

import android.app.PendingIntent
import android.content.Intent
import android.graphics.Color
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import org.json.JSONObject

/**
 * Receives pushes from FCM.
 *
 * Called for every data message, and for notification messages only while the
 * app is in the foreground — in the background FCM renders those itself from the
 * payload. Either way the payload is handed to PHP, which is what lets an app
 * react to a push rather than merely be told one arrived.
 */
class PushMessagingService : FirebaseMessagingService() {

    override fun onNewToken(token: String) {
        // FCM rotates tokens on its own schedule, usually while the app is not
        // in the foreground. Persisting it is not enough: unless the new token
        // reaches the backend, pushes stop arriving and nothing reports why. So
        // the event is queued and delivered on the next resume.
        PushStore.saveToken(applicationContext, token)

        val payload = JSONObject().apply { put("token", token) }

        PushNotificationFunctions.dispatch(
            applicationContext,
            PushStore.DEFAULT_TOKEN_EVENT,
            payload.toString(),
        )
    }

    override fun onMessageReceived(message: RemoteMessage) {
        val notification = message.notification
        val data = message.data

        val title = notification?.title ?: data["title"]
        val body = notification?.body ?: data["body"]

        val payload = JSONObject().apply {
            data.forEach { (key, value) -> put(key, value) }
        }

        // Tell PHP first: a data-only push may carry nothing to display, and the
        // app still needs to know it arrived.
        val event = JSONObject().apply {
            put("payload", payload.toString())
            if (title != null) put("title", title)
            if (body != null) put("body", body)
        }

        PushNotificationFunctions.dispatch(
            applicationContext,
            PushStore.messageEvent(applicationContext),
            event.toString(),
        )

        if (title == null && body == null) {
            return
        }

        show(message, title, body, payload)
    }

    private fun show(message: RemoteMessage, title: String?, body: String?, payload: JSONObject) {
        PushNotificationFunctions.ensureChannel(applicationContext)

        val channelId = PushStore.channelId(applicationContext)
        val link = deepLink(payload)

        // Reopen the app and carry the payload along, so a tap on a cold start
        // still produces a NotificationTapped event with its data intact.
        val launch = (packageManager.getLaunchIntentForPackage(packageName) ?: Intent()).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP
            putExtra(PushStore.EXTRA_PAYLOAD, payload.toString())
            if (link != null) putExtra(PushStore.EXTRA_LINK, link)
        }

        val pending = PendingIntent.getActivity(
            this,
            message.messageId?.hashCode() ?: 0,
            launch,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )

        val builder = NotificationCompat.Builder(this, channelId)
            // No title in the payload: fall back to the host app's own name. The
            // plugin is generic and cannot hard-code one.
            .setContentTitle(title ?: packageManager.getApplicationLabel(applicationInfo).toString())
            .setContentText(body ?: "")
            .setSmallIcon(smallIcon())
            .setAutoCancel(true)
            .setContentIntent(pending)

        body?.let { builder.setStyle(NotificationCompat.BigTextStyle().bigText(it)) }

        accentColor()?.let {
            builder.color = it
            builder.setColorized(false)
        }

        message.notification?.notificationCount?.let { count ->
            if (count > 0) builder.setNumber(count)
        }

        NotificationManagerCompat.from(this).notify(
            message.messageId?.hashCode() ?: System.currentTimeMillis().toInt(),
            builder.build(),
        )
    }

    /**
     * Status bar icons are drawn as a silhouette, so a full-colour launcher icon
     * shows up as a white blob. Apps are expected to ship a white,
     * transparent-background drawable and name it in config/push.php; the
     * launcher icon is only the fallback.
     */
    private fun smallIcon(): Int {
        val name = PushStore.smallIconName(applicationContext)

        if (!name.isNullOrEmpty()) {
            val id = resources.getIdentifier(name, "drawable", packageName)
            if (id != 0) {
                return id
            }

            val mipmap = resources.getIdentifier(name, "mipmap", packageName)
            if (mipmap != 0) {
                return mipmap
            }
        }

        return applicationInfo.icon
    }

    private fun accentColor(): Int? {
        val color = PushStore.accentColor(applicationContext) ?: return null

        return runCatching { Color.parseColor(color) }.getOrNull()
    }

    private fun deepLink(payload: JSONObject): String? {
        val nested = payload.optJSONObject("data")

        for (key in PushStore.deepLinkKeys(applicationContext)) {
            payload.optString(key).takeIf { it.isNotEmpty() }?.let { return it }
            nested?.optString(key)?.takeIf { it.isNotEmpty() }?.let { return it }
        }

        return null
    }
}
