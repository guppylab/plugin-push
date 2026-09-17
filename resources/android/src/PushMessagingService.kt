package com.guppylab.plugins.push

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.os.Build
import androidx.core.app.NotificationCompat
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage

/**
 * Recebe pushes do FCM. onNewToken mantém o último token; onMessageReceived
 * monta a notificação local quando o app está em foreground (em background o
 * FCM já exibe a notification do payload). Canal "default".
 */
class PushMessagingService : FirebaseMessagingService() {

    override fun onNewToken(token: String) {
        // Token rotacionado pelo FCM — guarda para o GetToken servir o valor novo.
        PushNotificationFunctions.lastToken = token
    }

    override fun onMessageReceived(message: RemoteMessage) {
        val notification = message.notification ?: return
        // Sem título no payload, usa o nome do próprio app — o plugin é genérico
        // e não pode fixar o nome de um app específico aqui.
        val title = notification.title
            ?: packageManager.getApplicationLabel(applicationInfo).toString()
        val body = notification.body ?: ""

        val channelId = "default"
        val manager = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && manager.getNotificationChannel(channelId) == null) {
            manager.createNotificationChannel(
                NotificationChannel(channelId, "Notificações", NotificationManager.IMPORTANCE_DEFAULT),
            )
        }

        // Toca a Activity principal ao abrir a notificação. getLaunchIntentForPackage
        // pode retornar null (sem launcher resolvível) — fallback pra Intent vazio
        // evita NPE no PendingIntent.
        val launch = (packageManager.getLaunchIntentForPackage(packageName) ?: Intent()).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP
        }
        val pending = PendingIntent.getActivity(
            this, 0, launch,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )

        val builder = NotificationCompat.Builder(this, channelId)
            .setContentTitle(title)
            .setContentText(body)
            .setSmallIcon(applicationInfo.icon)
            .setAutoCancel(true)
            .setContentIntent(pending)

        notification.notificationCount?.let { count ->
            if (count > 0) builder.setNumber(count)
        }

        manager.notify(message.messageId?.hashCode() ?: System.currentTimeMillis().toInt(), builder.build())
    }
}
