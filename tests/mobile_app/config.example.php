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

    // Ref-ID of a container object (course, folder, category, ...) the test
    // user can read, with at least one visible child. Used by the
    // GET /v2/ilias-app/objects/:refId test. Leave as 0 to skip.
    'parentRefId' => 0,

    // Ref-ID of a supported learning module (HTLM or SCORM/SAHS) the test
    // user can read. Used by the GET /v1/learning-module/:refId test.
    // Leave as 0 to skip.
    'learningModuleRefId' => 0,

    // Object-ID (not ref-ID) of a Learnplace the test user can read. Only
    // relevant if the Learnplaces plugin is installed and active. Used by
    // the GET/POST /v2/ilias-app/learnplace/* tests. Leave as 0 to skip.
    'learnplaceObjectId' => 0,

    // Set to true to also run the learning-progress-to-done and
    // learnplace journal-entry tests. This is opt-in because it WRITES data
    // (marks the file above as read/done, records a learnplace visit) --
    // only enable against disposable test objects.
    'runDestructiveTests' => false,

    // TLS verification. Only disable for local/self-signed test setups.
    'verifyTls' => true,
);
