<?php

/**
 * Hub configuration.
 *
 * The archive server: a website for browsing extensions and a small JSON
 * API the bot's console talks to. No database, no composer -- packages and
 * their metadata are files under hub/storage.
 *
 * Copy this folder to your host, point a vhost at hub/public/, and edit the
 * values below.
 */

return [

    // Shown in the page title and header.
    'name' => 'Botex Archive',

    'tagline' => 'Extensions for Botex bots.',

    // Public base URL, no trailing slash. Used for absolute links and for
    // the install commands shown on a package page. Leave empty to derive
    // it from the request, which is fine unless you sit behind a proxy that
    // rewrites the host.
    'url' => '',

    // Where packages and the generated index live. Outside public/ so a
    // misconfigured vhost cannot serve the raw storage directory.
    'storage' => __DIR__ . '/storage',

    // Release lines a package may be published to. The first is the
    // default. A bot follows one, set as archive.channel in its config.
    'channels' => ['stable', 'beta'],

    // Private key used to sign packages, if you want signing. Generate a
    // pair with: php hub/bin/hub keygen
    //
    // Keep this OUTSIDE the web root and readable only by the publishing
    // user. A bot that has the matching public key in its config will then
    // refuse any package this key did not sign.
    'signing_key' => '',

    // Passphrase for the signing key, if it has one.
    'signing_key_passphrase' => '',

    // Token for the HTTP upload endpoint. Empty -- the default -- disables
    // uploading entirely, so publishing is only possible from the CLI on
    // the server itself. That is the safer arrangement: an upload endpoint
    // is a way to put executable code on every bot that follows this
    // archive, so it stays off until you decide otherwise.
    'upload_token' => '',

    // Packages per page when browsing.
    'per_page' => 20,

    // Sent as Cache-Control on API responses, in seconds. The bot caches
    // locally as well, so this is only about proxies in between.
    'cache_seconds' => 300,

    // Extra footer line: contact details, a link to your repo, whatever.
    'footer' => '',
];
