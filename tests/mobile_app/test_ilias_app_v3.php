<?php
/**
 * Endpoint tests for the IACUBUS / ILIAS Pegasus mobile app API: the
 * current /v3/ilias-app/* routes (RESTController/extensions/ilias_app_v3),
 * plus GET /v1/files/:id (RESTController/extensions/files_v1), which the
 * app also uses for file downloads.
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

exit($runner->summary());
