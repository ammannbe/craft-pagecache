# Page Cache Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/) and this project adheres to [Semantic Versioning](http://semver.org/).

## 2.3.2 - 2026-11-14

### Fixed

- The settings were not listed under Settings when admin changes are disallowed (read-only settings)

## 2.3.1 - 2026-11-14

### Fixed

- The settings tabs “Pages”, “Cache files” and “Generating pages” didn’t show their fields when switching tabs (e.g. the URL tables), also when admin changes are disallowed

## 2.3.0 - 2026-11-14

### Added

- Setting "Refresh mode" (`refreshMode`): clear the cache right away and generate it again (default), keep it until it is generated again, or clear it only
- Dependency tracking (`trackDependencies`, on by default): pages remember the elements and element queries they use and are refreshed when one of them changes, e.g. a listing when a new entry is published or a navigation entry changes
- Page Cache utility with dismissible notices and improvement suggestions (with documentation links), a live overview and actions (refresh, cache or delete all pages, refresh by URL or tag); optionally as its own page in the main navigation (`showInCpNav`)
- Optional “Refresh cache” item in the main navigation (`showRefreshInCpNav`)
- Template optimizations in the utility: template patterns and rendered pages that don't work well with a static cache (static CSRF tokens, `{% cache %}`, random content, `now`, pagination, `Cache-Control`, pending image transforms, missing eager loading), with fix and AI prompt
- Tools for Craft's MCP server (Craft 5.12+): `pagecache_status`, `pagecache_page_status`, `pagecache_refresh`, `pagecache_clear`, `pagecache_warm`
- Console command `pagecache/cache/refresh-expired`, site requests also check every 5 minutes for entries whose post or expiry date passed
- Basic auth (`generateUsername`, `generatePassword`), concurrency (`generateConcurrency`) and timeout (`generateTimeout`) settings for the queue job
- The sidebar shows when a page was cached and whether it is waiting in the queue
- Cached pages served by the plugin have an `X-Page-Cache: HIT` header

### Fixed

- Pages rendered for a visitor with a flash message (e.g. after submitting a form) were cached with the message for everyone, and such visitors could get a cached page without their message

### Changed

- Settings are grouped in tabs; settings from `config/pagecache.php` are locked and marked, `resources/config/pagecache.php` contains all settings
- Monochrome icon for the utility and the navigation
- The timestamp in the HTML comment of cached pages is marked as UTC (`Z`)
- Changed elements are collected during a request and their pages are queued once at the end (also in queue workers), instead of on every element save
- Pages are queued with a single database query and only once (unique URL hash), a waiting queue job is found without loading all jobs
- The queue job processes pages in batches with a constant number of concurrent requests and uses Craft's Guzzle client (`config/guzzle.php`)
- The queue job requests pages with a `pagecache-generate` parameter and a secret header, so neither the rewrite rules nor the plugin serve the old cache; the parameter is removed before rendering
- Pages queued again while the job processes them are processed once more, a second job doesn't wait for the running one, and long runs hand over to a new job after 10 minutes
- Pages the queue job can't generate (error response, timeout) are deleted instead of serving the old version
- Pages of deleted elements are refreshed if they showed the element
- The queue table stores element references instead of serialized elements; existing caches are generated again once to track their dependencies

## 2.2.1 - 2026-11-14

### Added

- nginx rewrite example (`resources/rewrite-rules/nginx.conf.example`)
- README: excluding pages, tags and how `Cache-Control` headers relate to the page cache

### Fixed

- Paginated URLs (e.g. `news/p2`) overwrote and were served the cache of the page itself (`news`)
- Changing the URI of an element (slug or parent) left the cache of the pages below it at the old URLs
- Clearing the whole cache (utility, console, globals) left files without database record (e.g. after a database import), which the rewrite rules kept serving
- Non-HTML responses (e.g. `sitemap.xml` templates, `{% header "Content-Type: …" %}`) were cached and served as HTML
- Pages that used a CSRF token while rendering (`csrfInput()` without `async`, `craft.app.request.csrfToken`) were cached, so forms failed for every other visitor; a warning is logged instead
- Uninstalling the plugin could delete a non-dedicated folder (e.g. `@webroot`) if it was set as cache folder
- Uninstalling the plugin did not drop the queue table
- The sidebar actions reloaded the page and discarded unsaved changes
- Uncached or excluded pages ran a `DELETE` query on every guest request

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
