# Page Cache Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/) and this project adheres to [Semantic Versioning](http://semver.org/).

## 2.2.0 - 2026-10-17

### Added

- Setting "Cache pages with query strings" (`cacheQueryStrings`), off by default
- The queue job has a timeout per page request and is retried up to 3 times

### Changed

- Pages with query strings are no longer cached by default, every new query string created a new cache file
- Cached pages are only served to guests, not to logged-in users
- Previews and requests with a token are never cached or served from the cache
- Saving an element with a changed URI (slug or parent) deletes the cache of the old URI
- Saving a nested element (e.g. a Matrix or Neo block) refreshes the page of its owner
- Bulk resaves no longer push pages into the cache queue
- Cache files are written atomically, folders are created with `0755`
- Fewer database queries when pushing pages into the queue
- The rewrite examples skip the cache for logged-in users (`CraftSessionId` cookie)

### Fixed

- Query strings like `?a=/../../` could write cache files outside of the cache folder
- A failed cache job blocked all later cache refreshes
- `pagecache/cache/refresh` and `pagecache/cache/delete` without `--tags` did nothing
- The cache job did not wait for all page requests, so some pages were not cached again
- Elements of other sites than the primary site were not found by the cache job
- Refreshing the cache crashed if a cached element was deleted, and duplicate elements were queued
- Excluded/included URL patterns with `/` (e.g. `news/archive`) caused a PHP warning and never matched
- The sidebar actions accepted GET requests, requests outside of the control panel and users without permission to save the element
- The sidebar actions did not work in slideouts
- The cache folder was not created on install
- The brotli rewrite example used the wrong encoding name (`brotli` instead of `br`)
- PHP 8 deprecation warnings for optional parameters before required ones
- Console commands in the README

## 2.1.5 - 2026-10-17

### Fixed

- Saving an entry with nested Neo or Matrix blocks hung until the request timed out, so the entry was not saved and the cache was not refreshed
- Saving drafts and revisions no longer triggers a cache refresh
- Saving a new element could delete the cache of an unrelated entry
- Related elements were resolved from the wrong element when collecting Neo and Super Table owners

## 2.1.4 - 2025-09-29

### Fixed

- Fixed a logged warning
- Saved entries kept being a draft

## 2.1.3 - 2025-07-20

### Added

- Command to warm up all entries

## 2.1.2 - 2025-07-08

### Fixed

- Fixed error on initial cache folder creation if the folder already exists
- Fixed Install migration

## 2.1.1 - 2025-05-02

### Changed

- Only Render meta tags once

## 2.1.0 - 2025-05-02

### Changed

- improved plugin settings template
- removed HTML optimization
- refactor code
- added sidebar html
- updated pagecache action buttons
- added page cache html footer
- added "exclude" variable
- added "tags" variable
- added delete cache command

## 2.0.1 - 2025-04-14

### Fixed

- Fixed implicitly nullable parameters deprecation warning
- Decode URL before checking against include/exclude list

## 2.0.0 - 2025-02-10

### Changed

- Craft CMS 5.x ready

## 1.4.1 - 2025-05-02

### Changed

- Only Render meta tags once

## 1.4.0 - 2025-05-02

### Changed

- improved plugin settings template
- removed HTML optimization
- refactor code
- added sidebar html
- updated pagecache action buttons
- added page cache html footer
- added "exclude" variable
- added "tags" variable
- added delete cache command

## 1.3.1 - 2025-04-14

### Fixed

- Fixed implicitly nullable parameters deprecation warning
- Decode URL before checking against include/exclude list

## 1.3.0 - 2025-02-10

### Changed

- Deprecated `csrfInput()` variable
- Added `includeUrls` config option
- Don't cache pages when the current user is logged in

### Fixed

- Drastically improved DB queries

## 1.2.3 - 2023-08-17

### Fixed

- fixed migrations run on install
- log response errors

## 1.2.2 - 2023-08-13

### Fixed

- added uid to PageCacheQueueRecord

## 1.2.1 - 2023-08-13

### Added

- added console command

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
