<?php
/**
 * Configuration template for the IACUBUS / ILIAS Pegasus mobile app
 * endpoint tests (tests/mobile_app/test_ilias_app_v3.php).
 *
 * Copy this file to config.php (same directory) and fill in real values.
 * config.php is gitignored -- never commit real credentials.
 */

return array(
    // Base URL of this plugin's api.php, no trailing slash.
    // Example: 'https://your-ilias-domain.tld/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/REST/api.php'
    'baseUrl' => 'https://your-ilias-domain.tld/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/REST/api.php',

    // REST API client (create one via ClientConfiguration::createClient()
    // or the plugin's admin UI). Needs the 'ilias_app_v3' permissions.
    'apiKey' => 'your-api-key',
    'apiSecret' => 'your-api-secret',

    // ILIAS user the mobile app would log in as (Resource Owner Password
    // Credentials grant). This user's permissions determine what the
    // 'object'/'files' tests can see.
    'username' => 'test_user',
    'password' => 'test_password',

    // Ref-ID of a repository object (course, folder, file, etc.) the test
    // user can read. Used by the GET /v3/ilias-app/object/:refId test.
    'objectRefId' => 0,

    // Ref-ID of an actual File object (ilObjFile) the test user can read.
    // Used by the GET /v3/ilias-app/files/:refId test.
    'fileRefId' => 0,

    // Set to true to also run the learning-progress-to-done test. This is
    // opt-in because it WRITES data (marks the file above as read/done for
    // the test user) -- only enable against a disposable test object.
    'runDestructiveTests' => false,

    // TLS verification. Only disable for local/self-signed test setups.
    'verifyTls' => true,
);
