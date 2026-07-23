<?php
/**
 * Endpoint tests covering every route the current IACUBUS / ILIAS Pegasus
 * mobile app calls:
 *   - POST /v2/oauth2/token (password and refresh_token grants)
 *   - GET  /v2/ilias-app/auth-token, /desktop, /objects/:refId, /news
 *   - GET  /v2/ilias-app/learnplace/:objectId (+ /journal-entries,
 *          /blocks, POST /journal-entry)
 *   - GET  /v3/ilias-app/object/:refId, /files/:refId, /theme
 *   - POST /v3/ilias-app/files/:refId/learning-progress-to-done
 *   - GET  /v1/files/:id (files_v1) and /v1/learning-module/:refId
 *
 * Not covered: the initial GET/POST login.php?target=ilias_app_oauth2 page.
 * That's an ILIAS core HTML login screen, not a JSON route served by this
 * plugin's api.php, so it's out of scope for this API client.
 *
 * This is a standalone CLI script -- no PHPUnit, no Composer dev
 * dependencies. It authenticates against a real, running ILIAS instance
 * (Resource Owner Password Credentials grant, same as the mobile app) and
 * exercises each route, checking status codes and response shape.
 *
 * Usage:
 *   1. cp tests/mobile_app/config.example.php tests/mobile_app/config.php
 *   2. Fill in tests/mobile_app/config.php with real credentials, a test
 *      user, and known-good ref-IDs (see comments in config.example.php).
 *   3. php tests/mobile_app/test_ilias_app_v3.php
 *
 * Exit code is 0 if every executed test passed, 1 otherwise. Tests that
 * need a ref-ID that wasn't configured are skipped, not failed.
 */

declare(strict_types=1);

// Guard against execution as a side effect of being merely require()'d/include()'d
// -- e.g. ILIAS's own component/classmap scanning during `composer install` at the
// ILIAS root walks every .php file under Customizing/global/plugins. This script is
// only meant to run when invoked directly: `php test_ilias_app_v3.php`.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== realpath(__FILE__)) {
    return;
}

require_once __DIR__ . '/RestApiTestClient.php';

// ---------------------------------------------------------------------
// Tiny test runner (no external dependencies)
// ---------------------------------------------------------------------

final class TestRunner
{
    private $passed = 0;
    private $failed = 0;
    private $skipped = 0;

    public function run(string $name, callable $test): void
    {
        echo str_pad("  $name", 70, '.');
        try {
            $test();
            echo " PASS\n";
            $this->passed++;
        } catch (TestSkipped $e) {
            echo " SKIP (" . $e->getMessage() . ")\n";
            $this->skipped++;
        } catch (\Throwable $e) {
            echo " FAIL\n";
            echo "      " . get_class($e) . ': ' . $e->getMessage() . "\n";
            $this->failed++;
        }
    }

    public function summary(): int
    {
        echo "\n" . str_repeat('-', 50) . "\n";
        printf(
            "%d passed, %d failed, %d skipped\n",
            $this->passed,
            $this->failed,
            $this->skipped
        );
        return $this->failed > 0 ? 1 : 0;
    }
}

final class TestSkipped extends \Exception
{
}

function assertStatus(int $expected, array $result, string $context): void
{
    if ($result['status'] !== $expected) {
        throw new \RuntimeException(
            "$context: expected HTTP $expected, got HTTP {$result['status']} (body: {$result['body']})"
        );
    }
}

function assertJson(array $result, string $context): array
{
    if ($result['json'] === null) {
        throw new \RuntimeException("$context: response was not valid JSON (body: {$result['body']})");
    }
    return $result['json'];
}

function assertHasKeys(array $json, array $keys, string $context): void
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $json)) {
            throw new \RuntimeException(
                "$context: expected key '$key' missing from response (got: " . implode(', ', array_keys($json)) . ')'
            );
        }
    }
}

/**
 * Asserts the response decoded to a JSON array (list or empty), returning it.
 * If non-empty and $itemKeys is given, checks the first item has those keys --
 * these list endpoints legitimately return an empty array (eg. no desktop
 * items), so we can only assert item shape when there's at least one item.
 */
function assertJsonArray(array $result, string $context, array $itemKeys = array()): array
{
    $json = assertJson($result, $context);
    if (count($json) > 0 && !array_is_list($json)) {
        throw new \RuntimeException(
            "$context: expected a JSON array/list, got an object with keys: " . implode(', ', array_keys($json))
        );
    }
    if (!empty($itemKeys) && count($json) > 0) {
        assertHasKeys((array) $json[0], $itemKeys, "$context (first item)");
    }
    return $json;
}

// ---------------------------------------------------------------------
// Load configuration
// ---------------------------------------------------------------------

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "Missing tests/mobile_app/config.php.\n");
    fwrite(STDERR, "Copy tests/mobile_app/config.example.php to config.php and fill in real values first.\n");
    exit(1);
}
$config = require $configFile;

foreach (array('baseUrl', 'apiKey', 'apiSecret', 'username', 'password') as $required) {
    if (empty($config[$required])) {
        fwrite(STDERR, "config.php: '$required' must be set.\n");
        exit(1);
    }
}

$objectRefId = (int) ($config['objectRefId'] ?? 0);
$fileRefId = (int) ($config['fileRefId'] ?? 0);
$parentRefId = (int) ($config['parentRefId'] ?? 0);
$learningModuleRefId = (int) ($config['learningModuleRefId'] ?? 0);
$learnplaceObjectId = (int) ($config['learnplaceObjectId'] ?? 0);
$runDestructive = (bool) ($config['runDestructiveTests'] ?? false);

// ---------------------------------------------------------------------
// Authenticate
// ---------------------------------------------------------------------

echo "IACUBUS / ILIAS Pegasus mobile app -- ilias_app_v3 endpoint tests\n";
echo "Target: {$config['baseUrl']}\n\n";

$client = new RestApiTestClient($config['baseUrl'], $config['verifyTls'] ?? true);

echo "Authenticating as '{$config['username']}' ...\n";
try {
    $token = $client->authenticateWithPassword(
        $config['apiKey'],
        $config['apiSecret'],
        $config['username'],
        $config['password']
    );
    echo "  OK (token expires in {$token['expires_in']}s)\n\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "Authentication failed: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Check baseUrl/apiKey/apiSecret/username/password in config.php.\n");
    exit(1);
}

$runner = new TestRunner();

// ---------------------------------------------------------------------
// POST /v2/oauth2/token (refresh_token grant)
// ---------------------------------------------------------------------

$runner->run('POST /v2/oauth2/token exchanges a refresh token for a new access token', function () use ($client, $config, $token) {
    // refresh_token comes back as boolean false (not a token string) unless the
    // REST client has the 'refresh_resource_owner' setting enabled -- see
    // Common::GetResponse() in RESTController/core/oauth2_v2/models/Common.php.
    if (empty($token['refresh_token'])) {
        throw new TestSkipped("client '{$config['apiKey']}' is not configured for refresh tokens (refresh_resource_owner)");
    }
    $result = $client->refreshAccessToken($config['apiKey'], $config['apiSecret'], $token['refresh_token']);
    assertStatus(200, $result, 'refresh token request');
    $json = assertJson($result, 'refresh token request');
    assertHasKeys($json, array('access_token', 'refresh_token', 'expires_in', 'token_type'), 'refresh token response');
});

// ---------------------------------------------------------------------
// GET /v2/ilias-app/auth-token
// ---------------------------------------------------------------------

$runner->run('GET /v2/ilias-app/auth-token requires a token', function () use ($client) {
    $result = $client->get('/v2/ilias-app/auth-token', array(), false);
    assertStatus(401, $result, 'unauthenticated auth-token request');
});

$runner->run('GET /v2/ilias-app/auth-token returns a login token', function () use ($client) {
    $result = $client->get('/v2/ilias-app/auth-token', array(), true);
    assertStatus(200, $result, 'auth-token request');
    // ILIASAppRoutes.php v2 '/auth-token' builds its body as
    // json_encode("{\"token\":\"$token\"}"), which -- read literally -- would
    // double-JSON-encode the payload into a JSON string rather than an object.
    // In practice this has been observed to come back as a plain
    // {"token": "..."} object (single-encoded), so accept either shape rather
    // than assume which one a given deployment/opcache state produces.
    $body = $result['json'];
    if (is_string($body)) {
        $body = json_decode($body, true);
    }
    if (!is_array($body) || empty($body['token'])) {
        throw new \RuntimeException("auth-token request: expected a non-empty 'token' in the response (got: {$result['body']})");
    }
});

// ---------------------------------------------------------------------
// GET /v2/ilias-app/desktop
// ---------------------------------------------------------------------

$runner->run('GET /v2/ilias-app/desktop requires a token', function () use ($client) {
    $result = $client->get('/v2/ilias-app/desktop', array(), false);
    assertStatus(401, $result, 'unauthenticated desktop request');
});

$runner->run('GET /v2/ilias-app/desktop returns desktop items', function () use ($client) {
    $result = $client->get('/v2/ilias-app/desktop', array(), true);
    assertStatus(200, $result, 'desktop request');
    assertJsonArray($result, 'desktop request', array(
        'objId', 'title', 'description', 'hasPageLayout', 'hasTimeline',
        'permissionType', 'refId', 'parentRefId', 'type', 'link', 'repoPath',
    ));
});

// ---------------------------------------------------------------------
// GET /v2/ilias-app/objects/:refId
// ---------------------------------------------------------------------

$runner->run('GET /v2/ilias-app/objects/:refId requires a token', function () use ($client, $parentRefId) {
    if ($parentRefId <= 0) {
        throw new TestSkipped('config.parentRefId not set');
    }
    $result = $client->get("/v2/ilias-app/objects/$parentRefId", array(), false);
    assertStatus(401, $result, 'unauthenticated objects request');
});

$runner->run('GET /v2/ilias-app/objects/:refId returns child objects', function () use ($client, $parentRefId) {
    if ($parentRefId <= 0) {
        throw new TestSkipped('config.parentRefId not set');
    }
    $result = $client->get("/v2/ilias-app/objects/$parentRefId", array(), true);
    assertStatus(200, $result, 'objects request');
    assertJsonArray($result, 'objects request', array(
        'objId', 'title', 'description', 'hasPageLayout', 'hasTimeline',
        'permissionType', 'refId', 'parentRefId', 'type', 'link', 'repoPath',
    ));
});

// ---------------------------------------------------------------------
// GET /v2/ilias-app/news
// ---------------------------------------------------------------------

$runner->run('GET /v2/ilias-app/news requires a token', function () use ($client) {
    $result = $client->get('/v2/ilias-app/news', array(), false);
    assertStatus(401, $result, 'unauthenticated news request');
});

$runner->run('GET /v2/ilias-app/news returns news items', function () use ($client) {
    $result = $client->get('/v2/ilias-app/news', array(), true);
    assertStatus(200, $result, 'news request');
    assertJsonArray($result, 'news request', array(
        'newsId', 'newsContext', 'title', 'subtitle', 'content', 'createDate', 'updateDate',
    ));
});

// ---------------------------------------------------------------------
// GET /v3/ilias-app/theme
// ---------------------------------------------------------------------

$runner->run('GET /v3/ilias-app/theme requires a token', function () use ($client) {
    $result = $client->get('/v3/ilias-app/theme', array('timestamp' => 0), false);
    assertStatus(401, $result, 'unauthenticated theme request');
});

$runner->run('GET /v3/ilias-app/theme returns theme data', function () use ($client) {
    $result = $client->get('/v3/ilias-app/theme', array('timestamp' => 0), true);
    assertStatus(200, $result, 'theme request');
    $json = assertJson($result, 'theme request');
    assertHasKeys($json, array(
        'themePrimaryColor',
        'themeContrastColor',
        'themeTimestamp',
        'themeIconResources',
    ), 'theme response');
});

// ---------------------------------------------------------------------
// GET /v3/ilias-app/object/:refId
// ---------------------------------------------------------------------

$runner->run('GET /v3/ilias-app/object/:refId requires a token', function () use ($client, $objectRefId) {
    if ($objectRefId <= 0) {
        throw new TestSkipped('config.objectRefId not set');
    }
    $result = $client->get("/v3/ilias-app/object/$objectRefId", array(), false);
    assertStatus(401, $result, 'unauthenticated object request');
});

$runner->run('GET /v3/ilias-app/object/:refId returns object data', function () use ($client, $objectRefId) {
    if ($objectRefId <= 0) {
        throw new TestSkipped('config.objectRefId not set');
    }
    $result = $client->get("/v3/ilias-app/object/$objectRefId", array(), true);
    assertStatus(200, $result, 'object request');
    $json = assertJson($result, 'object request');
    assertHasKeys($json, array(
        'objId', 'title', 'description', 'hasPageLayout', 'hasTimeline',
        'permissionType', 'refId', 'parentRefId', 'type', 'link', 'repoPath',
    ), 'object response');
    if ((int) $json['refId'] !== $objectRefId) {
        throw new \RuntimeException("object response refId mismatch: expected $objectRefId, got {$json['refId']}");
    }
});

// ---------------------------------------------------------------------
// GET /v3/ilias-app/files/:refId
// ---------------------------------------------------------------------

$runner->run('GET /v3/ilias-app/files/:refId requires a token', function () use ($client, $fileRefId) {
    if ($fileRefId <= 0) {
        throw new TestSkipped('config.fileRefId not set');
    }
    $result = $client->get("/v3/ilias-app/files/$fileRefId", array(), false);
    assertStatus(401, $result, 'unauthenticated files request');
});

$runner->run('GET /v3/ilias-app/files/:refId returns file metadata', function () use ($client, $fileRefId) {
    if ($fileRefId <= 0) {
        throw new TestSkipped('config.fileRefId not set');
    }
    $result = $client->get("/v3/ilias-app/files/$fileRefId", array(), true);
    assertStatus(200, $result, 'files request');
    $json = assertJson($result, 'files request');
    assertHasKeys($json, array(
        'fileExtension', 'fileName', 'fileSize', 'fileType',
        'fileVersion', 'fileVersionDate', 'fileLearningProgress',
    ), 'files response');
});

// ---------------------------------------------------------------------
// POST /v3/ilias-app/files/:refId/learning-progress-to-done
// (opt-in: writes data)
// ---------------------------------------------------------------------

$runner->run('POST /v3/ilias-app/files/:refId/learning-progress-to-done requires a token', function () use ($client, $fileRefId) {
    if ($fileRefId <= 0) {
        throw new TestSkipped('config.fileRefId not set');
    }
    $result = $client->post("/v3/ilias-app/files/$fileRefId/learning-progress-to-done", array(), false);
    assertStatus(401, $result, 'unauthenticated learning-progress request');
});

$runner->run('POST /v3/ilias-app/files/:refId/learning-progress-to-done marks progress done', function () use ($client, $fileRefId, $runDestructive) {
    if (!$runDestructive) {
        throw new TestSkipped('config.runDestructiveTests is false');
    }
    if ($fileRefId <= 0) {
        throw new TestSkipped('config.fileRefId not set');
    }
    $result = $client->post("/v3/ilias-app/files/$fileRefId/learning-progress-to-done", array(), true);
    assertStatus(200, $result, 'learning-progress request');
    $json = assertJson($result, 'learning-progress request');
    assertHasKeys($json, array('message'), 'learning-progress response');
});

// ---------------------------------------------------------------------
// GET /v1/files/:id (files_v1 extension)
//
// Unlike the /v3/ilias-app/* routes above, this one is gated by
// RESTAuth::PERMISSION rather than RESTAuth::TOKEN: it checks the token
// AND that the API client has an explicit route permission configured for
// 'GET /v1/files/:id' (table ui_uihk_rest_perm). Both a missing/invalid
// token AND a valid token without that route permission come back as
// HTTP 401 -- so a 401 on the "returns file metadata" test below does not
// necessarily mean authentication itself is broken, it may just mean the
// configured API client hasn't been granted this route. See the assertion
// message for the distinguishing 'no permission' hint.
// ---------------------------------------------------------------------

$runner->run('GET /v1/files/:id requires a token', function () use ($client, $fileRefId) {
    if ($fileRefId <= 0) {
        throw new TestSkipped('config.fileRefId not set');
    }
    $result = $client->get('/v1/files/' . $fileRefId, array('meta_data' => 'true'), false);
    assertStatus(401, $result, 'unauthenticated v1 files request');
});

$runner->run('GET /v1/files/:id returns file metadata', function () use ($client, $fileRefId) {
    if ($fileRefId <= 0) {
        throw new TestSkipped('config.fileRefId not set');
    }
    // id_type defaults to 'ref_id', matching config.fileRefId.
    $result = $client->get('/v1/files/' . $fileRefId, array('meta_data' => 'true'), true);
    if ($result['status'] === 401) {
        $message = $result['json']['message'] ?? $result['body'];
        throw new \RuntimeException(
            "v1 files request: got HTTP 401 with a valid token -- this usually means the API client " .
            "has no route permission for 'GET /v1/files/:id' (grant it via the plugin admin UI or " .
            "ClientConfiguration::createClient()), not that authentication itself failed. Server said: $message"
        );
    }
    assertStatus(200, $result, 'v1 files request');
    $json = assertJson($result, 'v1 files request');
    assertHasKeys($json, array('ext', 'name', 'size', 'type', 'dir', 'version', 'realpath'), 'v1 files response');
});

$runner->run('GET /v1/files/:id downloads file content', function () use ($client, $fileRefId) {
    if ($fileRefId <= 0) {
        throw new TestSkipped('config.fileRefId not set');
    }
    // Without meta_data, the route streams the raw file via sendFile() instead of JSON.
    $result = $client->get('/v1/files/' . $fileRefId, array(), true);
    if ($result['status'] === 401) {
        throw new TestSkipped('API client likely has no route permission for GET /v1/files/:id');
    }
    assertStatus(200, $result, 'v1 files download');
    if ($result['body'] === '') {
        throw new \RuntimeException('v1 files download: response body was empty');
    }
});

// ---------------------------------------------------------------------
// GET /v1/learning-module/:refId (learning_module_v1 extension)
// ---------------------------------------------------------------------

$runner->run('GET /v1/learning-module/:refId requires a token', function () use ($client, $learningModuleRefId) {
    if ($learningModuleRefId <= 0) {
        throw new TestSkipped('config.learningModuleRefId not set');
    }
    $result = $client->get("/v1/learning-module/$learningModuleRefId", array(), false);
    assertStatus(401, $result, 'unauthenticated learning-module request');
});

$runner->run('GET /v1/learning-module/:refId returns module data', function () use ($client, $learningModuleRefId) {
    if ($learningModuleRefId <= 0) {
        throw new TestSkipped('config.learningModuleRefId not set');
    }
    $result = $client->get("/v1/learning-module/$learningModuleRefId", array(), true);
    assertStatus(200, $result, 'learning-module request');
    $json = assertJson($result, 'learning-module request');
    assertHasKeys($json, array('startFile', 'zipFile', 'zipDirName', 'timestamp'), 'learning-module response');
});

// ---------------------------------------------------------------------
// Learnplace plugin routes (/v2/ilias-app/learnplace/*)
//
// Requires the separate "Learnplaces" plugin to be installed and active,
// and config.learnplaceObjectId to be set to an OBJECT-ID (not ref-ID) of
// a learnplace the test user can read -- see LearnplaceRoutes.php's
// checkReadAccessRight(), which resolves ref-IDs from the object-id itself.
// ---------------------------------------------------------------------

$runner->run('GET /v2/ilias-app/learnplace/:objectId requires a token', function () use ($client, $learnplaceObjectId) {
    if ($learnplaceObjectId <= 0) {
        throw new TestSkipped('config.learnplaceObjectId not set');
    }
    $result = $client->get("/v2/ilias-app/learnplace/$learnplaceObjectId", array(), false);
    assertStatus(401, $result, 'unauthenticated learnplace request');
});

$runner->run('GET /v2/ilias-app/learnplace/:objectId returns learnplace data', function () use ($client, $learnplaceObjectId) {
    if ($learnplaceObjectId <= 0) {
        throw new TestSkipped('config.learnplaceObjectId not set');
    }
    $result = $client->get("/v2/ilias-app/learnplace/$learnplaceObjectId", array(), true);
    assertStatus(200, $result, 'learnplace request');
    $json = assertJson($result, 'learnplace request');
    assertHasKeys($json, array('objectId', 'location', 'map'), 'learnplace response');
    assertHasKeys((array) $json['location'], array('latitude', 'longitude', 'elevation', 'radius'), 'learnplace location');
    assertHasKeys((array) $json['map'], array('visibility', 'zoomLevel'), 'learnplace map');
});

$runner->run('GET /v2/ilias-app/learnplace/:objectId/journal-entries returns visit journal', function () use ($client, $learnplaceObjectId) {
    if ($learnplaceObjectId <= 0) {
        throw new TestSkipped('config.learnplaceObjectId not set');
    }
    $result = $client->get("/v2/ilias-app/learnplace/$learnplaceObjectId/journal-entries", array(), true);
    assertStatus(200, $result, 'learnplace journal-entries request');
    assertJsonArray($result, 'learnplace journal-entries request', array('userId', 'timestamp'));
});

$runner->run('POST /v2/ilias-app/learnplace/:objectId/journal-entry requires a token', function () use ($client, $learnplaceObjectId) {
    if ($learnplaceObjectId <= 0) {
        throw new TestSkipped('config.learnplaceObjectId not set');
    }
    $result = $client->postJson("/v2/ilias-app/learnplace/$learnplaceObjectId/journal-entry", array('time' => time()), false);
    assertStatus(401, $result, 'unauthenticated learnplace journal-entry request');
});

$runner->run('POST /v2/ilias-app/learnplace/:objectId/journal-entry records a visit', function () use ($client, $learnplaceObjectId, $runDestructive) {
    if (!$runDestructive) {
        throw new TestSkipped('config.runDestructiveTests is false');
    }
    if ($learnplaceObjectId <= 0) {
        throw new TestSkipped('config.learnplaceObjectId not set');
    }
    // Must be JSON (not form-urlencoded): the route reads $app->request()->getBody(),
    // which Slim's ContentTypes middleware only parses into an array for JSON bodies.
    $result = $client->postJson("/v2/ilias-app/learnplace/$learnplaceObjectId/journal-entry", array('time' => time()), true);
    assertStatus(200, $result, 'learnplace journal-entry request');
    $json = assertJson($result, 'learnplace journal-entry request');
    assertHasKeys($json, array('message'), 'learnplace journal-entry response');
});

$runner->run('GET /v2/ilias-app/learnplace/:objectId/blocks returns block collection', function () use ($client, $learnplaceObjectId) {
    if ($learnplaceObjectId <= 0) {
        throw new TestSkipped('config.learnplaceObjectId not set');
    }
    $result = $client->get("/v2/ilias-app/learnplace/$learnplaceObjectId/blocks", array(), true);
    assertStatus(200, $result, 'learnplace blocks request');
    $json = assertJson($result, 'learnplace blocks request');
    assertHasKeys($json, array('text', 'picture', 'video', 'iliasLink', 'accordion'), 'learnplace blocks response');
});

exit($runner->summary());
