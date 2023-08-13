# Page Cache Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/) and this project adheres to [Semantic Versioning](http://semver.org/).

## 0.2.1 - 2023-08-13

### Added

- added console command

## 0.2.0 - 2023-08-13

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

## 0.1.1 - 2023-06-03

### Added

- added clear caches utility

## 0.1.0 - 2023-05-13

### Fixed

- Bump new release 0.1.0 (Craft CMS 3.x)

## 0.0.1 - 2023-05-13

### Fixed

- cleanup pagecache.php
- prevent loading jquery when using PageCacheVariable
- bump to release 0.0.1
- Updated README.md

## 0.0.1-beta.11 - 2023-03-18

### Fixed

- Fixed typo in `PageCacheService::deleteAllPageCaches()`

## 0.0.1-beta.10 - 2023-03-18

### Fixed

- Refactor recreate/delete cache when globals change

## 0.0.1-beta.9 - 2023-03-18

### Added

- Add possibility to recreate cache if global is saved
- Add twig variable to get dynamic CSRF token input

### Fixed

- Fixed typo in settings.twig

## 0.0.1-beta.8 - 2023-03-01

### Fixed

- Don't cache pages with active assets transforms
- Remove \_\_home\_\_ from URIs

## 0.0.1-beta.7 - 2023-02-27

### Fixed

- Fixed exception of $owner->owner

## 0.0.1-beta.6 - 2023-02-25

### Fixed

- Check sub-owner elements on relations

## 0.0.1-beta.5 - 2023-02-02

### Fixed

- Fixed type error while saving settings: Settings::$excludedUrls

## 0.0.1-beta.4 - 2023-02-01

### Fixed

- Fixed string replace in PageCacheService@parsePath()

## 0.0.1-beta.3 - 2023-02-01

### Fixed

- Settings validation rules
- PHP 7 compatibility

## 0.0.1-beta.2 - 2023-02-01

### Added

- Allow php 8 in composer.json
- Changed .htaccess rewrite rules location
- Added images for plugin store

## 0.0.1-beta.1 - 2023-01-31

### Added

- Going live with beta

## 0.0.1 - 2022-12-13

### Added

- Project setup
- Initial beta release
