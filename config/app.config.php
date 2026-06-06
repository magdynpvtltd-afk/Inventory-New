<?php
/**
 * MagDyn — Application Configuration
 * Created: 20260515_060024_IST
 *
 * App-wide settings. Persistent across upgrades — replacing the rest of
 * the app code should NOT touch this file.
 */

return [
    // ---- Identity ----
    'app_name'    => 'MagDyn',
    'app_tagline' => 'Operations Console',

    // The path under your web root the app lives at (no trailing slash).
    // If you serve it at https://example.com/magdyn use '/magdyn'.
    // If you serve it at the document root, use ''.
    'base_url'    => '/magdyn',

    // ---- Locale ----
    'timezone'    => 'Asia/Kolkata',

    // ---- Sessions ----
    'session_name'     => 'magdyn_sid',
    'session_lifetime' => 28800,             // seconds (8h)

    // ---- Security ----
    'password_pepper'  => 'change-me-to-a-long-random-string',
    'csrf_field'       => '_csrf',

    // ---- PWA ----
    'pwa' => [
        'theme_color'      => '#dc2626',     // matches the MagDyn red logo
        'background_color' => '#f4f5f7',
        'display'          => 'standalone',
    ],

    // ---- VAPID keys for Web Push (generate via the web-push library) ----
    'vapid' => [
        'public_key'  => '',
        'private_key' => '',
        'subject'     => 'mailto:admin@example.com',
    ],

    // ---- Uploads ----
    'upload_max_mb' => 20,

    // ---- Billing app integration (outbound ATS push) ----
    // MagDyn POSTs to /api/ats_inbound.php on the billing host whenever
    // an operator finalises an ATS. See ats.php / includes/_ats.php.
    // Leave fields blank to disable the outbound push (ATSes still get
    // created locally; the Finalize button just stays inert).
    'billing_integration' => [
        'url'          => '',   // e.g. https://billing.example.com/api/ats_inbound.php
        'product_url'  => '',   // e.g. https://billing.example.com/api/product_inbound.php (finished-good catalogue mirror; leave blank to disable)
        'bearer_token' => '',   // shared secret, same value as the inbound integration uses
        'timeout'      => 30,   // seconds; the billing app should respond in well under this
    ],

    // ---- Asset ID auto-generation ----
    // New assets get an auto-generated ID in the form <prefix><N> where
    // N is zero-padded to `pad` digits. Change these without breaking
    // existing data — the unique constraint still guards against collisions.
    'asset_id' => [
        'prefix' => 'AST-',
        'pad'    => 5,         // AST-00001, AST-00002, ...
        'start'  => 1,
    ],

    // ---- SSO ----
    'sso' => [
        'enabled'        => true,           // flip to true once a mode is configured
        'mode'           => 'oidc',          // 'oidc' | 'saml' | 'header'
        'auto_provision' => true,            // create local row on first SSO login

        'oidc' => [
            'issuer'        => '',           // e.g. https://accounts.google.com
            'client_id'     => '',
            'client_secret' => '',
            'redirect_uri'  => '',           // public URL of sso_callback endpoint
            'scopes'        => 'openid email profile',
        ],
        'saml' => [
            'idp_entity_id' => '',
            'idp_sso_url'   => '',
            'idp_x509_cert' => '',
            'sp_entity_id'  => '',
            'sp_acs_url'    => '',
        ],
        'header' => [
            'user_header'  => 'HTTP_X_FORWARDED_USER',
            'email_header' => 'HTTP_X_FORWARDED_EMAIL',
            'name_header'  => 'HTTP_X_FORWARDED_NAME',
        ],
    ],
];
