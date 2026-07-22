# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

ILIAS REST Plugin is a customizable REST API plugin for the ILIAS Learning Management System (LMS). It provides a complete REST interface with OAuth2 authentication, API key management, and permission-based access control. This is a fork of the original REST plugin with modifications for the IACUBUS mobile application.

**Current Status**: Version 6.0.0, supporting ILIAS 9 and 10, PHP 8.2+

## Architecture

### High-Level Structure

The plugin follows a layered architecture with clear separation of concerns:

1. **Entry Point** (`api.php`): Initializes the environment, loads Composer autoloader, and instantiates the RESTController application
2. **Core Application** (`RESTController/RESTController.php`): Extends Slim framework with PSR-0 autoloading for RESTController and extension namespaces
3. **ILIAS Integration** (`classes/`): Plugin class and UI hooks for integration with ILIAS's plugin system
4. **Data Layer** (`RESTController/database/`): Database abstraction for clients, authorization, permissions, access logs, configuration, and refresh tokens
5. **Framework** (`RESTController/Slim/`): Bundled Slim framework microframework with HTTP request handling, routing, middleware, and response formatting
6. **Extensions** (`RESTController/extensions/`): Versioned API endpoints (routes and models) for specific features (ebook, ilias_app, files, learning_module)
7. **Core Services** (`RESTController/core/`): Base API services for clients and OAuth2 authentication (oauth2_v2, clients_v1)

### Extension Structure

Each extension (e.g., `ilias_app_v3`, `ebook_v2`) follows a consistent pattern:

```
extensions/<name>_v<version>/
  ├── routes/              # Route definitions (e.g., ILIASAppRoutes.php, EBookRoutes.php)
  ├── models/              # Data models and business logic
  ├── services/            # Utility services (e.g., JsonSchemaValidation, FileHashing)
  ├── schemas/             # JSON schemas for request validation
  └── README.md            # Extension documentation
```

Routes use Slim's group routing to define versioned API endpoints (e.g., `/v2/ebook`, `/v3/ilias-app`). Each route file checks for plugin availability and defines HTTP handlers for CRUD operations.

### Autoloading Convention

The custom PSR-0 autoloader in `RESTController::autoload()` automatically inserts `models` into the namespace path for core and extension classes:
- `\RESTController\extensions\eBook\v2\MyClass` → loads `RESTController/extensions/ebook_v2/models/MyClass.php`
- `\RESTController\core\clients_v1\MyClass` → loads `RESTController/core/clients_v1/models/MyClass.php`
- Other namespaces load directly without the `models` insertion

### OAuth2 and Authentication

The plugin implements full OAuth2 support (RFC6749) with:
- Authorization Code grant
- Implicit grant
- Resource Owner Password Credentials grant
- Client Credentials grant

Managed via `RESTController/core/oauth2_v2/` and persisted in the database via `RESTController/database/RESTauthorization.php`, `RESTController/database/RESTaccess.php`, and `RESTController/database/RESTrefresh.php`.

## Database

The plugin uses ILIAS's database service. Key tables (prefixed with `ui_uihk_rest_`):
- `ui_uihk_rest_client` - REST API clients (API keys, redirect URIs)
- `ui_uihk_rest_perm` - Resource permissions for clients
- `ui_uihk_rest_config` - Plugin configuration
- `ui_uihk_rest_authcode` - OAuth2 authorization codes
- `ui_uihk_rest_access` - OAuth2 access tokens
- `ui_uihk_rest_token` - Refresh tokens
- `ui_uihk_rest_refresh` - Token refresh log
- Tables for specific extensions (e.g., `ui_uihk_xebk_hash_cache` for ebook file hashing)

Schema defined in `sql/dbupdate.php`.

## Key Files and Their Roles

| File | Purpose |
|------|---------|
| `plugin.php` | Plugin metadata (version, ILIAS compatibility, maintainer) |
| `api.php` | REST API entry point |
| `classes/class.ilRESTPlugin.php` | ILIAS plugin interface; handles uninstall cleanup |
| `classes/class.ilRESTUIHookGUI.php` | UI hook for admin interface |
| `classes/class.ilRESTConfigGUI.php` | Configuration UI for the plugin |
| `RESTController/RESTController.php` | Main Slim application with routing and custom autoloading |
| `RESTController/database/*.php` | Database access layer for each resource type |
| `RESTController/extensions/ClientConfiguration.php` | Static utility for programmatic client/API key creation |
| `composer.json` | Dependency management (json-schema validation library) |

## Development Workflow

### Prerequisites
- PHP 8.2+ (8.3 also supported on ILIAS 10)
- Composer (for dependency installation)
- ILIAS 9 or 10, with write access to the plugin directory
- Target ILIAS installation at `/var/www/ilias/` (or update paths in code).
  On ILIAS 10 this plugin lives under `<ILIAS root>/public/Customizing/global/plugins/...`;
  the ILIAS root itself (containing `ilias.ini.php`, `vendor/`, `components/`)
  is one directory above `public/`.

### Common Commands

**Install/update dependencies:**
```bash
composer install
```

**Verify JSON schemas (for ebook and other extensions using schema validation):**
Schema files are located in `RESTController/extensions/<name>_v<version>/schemas/` and validated via `JsonSchemaValidation` service.

**Check available routes:**
```bash
curl -X GET https://<your_domain>/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/REST/api.php/v2/util/routes
```

**Plugin activation:**
Access ILIAS Administration > Plugins, locate the REST plugin, and use the action menu to update and activate.

### Adding a New Endpoint

1. **Choose or create an extension directory:**
   - If adding to existing service (e.g., ebook), navigate to `RESTController/extensions/ebook_v<N>/`
   - To create a new service: `mkdir -p RESTController/extensions/<service>_v1/{routes,models,services,schemas}`

2. **Define routes in `routes/<ServiceName>Routes.php`:**
   ```php
   use RESTController\RESTController;
   
   /** @var $app RESTController */
   $app->group('/v1/<service>', function () use ($app) {
       $app->get('/:id', function ($id) {
           // Handle GET /v1/<service>/:id
       });
   });
   ```

3. **Create data models in `models/<ModelName>.php`:**
   - Use namespace `RESTController\extensions\<ServiceName>\v1\models`
   - Include constructor and getter/setter methods

4. **For request validation, add JSON schema in `schemas/<SchemaName>.json`:**
   - Reference from route validation: `JsonSchemaValidation::validateRequest($request)`

5. **Routes are auto-loaded** via the plugin's require system (no registration needed; the framework discovers them)

### Testing an Endpoint

Use `curl` or a REST client (Postman, Insomnia):
```bash
# Get all routes
curl -X GET https://your-domain/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/REST/api.php/v2/util/routes

# Test a versioned endpoint
curl -X POST https://your-domain/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/REST/api.php/v2/ebook/files \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"data": "value"}'
```

OAuth2 clients require setting up API keys and redirect URIs via the plugin configuration UI or using `ClientConfiguration::createClient()`.

## ILIAS 9 vs ILIAS 10: Directory Layout and Core API Differences

ILIAS 10 restructured the installation on disk and changed a few core APIs this
plugin depends on. The plugin supports both versions from one code-base via a
compatibility layer in `RESTController/libs/RESTilias.php` — read this before
touching any code that reaches into ILIAS core classes.

### Directory layout change
- **ILIAS 9 and earlier**: the ILIAS root (containing `ilias.ini.php`, `Services/`,
  `Modules/`, `Customizing/`) is also the webserver's document root.
- **ILIAS 10**: introduced a `public/` webroot. `Customizing/` (and thus this
  plugin) lives under `public/Customizing/global/plugins/...`, but `ilias.ini.php`,
  `vendor/`, and all core classes live in `components/ILIAS/<Name>/...` **one
  directory above** `public/`. The `Services/`/`Modules/` type-segment was
  dropped, and a few components were also renamed (e.g. `Object` → `ILIASObject`).

Because of this, **never** write a raw `require_once('Services/...')` or
`require_once('Modules/...')` for an ILIAS core class, and never assume the
current working directory is the ILIAS root. Instead:
- Use `RESTilias::getIliasRoot()` to get the absolute ILIAS root (found by
  walking up from `RESTilias.php`'s own location until `ilias.ini.php` is
  found — this also loads ILIAS's own Composer autoloader once, which ILIAS 10
  needs even to load `ilInitialisation.php` itself).
- Use `RESTilias::includeIlias('Services/Xxx/classes/class.ilXxx.php')` (or
  `Modules/...`) to require an ILIAS core class file; it transparently
  resolves either layout and throws a clear `RuntimeException` if the class
  truly can't be found in either.
- The plugin's own self-referencing includes were previously hardcoded as
  `./Customizing/global/plugins/.../REST/...`. This only works when the
  current working directory happens to be the directory directly containing
  `Customizing/` — true for normal HTTP requests (CWD is ILIAS's `public/`
  webroot on ILIAS 10, via `api.php`'s `chdir()`), but **not** true under
  ILIAS's own CLI/setup tooling (eg. `composer du` / "Building Static
  Artifacts"), where CWD is the ILIAS root itself — one level above `public/`
  on ILIAS 10, so `./Customizing/...` doesn't exist there. All such
  self-references now use `__DIR__`-relative paths instead, which don't depend
  on CWD at all. Follow this pattern for any new file that requires a sibling
  file within this plugin — never hardcode a `Customizing/global/plugins/...`
  path relative to CWD. For reaching into a *different* plugin (not this one),
  use `RESTilias::includeCustomizingPlugin()`.

### Core API changes in ILIAS 10
- `ilPluginAdmin::getPluginObject($ctype, $cname, $slot, $pname)` and the
  `IL_COMP_SERVICE` constant were both **removed**. Use
  `$DIC['component.factory']->getPlugin($id)` instead (`RESTilias::getPlugin()`
  already does this, with a fallback to the legacy API for ILIAS 9).
- `ilPlugin::getDirectory()` now returns an **absolute filesystem path**
  (it used to be relative to the ILIAS root). Use the new
  `ilPlugin::getRelativeDirectory()` for anything that builds a URL (webroot-relative).
  `RESTilias::getPluginRelativeDirectory($plugin)` wraps this with a
  `method_exists()` fallback for ILIAS 9.
- `ilMimeTypeUtil` was **removed**; its drop-in replacement is the namespaced
  `ILIAS\FileUpload\MimeType` (same `getMimeType()` signature). The `files_v1`
  models pick whichever is available via `class_exists()`.
- `$DIC['component.repository']->getPluginById($id)` now returns an
  `ilPluginInfo`, not an `ilPlugin` — it has no `getDirectory()` method at all
  (only `getPath()`, absolute). Prefer `RESTilias::getPlugin()` +
  `RESTilias::getPluginRelativeDirectory()` over calling
  `component.repository` directly when you need a plugin's own directory.

## Important Notes for Modifications

### Backwards Compatibility
- Do not modify existing route paths (vN means version N and should be immutable)
- Create new extensions or new versions (v(N+1)) for breaking changes
- Multiple API versions should coexist

### PHP 8.2 Compliance
- Recent changes updated deprecated function usage (e.g., `finfo_file()` instead of deprecated file extensions methods)
- Avoid magic quotes detection patterns that trigger fatal errors in PHP 8.2
- Use type hints for function parameters and return types where practical

### Extension Deactivation Checks
Extensions that depend on optional ILIAS plugins must check plugin availability before registering routes:
```php
global $DIC;
if (!$DIC['component.repository']->hasActivatedPlugin('xebk')) {
    return;  // Skip route registration if plugin not active
}
```

### SSL Client Certificates
The plugin supports OAuth2 with SSL client certificates. Always use defensive checks to prevent PHP notices when `$_SERVER['SSL_CLIENT_*']` values are absent (common in non-production environments).

### Database Cleanup on Uninstall
Update `class.ilRESTPlugin::beforeUninstall()` when adding new tables to ensure proper cleanup during plugin removal.

## References

- **ILIAS Documentation**: http://www.ilias.de
- **REST Plugin Wiki** (original project): https://github.com/hrz-unimr/Ilias.RESTPlugin/wiki
- **OAuth2 RFC**: https://tools.ietf.org/html/rfc6749
- **Slim Framework**: The bundled version is included in `RESTController/Slim/`
- **JSON Schema Validation**: Uses `swaggest/json-schema` library for request validation
