# Changelog
All notable changes to this project will be documented in this file.

## Versions
- [2020-02-27](#2020-02-27)
- [2020-01-31](#2020-01-31)

## Updating
When the Unreleased section becomes a new version, duplicate the Template to create a new Unreleased section.
```
## [Template]
### Added
- new features
### Changed
- changes in existing functionality
### Removed
- removed features
### Deprecated
- soon-to-be removed features
### Fixed
- bugfixes
### Security
- vulnerabilities or security notes
```

## Unreleased
- none

## 2020-02-27
### Added
- Added a changelog file
- Added an example of a post-login ZenKey authorize flow
- Wrote additional PHPDocs 
- Added additional comments to explain the ZenKey authorize flow
### Changed
- Updated copyright date across all files
- Added missing copyright headers across the project
- Refactored the codebase to enable the post-login authorization flow and improve readabiliity
  - Added AuthorizeFlowHandler.php to handle the ZenKey post-login authorization flow
  - Added SessionService.php to handle caching data in the session
  - Added ZenKeyOIDCService.php to abstract the ZenKey OIDC calls
- Linting tweaks
### Fixed
- Clear the session state when an error occurs so that the "state" parameter doesn't get reused

## 2020-01-31
### Added
- First public release