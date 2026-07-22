# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/)

## [6.0.0]
### Added
- ILIAS 10 support, while keeping ILIAS 9 working from the same code-base
### Changed
- `RESTilias` now resolves the ILIAS installation root by walking up from its own
  file location (`getIliasRoot()`) instead of assuming the current working
  directory is the ILIAS root. This is required because ILIAS 10 introduced a
  `public/` webroot that is no longer the same directory as the ILIAS
  installation root (which holds `ilias.ini.php`, `vendor/`, `components/`).
- All `require_once`/`include_once` calls into ILIAS core classes
  (`Services/...`, `Modules/...`) now go through a new `RESTilias::includeIlias()`
  helper, which transparently resolves either the ILIAS 9 layout
  (`Services/<Name>/...`, `Modules/<Name>/...` at the ILIAS root) or the ILIAS 10
  layout (`components/ILIAS/<Name>/...`, type-segment dropped, some components
  renamed, e.g. `Object` -> `ILIASObject`).
- All of the plugin's own self-referencing `require_once`/`include_once` calls
  (previously hardcoded as `./Customizing/global/plugins/.../REST/...`) now use
  `__DIR__`-relative paths instead. These only worked before when the current
  working directory happened to be the directory directly containing
  `Customizing/` (true for normal HTTP requests, where CWD is ILIAS's
  `public/` webroot on ILIAS 10). They broke under ILIAS's CLI/setup tooling
  (eg. `composer du` / "Building Static Artifacts"), where CWD is the ILIAS
  root itself, one level above `public/` on ILIAS 10 -- `./Customizing/...`
  doesn't exist there, only `./public/Customizing/...` does. `__DIR__`-relative
  paths are immune to this since they don't depend on CWD at all. Added
  `RESTilias::includeCustomizingPlugin()` for the one remaining case that
  reaches into a *different* plugin (Learnplaces) rather than this plugin's
  own files, which resolves the `Customizing/global/plugins/` root the same
  version-aware way.
- `RESTilias::getPlugin()` now uses the `component.factory` DIC service
  (`ilComponentFactory::getPlugin()`) on ILIAS 10, since `ilPluginAdmin::getPluginObject()`
  and the `IL_COMP_SERVICE` constant it required were both removed. Falls back
  to the legacy API on ILIAS 9.
- Added `RESTilias::getPluginRelativeDirectory()` to handle ILIAS 10's
  `ilPlugin::getDirectory()` now returning an absolute filesystem path instead
  of a webroot-relative one; uses the new `getRelativeDirectory()` where
  available (ILIAS 10), otherwise falls back to the old relative behavior
  (ILIAS 9). Used by the OAuth2 authorization page and the plugin
  configuration screen to build correct URLs.
- `files_v1` models now use `ILIAS\FileUpload\MimeType::getMimeType()` when the
  legacy `ilMimeTypeUtil` class (removed in ILIAS 10) is not available.
### Fixed
- Fixed a pre-existing syntax error (`new\ ilWorkspaceTree` instead of
  `new \ilWorkspaceTree`) in `PersonalFileSpaceModel::deleteFromMyFileSpace()`.
- Fixed `GET /v3/ilias-app/files/:refId` (`ILIASAppModel::getFileData()`)
  throwing a 500 ("Trying to access array offset on value of type null")
  whenever a file has no learning-progress record yet for the requesting
  user -- the common case for any file the user hasn't opened before. Found
  via the new `tests/mobile_app` endpoint tests running against a real
  ILIAS 10 instance.
- Fixed the same latent bug in `GET /v3/ilias-app/theme`
  (`ILIASAppModel::getThemeData()`): would 500 if the `ui_uihk_pegasus_theme`
  settings row doesn't exist yet (eg. a fresh install where no admin has
  configured the theme), now falls back to sensible defaults.
- Fixed `RESTilias::initILIAS()` not registering the ILIAS UI framework's
  `ui.factory` DIC service, causing a 500 ("Identifier 'ui.factory' is not
  defined") on any route that ends up calling `ilObjFile::sendFile()`
  (eg. `GET /v1/files/:id` without `meta_data`) or otherwise updates an
  object whose `beforeUpdate()` hook touches metadata/copyright rendering.
  ILIAS marks the REST context as `hasHTML() === false`, so ILIAS's own
  bootstrap skips `initUIFramework()` -- but ILIAS 10's file-update code
  path needs `ui.factory` regardless. Registered explicitly now (cheap: it
  only registers lazy factory closures, nothing is eagerly instantiated).
  Found via the new `tests/mobile_app` endpoint tests running against a
  real ILIAS 10 instance.

## [5.0.0]
### Added
- ILIAS 9 support
- PHP 8.2 support
### Removed
- Support for ILIAS versions older than 9
- PHP 7 compatibility code paths
### Fixed
- Guard legacy magic quotes detection to prevent PHP 8.2 fatal errors
- Updated `ilLink` include paths for the new PermanentLink service layout in ILIAS 9
- OAuth2 certificate handling now tolerates missing `$_SERVER['SSL_CLIENT_*']` values to avoid PHP notices when no client cert is supplied

## [4.0.0] 
### Added
- ILIAS 8 support
### Removed
- ILIAS 7 and 6 support

## [3.0.1] - 2022-03-04
### Fixed
- Ensure the requested SSO Tokens are valid and not already expired

## [3.0.0] - 2021-09-16
### Added
- ILIAS 7 Support
### Removed
- App unrelated extension routes.

## [2.0.2] - 2020-11-18
### Fixed
- The learning progress of the parent object gets updated now

## [2.0.1] - 2020-09-30
### Fixed
- The learning module metadata route no longer returns invalid entry point paths in certain scenarios

## [2.0.0] - 2020-09-29
### Fixed
- Fix issues with user session on ILIAS 6

### Deprecated
- PHP 5.x support
- ILIAS 5.3 support

### Removed
- ILIAS 5.2 support

## [1.10.0] - 2020-08-18
### Added
- New object route which allows to fetch a single object by refId.

### Fixed
- Children of objects which contains a learning sequence no longer errors.
- Learning module ZIPs now have the correct size.
- Plugin max ILIAS version constraint

## [1.9.1] - 2020-05-26
### Added
- configuration of REST clients without having to use the API
- support for learning modules (htlm, sahs)

### Fixed
- eBook note sync now accepts strings with newline characters.

## [1.9.0] - 2020-04-02
### Added
- eBook cover route

## [1.8.4] - 2019-12-05
### Added
- ILIASAPP-645 (version 1) farbwahl
- route v3 for theme colors
- setting theme colors in the configuration page of the plugin
- route v1 'routesAccess' for testing purposes

## [1.8.3] - 2019-11-22
### Added
- RP001 & RP002: Lernforttschritte abholen & hochschreiben
- route v3 for files
- checking access-rights for files

### Fixed
- array definition in RESTLib.php

# Template
## [v.v.v] - yyyy-mm-dd
### Added
- ...
### Changed
- ...
### Fixed
- ...
### Removed
- ...
