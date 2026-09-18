<?php

/**
 * Contract tests for the Push plugin (direct APNs on iOS, FCM on Android).
 *
 * These guard the things that fail silently in production: an event class the
 * native side dispatches under a name PHP does not listen for, a token that
 * never leaves the device, a response envelope read at the wrong level.
 *
 * Run with: ./vendor/bin/pest
 */
beforeEach(function () {
    $this->pluginPath = dirname(__DIR__);
    $this->manifestPath = $this->pluginPath.'/nativephp.json';
    $this->swiftFile = $this->pluginPath.'/resources/ios/Sources/PushNotificationFunctions.swift';
    $this->kotlinFile = $this->pluginPath.'/resources/android/src/PushNotificationFunctions.kt';
    $this->storeFile = $this->pluginPath.'/resources/android/src/PushStore.kt';
    $this->serviceFile = $this->pluginPath.'/resources/android/src/PushMessagingService.kt';
    $this->manifest = json_decode(file_get_contents($this->manifestPath), true);
});

describe('Plugin Manifest', function () {
    it('has a valid nativephp.json file', function () {
        expect(file_exists($this->manifestPath))->toBeTrue();

        json_decode(file_get_contents($this->manifestPath), true);

        expect(json_last_error())->toBe(JSON_ERROR_NONE);
    });

    it('has required fields', function () {
        expect($this->manifest)->toHaveKeys(['name', 'namespace', 'bridge_functions']);
        expect($this->manifest['name'])->toBe('guppylab/plugin-push');
        expect($this->manifest['namespace'])->toBe('PushNotification');
    });

    it('targets iOS and Android', function () {
        expect($this->manifest['platforms'])->toBe(['ios', 'android']);
    });

    it('implements every bridge function on both platforms', function () {
        $names = array_column($this->manifest['bridge_functions'], 'name');

        expect($names)->toBe([
            'PushNotification.RequestPermission',
            'PushNotification.CheckPermission',
            'PushNotification.GetToken',
            'PushNotification.Configure',
            'PushNotification.Unenroll',
            'PushNotification.SetBadge',
            'PushNotification.IsSupported',
        ]);

        foreach ($this->manifest['bridge_functions'] as $function) {
            expect($function)->toHaveKeys(['name', 'ios', 'android', 'android_params']);
            expect($function['ios'])->toStartWith('PushNotificationFunctions.');
            expect($function['android'])->toStartWith('com.guppylab.plugins.push.PushNotificationFunctions.');
        }
    });

    it('registers the functions that must survive a cold boot with a Context', function () {
        // Activity-bound registrations are skipped by the WorkManager path, so
        // anything that has to answer without a visible Activity asks for a
        // Context. Permission prompts genuinely need an Activity.
        $params = collect($this->manifest['bridge_functions'])
            ->mapWithKeys(fn ($fn) => [$fn['name'] => $fn['android_params']]);

        expect($params['PushNotification.RequestPermission'])->toBe(['activity']);
        expect($params['PushNotification.CheckPermission'])->toBe(['activity']);
        expect($params['PushNotification.GetToken'])->toBe(['context']);
        expect($params['PushNotification.Configure'])->toBe(['context']);
    });

    it('installs early on both platforms through an init function', function () {
        // On iOS the notification delegate has to be in place before a cold-start
        // tap arrives; on Android the lifecycle observer has to exist before the
        // first resume, or queued events are never flushed.
        expect($this->manifest['ios']['init_function'])->toBe('initGuppylabPush');
        expect($this->manifest['android']['init_function'])
            ->toBe('com.guppylab.plugins.push.initGuppylabPush');
    });

    it('declares the events it dispatches', function () {
        expect($this->manifest['events'])->toBe([
            'Guppylab\Push\Events\MessageReceived',
            'Guppylab\Push\Events\NotificationTapped',
        ]);
    });

    it('declares the FCM messaging service and POST_NOTIFICATIONS', function () {
        expect($this->manifest['android']['permissions'])
            ->toContain('android.permission.POST_NOTIFICATIONS');

        $service = $this->manifest['android']['services'][0];

        expect($service['name'])->toBe('com.guppylab.plugins.push.PushMessagingService');
        expect($service['exported'])->toBeFalse();
        expect($service['intent_filters'][0]['action'])->toBe('com.google.firebase.MESSAGING_EVENT');
    });

    it('pulls in firebase-messaging and nothing else', function () {
        // firebase-analytics was never used by the plugin; it only added weight
        // and a data-collection surface to every app installing it.
        $dependencies = $this->manifest['android']['dependencies']['implementation'];

        expect($dependencies)->toHaveCount(1);
        expect($dependencies[0])->toStartWith('com.google.firebase:firebase-messaging:');
    });

    it('declares the aps-environment entitlement so push survives --rebuild', function () {
        expect($this->manifest['ios']['entitlements']['aps-environment'])->toBe('production');
    });
});

describe('iOS', function () {
    it('implements every bridge function', function () {
        $content = file_get_contents($this->swiftFile);

        foreach (['RequestPermission', 'CheckPermission', 'GetToken', 'Configure', 'Unenroll', 'SetBadge', 'IsSupported'] as $class) {
            expect($content)->toContain("class {$class}: BridgeFunction");
        }
    });

    it('presents notifications while the app is in the foreground', function () {
        // Without willPresent, iOS shows nothing in the foreground and the
        // platform looks broken next to Android, which renders its own.
        $content = file_get_contents($this->swiftFile);

        expect($content)->toContain('willPresent notification: UNNotification');
        expect($content)->toContain('completionHandler(presentationOptions())');
    });

    it('chains to a delegate installed before it', function () {
        // A push build usually also has the core's local-notification plugin.
        // Install order between plugins is not guaranteed, so both sides chain.
        $content = file_get_contents($this->swiftFile);

        expect($content)->toContain('previousDelegate');
        expect($content)->toContain('previous.responds(to:');
    });

    it('delivers the payload and the tap, not just the token', function () {
        $content = file_get_contents($this->swiftFile);

        expect($content)->toContain('messageReceivedEvent');
        expect($content)->toContain('notificationTappedEvent');
        expect($content)->toContain('didReceive response: UNNotificationResponse');
    });

    it('holds events raised before the PHP bridge exists', function () {
        // A tap on a cold start reaches the delegate long before the runtime is
        // up; dispatching straight away would drop exactly the deep link case.
        $content = file_get_contents($this->swiftFile);

        expect($content)->toContain('pendingDispatches');
        expect($content)->toContain('scheduleFlush');
    });

    it('bounds the permission check instead of blocking the bridge thread', function () {
        $content = file_get_contents($this->swiftFile);

        expect($content)->toContain('semaphore.wait(timeout: .now() + 5)');
    });
});

describe('Android', function () {
    it('implements every bridge function', function () {
        $content = file_get_contents($this->kotlinFile);

        expect($content)->toContain('package com.guppylab.plugins.push');

        foreach (['RequestPermission', 'CheckPermission', 'GetToken', 'Configure', 'Unenroll', 'SetBadge', 'IsSupported'] as $class) {
            expect($content)->toContain("class {$class}(");
        }
    });

    it('queues events raised without an Activity instead of dropping them', function () {
        // dispatchEvent needs a FragmentActivity. A token rotated while the app
        // was closed has none — dropping it is how a device silently stops
        // receiving pushes.
        $kotlin = file_get_contents($this->kotlinFile);
        $store = file_get_contents($this->storeFile);

        expect($kotlin)->toContain('PushStore.enqueue');
        expect($store)->toContain('fun enqueue');
        expect($store)->toContain('fun drain');
    });

    it('dispatches the rotated token from the FCM service', function () {
        $service = file_get_contents($this->serviceFile);

        expect($service)->toContain('override fun onNewToken');
        expect($service)->toContain('PushNotificationFunctions.dispatch');
    });

    it('flushes queued events when an Activity resumes', function () {
        $content = file_get_contents($this->kotlinFile);

        expect($content)->toContain('ActivityLifecycleCallbacks');
        expect($content)->toContain('override fun onActivityResumed');
        expect($content)->toContain('PushStore.drain');
    });

    it('turns a cold-start tap into an event', function () {
        $kotlin = file_get_contents($this->kotlinFile);
        $service = file_get_contents($this->serviceFile);

        expect($service)->toContain('PushStore.EXTRA_PAYLOAD');
        expect($kotlin)->toContain('handleLaunchIntent');
        expect($kotlin)->toContain('intent.removeExtra(PushStore.EXTRA_PAYLOAD)');
    });

    it('does not hard-code a user-visible channel name', function () {
        // The channel name is shown in the system settings of the host app, so a
        // string baked into the plugin leaks the plugin author's language into
        // every app that installs it.
        $service = file_get_contents($this->serviceFile);
        $kotlin = file_get_contents($this->kotlinFile);

        expect($service)->not->toContain('"Notificações"');
        expect($kotlin)->toContain('PushStore.channelName(context)');
    });

    it('lets the app supply a monochrome status bar icon', function () {
        // A full-colour launcher icon is rendered as a white blob in the status
        // bar; it is only the fallback.
        $service = file_get_contents($this->serviceFile);

        expect($service)->toContain('PushStore.smallIconName');
        expect($service)->toContain('getIdentifier');
    });
});

describe('PHP Classes', function () {
    it('has the service provider under Guppylab\\Push', function () {
        $content = file_get_contents($this->pluginPath.'/src/PushNotificationServiceProvider.php');

        expect($content)->toContain('namespace Guppylab\Push');
        expect($content)->toContain('class PushNotificationServiceProvider');
        expect($content)->toContain('mergeConfigFrom');
    });

    it('configures the native side on boot', function () {
        $content = file_get_contents($this->pluginPath.'/src/PushNotificationServiceProvider.php');

        expect($content)->toContain("config('push.auto_configure'");
        expect($content)->toContain('->configure()');
    });

    it('exposes what the core facade has no API for', function () {
        $content = file_get_contents($this->pluginPath.'/src/Push.php');

        foreach (['configure', 'unenroll', 'setBadge', 'clearBadge', 'isSupported'] as $method) {
            expect($content)->toContain("function {$method}");
        }
    });

    it('ships the events it dispatches', function () {
        expect(file_exists($this->pluginPath.'/src/Events/MessageReceived.php'))->toBeTrue();
        expect(file_exists($this->pluginPath.'/src/Events/NotificationTapped.php'))->toBeTrue();

        $tapped = file_get_contents($this->pluginPath.'/src/Events/NotificationTapped.php');

        expect($tapped)->toContain('public readonly ?string $link');
    });

    it('ships a publishable config', function () {
        // Read as source rather than evaluated: env() needs a booted app, and
        // what matters here is the shipped defaults.
        $config = file_get_contents($this->pluginPath.'/config/push.php');

        expect($config)->toContain("'auto_configure' =>");
        expect($config)->toContain("'channel' =>");
        expect($config)->toContain("'small_icon' =>");
        expect($config)->toContain("'foreground_presentation' =>");
    });
});

describe('JavaScript Library', function () {
    it('ships a JS library with TypeScript definitions', function () {
        expect(file_exists($this->pluginPath.'/resources/js/index.js'))->toBeTrue();
        expect(file_exists($this->pluginPath.'/resources/js/index.d.ts'))->toBeTrue();

        $js = file_get_contents($this->pluginPath.'/resources/js/index.js');

        expect($js)->toContain('PushNotification.RequestPermission');
        expect($js)->toContain('export');
    });

    it('unwraps the native-call envelope', function () {
        // /_native/api/call answers { status, data }. Reading the top level made
        // checkPermission() and getToken() always return their fallback.
        $js = file_get_contents($this->pluginPath.'/resources/js/index.js');

        expect($js)->toContain('const nativeResponse = result.data;');
        expect($js)->toContain("result.status === 'error'");
        expect($js)->toContain('X-CSRF-TOKEN');
    });

    it('exposes the push payload and taps', function () {
        $js = file_get_contents($this->pluginPath.'/resources/js/index.js');

        expect($js)->toContain('export function onMessage');
        expect($js)->toContain('export function onTapped');
        expect($js)->toContain('export async function unenroll');
    });
});

describe('Composer Configuration', function () {
    it('has a valid composer.json with the nativephp-plugin type', function () {
        $composer = json_decode(file_get_contents($this->pluginPath.'/composer.json'), true);

        expect(json_last_error())->toBe(JSON_ERROR_NONE);
        expect($composer['name'])->toBe('guppylab/plugin-push');
        expect($composer['type'])->toBe('nativephp-plugin');
        expect($composer['extra']['nativephp']['manifest'])->toBe('nativephp.json');
        expect($composer['extra']['laravel']['providers'])
            ->toBe(['Guppylab\Push\PushNotificationServiceProvider']);
    });
});
