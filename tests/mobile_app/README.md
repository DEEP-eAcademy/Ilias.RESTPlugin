# Mobile app (IACUBUS / ILIAS Pegasus) endpoint tests

Standalone PHP scripts that verify the endpoints the current mobile app
actually calls work against a real, running ILIAS instance. No PHPUnit or
Composer dev-dependencies required, just the `php` CLI with the `curl`
extension.

## Covered endpoints

| Route | Method | Test coverage |
|---|---|---|
| `/v2/oauth2/token` | POST | `password` grant (initial login); `refresh_token` grant (skipped if the client isn't configured for `refresh_resource_owner`) |
| `/v2/ilias-app/auth-token` | GET | requires a token; returns a short-lived login token |
| `/v2/ilias-app/desktop` | GET | requires a token; returns personal desktop items |
| `/v2/ilias-app/objects/:refId` | GET | requires a token; returns child objects (skipped if no parent ref-ID configured) |
| `/v2/ilias-app/news` | GET | requires a token; returns news items |
| `/v2/ilias-app/learnplace/:objectId` | GET | requires a token; returns learnplace location/map data (skipped if no learnplace object-ID configured) |
| `/v2/ilias-app/learnplace/:objectId/journal-entries` | GET | returns the visit journal |
| `/v2/ilias-app/learnplace/:objectId/journal-entry` | POST | requires a token; records a visit (opt-in, see below) |
| `/v2/ilias-app/learnplace/:objectId/blocks` | GET | returns the block collection (text/picture/video/link/accordion) |
| `/v3/ilias-app/theme` | GET | requires a token; returns theme keys |
| `/v3/ilias-app/object/:refId` | GET | requires a token; returns object metadata (skipped if no ref-ID configured) |
| `/v3/ilias-app/files/:refId` | GET | requires a token; returns file metadata (skipped if no ref-ID configured) |
| `/v3/ilias-app/files/:refId/learning-progress-to-done` | POST | requires a token; marks progress done (opt-in, see below) |
| `/v1/files/:id` | GET | requires a token; returns file metadata (`meta_data=true`); downloads file content (skipped if no ref-ID configured) |
| `/v1/learning-module/:refId` | GET | requires a token; returns start file / zip info (skipped if no ref-ID configured) |

**Not covered:** the initial `GET`/`POST login.php?target=ilias_app_oauth2&client_id=...`
login page. That's an ILIAS core HTML screen, not a JSON route served by this
plugin's `api.php`, so it's out of scope for this curl-based JSON client.

`/v1/files/:id` (in `files_v1`, not `ilias_app_v3`) is gated by
`RESTAuth::PERMISSION` rather than `RESTAuth::TOKEN` -- it also checks that
the configured API client has an explicit route permission for
`GET /v1/files/:id` (`ui_uihk_rest_perm`), separately from whether the token
itself is valid. Both cases come back as plain HTTP 401, so if the "returns
file metadata" test fails with 401, check the failure message: it will tell
you to grant the route permission rather than assume auth is broken.

The `/v2/ilias-app/learnplace/*` routes require the separate "Learnplaces"
plugin to be installed and active; `learnplaceObjectId` is an **object-ID**,
not a ref-ID (see `LearnplaceRoutes.php`'s `checkReadAccessRight()`, which
resolves ref-IDs from the object-id itself).

## Setup

```bash
cp config.example.php config.php
```

Edit `config.php` (gitignored -- never commit real credentials):

- `baseUrl` -- this plugin's `api.php`, e.g.
  `https://your-domain/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/REST/api.php`
- `apiKey` / `apiSecret` -- a REST API client with `ilias_app_v3` permissions
  and a route permission for `GET /v1/files/:id`
  (create one via the plugin's admin UI, or
  `RESTController\extensions\ClientConfiguration::createClient()`)
- `username` / `password` -- an ILIAS user the mobile app would log in as.
  This user's own permissions determine what `object`/`files` can see.
- `objectRefId` -- ref-ID of any repository object the test user can read.
  Leave as `0` to skip the object test.
- `fileRefId` -- ref-ID of an actual File object (`ilObjFile`) the test user
  can read. Used by both the `/v3/ilias-app/files/*` and `/v1/files/:id`
  tests. Leave as `0` to skip all of them.
- `parentRefId` -- ref-ID of a container object (course, folder, category,
  ...) the test user can read. Used by the `/v2/ilias-app/objects/:refId`
  test. Leave as `0` to skip.
- `learningModuleRefId` -- ref-ID of a supported learning module (HTLM or
  SCORM/SAHS) the test user can read. Used by the
  `/v1/learning-module/:refId` test. Leave as `0` to skip.
- `learnplaceObjectId` -- object-ID (not ref-ID) of a Learnplace the test
  user can read. Only relevant if the Learnplaces plugin is installed and
  active. Leave as `0` to skip.
- `runDestructiveTests` -- set to `true` to also run the
  `learning-progress-to-done` and learnplace `journal-entry` tests. This
  **writes** data (marks the configured file read/done, records a
  learnplace visit), so it's off by default -- only enable it against
  disposable test objects/users.

## Run

```bash
php test_ilias_app_v3.php
```

Exit code is `0` if every executed test passed, `1` otherwise. Tests that
need a ref-ID you didn't configure are reported as `SKIP`, not `FAIL`.

## Notes

- Authentication uses the Resource Owner Password Credentials grant
  (`grant_type=password`), the same flow the mobile app itself uses.
- These are live integration tests against real ILIAS data -- they're not
  hermetic and results depend on the configured user's permissions and the
  state of the configured ref-IDs.
- `RestApiTestClient.php` is a tiny, dependency-free curl wrapper; reuse it
  if you add coverage for other endpoint groups (`ilias_app_v2`, `ebook_v*`,
  etc.) under this `tests/` directory.
