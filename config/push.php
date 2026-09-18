<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Automatic configuration
    |--------------------------------------------------------------------------
    |
    | The native side cannot read this file, so the plugin pushes the settings
    | below across the bridge once per process, on boot. Turn this off if you
    | would rather call Push::configure() yourself at a moment of your choosing.
    |
    */

    'auto_configure' => true,

    /*
    |--------------------------------------------------------------------------
    | Notification channel (Android)
    |--------------------------------------------------------------------------
    |
    | Android groups notifications into user-visible channels: the name and
    | description below are what someone reads in the system settings of YOUR
    | app, so translate them. Importance is one of min, low, default or high —
    | "high" is what makes a notification appear as a heads-up banner.
    |
    | Changing importance after a channel exists has no effect: Android freezes
    | it once created, on purpose, so an app cannot escalate itself. Ship a new
    | channel id when you need a different importance.
    |
    */

    'channel' => [
        'id' => env('PUSH_CHANNEL_ID', 'default'),
        'name' => env('PUSH_CHANNEL_NAME', 'Notifications'),
        'description' => env('PUSH_CHANNEL_DESCRIPTION', ''),
        'importance' => env('PUSH_CHANNEL_IMPORTANCE', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Android appearance
    |--------------------------------------------------------------------------
    |
    | small_icon is the name of a drawable in the app (without extension), e.g.
    | "ic_notification". Android renders the status bar icon as a silhouette, so
    | a full-colour launcher icon shows up as a white blob — ship a white,
    | transparent-background drawable. Left empty, the launcher icon is used.
    |
    | color tints the icon and the app name on the notification, as #RRGGBB.
    |
    */

    'android' => [
        'small_icon' => env('PUSH_SMALL_ICON'),
        'color' => env('PUSH_ACCENT_COLOR'),
    ],

    /*
    |--------------------------------------------------------------------------
    | iOS appearance
    |--------------------------------------------------------------------------
    |
    | How a push is presented while the app is in the foreground. iOS shows
    | nothing by default — without this the notification is delivered silently
    | and Android looks broken by comparison. Any of banner, list, sound, badge.
    |
    | provisional asks for quiet notifications that need no permission prompt;
    | they arrive silently in the notification centre until the user promotes
    | them. Android has no equivalent, so checkPermission() reports "provisional"
    | on iOS only.
    |
    */

    'ios' => [
        'foreground_presentation' => ['banner', 'sound', 'badge'],
        'provisional' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Deep links
    |--------------------------------------------------------------------------
    |
    | Payload keys searched, in order, for a URL to open when a notification is
    | tapped. Both the top level and a nested "data" object are inspected, which
    | covers the shape FCM and APNs payloads tend to arrive in.
    |
    */

    'deep_link_keys' => ['link', 'url'],
];
