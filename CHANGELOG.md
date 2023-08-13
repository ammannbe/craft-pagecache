# Page Cache Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/) and this project adheres to [Semantic Versioning](http://semver.org/).

## 1.2.0 - 2023-08-13

### Added

- added rewrite rules in README.md
- added batch job handling

### Fixed

- fixed excludedUrls on all sites
- disabled ElementAction buttons if plugin is disabled

### Changed

- fixed async requests in PageCacheTask
- disable optimize HTML by default
- decode (special characters) in path

## 1.1.0 - 2023-06-03

### Added

- added clear caches utility

## 1.0.0 - 2023-05-13

### Fixed

- cleanup `pagecache.php`
- prevent loading jquery when using `PageCacheVariable`

## 1.0.0-beta.8 - 2023-03-18

### Fixed

- Fixed typo in `PageCacheService::deleteAllPageCaches()`

## 1.0.0-beta.7 - 2023-03-01

### Fixed

- Refactor recreate/delete cache when globals change

## 1.0.0-beta.6 - 2023-03-018

### Fixed

- Don't cache pages with active assets transforms
- Remove \_\_home\_\_ from URIs
- Refactor recreate/delete cache when globals change

## 1.0.0-beta.5 - 2023-02-27

### Fixed

- Fixed exception of $owner->owner

## 1.0.0-beta.4 - 2023-02-25

### Fixed

- Check sub-owner elements on relations

## 1.0.0-beta.3 - 2023-02-01

### Fixed

- Fixed string replace in PageCacheService@parsePath()

## 1.0.0-beta.2 - 2023-02-01

### Added

- Changed .htaccess rewrite rules location
- Added images for plugin store

### Fixed

- Settings validation rules
- PHP 7 compatibility

## 1.0.0-beta.1 - 2023-01-31

### Added

- Going live with beta

## 0.0.1 - 2022-12-13

### Added

- Project setup
- Initial beta release
