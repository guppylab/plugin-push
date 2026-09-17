<?php

/**
 * Validação do plugin de push (APNs direto no iOS, FCM no Android).
 *
 * Rodar com: ./vendor/bin/pest
 */
beforeEach(function () {
    $this->pluginPath = dirname(__DIR__);
    $this->manifestPath = $this->pluginPath.'/nativephp.json';
    $this->swiftFile = $this->pluginPath.'/resources/ios/Sources/PushNotificationFunctions.swift';
    $this->kotlinFile = $this->pluginPath.'/resources/android/src/PushNotificationFunctions.kt';
    $this->serviceFile = $this->pluginPath.'/resources/android/src/PushMessagingService.kt';
});

describe('Plugin Manifest', function () {
    it('has a valid nativephp.json file', function () {
        expect(file_exists($this->manifestPath))->toBeTrue();

        json_decode(file_get_contents($this->manifestPath), true);

        expect(json_last_error())->toBe(JSON_ERROR_NONE);
    });

    it('has required fields', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);

        expect($manifest)->toHaveKeys(['name', 'namespace', 'bridge_functions']);
        expect($manifest['name'])->toBe('keepcloud/plugin-push');
        expect($manifest['namespace'])->toBe('PushNotification');
    });

    it('targets iOS and Android', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);
        expect($manifest['platforms'])->toBe(['ios', 'android']);
    });

    it('declares the three push bridge functions with ios handlers', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);

        $names = array_column($manifest['bridge_functions'], 'name');
        expect($names)->toBe([
            'PushNotification.RequestPermission',
            'PushNotification.CheckPermission',
            'PushNotification.GetToken',
        ]);

        foreach ($manifest['bridge_functions'] as $function) {
            expect($function)->toHaveKeys(['name', 'ios']);
        }
    });

    it('maps every bridge function to an Android class under the vendor package', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);
        foreach ($manifest['bridge_functions'] as $fn) {
            expect($fn['android'])->toStartWith('com.keepcloud.plugins.push.PushNotificationFunctions.');
        }
    });

    it('declares the FCM messaging service and POST_NOTIFICATIONS', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);
        expect($manifest['android']['permissions'])->toContain('android.permission.POST_NOTIFICATIONS');
        expect($manifest['android']['services'][0]['name'])->toBe('com.keepcloud.plugins.push.PushMessagingService');
        expect($manifest['android']['dependencies']['implementation'])
            ->toContain('com.google.firebase:firebase-messaging:24.1.0');

        // google-services.json NÃO é asset do plugin: o gradle plugin
        // com.google.gms.google-services exige o arquivo no module root, e o
        // NativePHP o copia de nativephp/resources/google-services.json (nível do
        // app). Por isso assets.android fica vazio aqui.
        expect($manifest['assets']['android'])->toBe([]);
    });

    it('does not re-declare the core TokenGenerated event', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);

        // O evento é do core (Native\Mobile\...); o plugin só dispara por nome.
        expect($manifest['events'])->toBe([]);
    });

    it('declares the aps-environment entitlement so push survives --rebuild', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);

        // O compilador de plugin injeta ios.entitlements na NativePHP.entitlements
        // a cada rebuild — única forma durável de manter aps-environment.
        expect($manifest['ios']['entitlements']['aps-environment'])->toBe('production');
    });
});

describe('Native Code', function () {
    it('has the iOS Swift file in resources/ios/Sources', function () {
        expect(file_exists($this->swiftFile))->toBeTrue();

        $content = file_get_contents($this->swiftFile);
        expect($content)->toContain('enum PushNotificationFunctions');
        expect($content)->toContain('BridgeFunction');
    });

    it('has a matching Swift class for every ios bridge function', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);
        $content = file_get_contents($this->swiftFile);

        foreach ($manifest['bridge_functions'] as $function) {
            $parts = explode('.', $function['ios']);
            $className = end($parts);
            expect($content)->toContain("class {$className}");
        }
    });

    it('consumes the core APNs callback and dispatches the token', function () {
        $content = file_get_contents($this->swiftFile);

        // Observa o post do AppDelegate e devolve o token cru em hex ao PHP.
        expect($content)->toContain('.didRegisterForRemoteNotifications');
        expect($content)->toContain('registerForRemoteNotifications()');
        expect($content)->toContain('LaravelBridge.shared.send');
        expect($content)->toContain('%02x');
    });
});

describe('Android Native Code', function () {
    it('has a Kotlin class for every android bridge function', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);
        $kotlin = file_get_contents($this->kotlinFile);

        expect($kotlin)->toContain('package com.keepcloud.plugins.push');
        foreach ($manifest['bridge_functions'] as $fn) {
            $className = last(explode('.', $fn['android']));
            expect($kotlin)->toContain("class {$className}");
        }
    });

    it('dispatches TokenGenerated and fetches the FCM token', function () {
        $kotlin = file_get_contents($this->kotlinFile);

        expect($kotlin)->toContain('FirebaseMessaging.getInstance().token');
        expect($kotlin)->toContain('NativeActionCoordinator.dispatchEvent');
        expect($kotlin)->toContain('POST_NOTIFICATIONS');
    });

    it('has the FirebaseMessagingService', function () {
        $service = file_get_contents($this->serviceFile);

        expect($service)->toContain('package com.keepcloud.plugins.push');
        expect($service)->toContain('class PushMessagingService : FirebaseMessagingService()');
        expect($service)->toContain('onNewToken');
        expect($service)->toContain('onMessageReceived');
    });

    it('falls back to the host app name, not a hardcoded one', function () {
        $service = file_get_contents($this->serviceFile);

        expect($service)->toContain('packageManager.getApplicationLabel(applicationInfo)');
        expect($service)->not->toContain('Vestcasa');
    });
});

describe('PHP Classes', function () {
    it('has the service provider under Keepcloud\Push', function () {
        $file = $this->pluginPath.'/src/PushNotificationServiceProvider.php';
        expect(file_exists($file))->toBeTrue();

        $content = file_get_contents($file);
        expect($content)->toContain('namespace Keepcloud\Push');
        expect($content)->toContain('class PushNotificationServiceProvider');
    });
});

describe('Composer Configuration', function () {
    it('has valid composer.json', function () {
        $composerPath = $this->pluginPath.'/composer.json';
        expect(file_exists($composerPath))->toBeTrue();

        $composer = json_decode(file_get_contents($composerPath), true);

        expect(json_last_error())->toBe(JSON_ERROR_NONE);
        expect($composer['type'])->toBe('nativephp-plugin');
        expect($composer['name'])->toBe('keepcloud/plugin-push');
        expect($composer['extra']['nativephp']['manifest'])->toBe('nativephp.json');
        expect($composer['extra']['laravel']['providers'])
            ->toBe(['Keepcloud\Push\PushNotificationServiceProvider']);
    });
});
