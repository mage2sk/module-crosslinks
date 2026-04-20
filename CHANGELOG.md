# Changelog

All notable changes to this extension are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.1]

### Fixed
- ACL XML duplicate resource-id error that prevented the admin config
  page from loading (`Panth_X::config` was declared under both
  `Panth_Core::panth_extensions` and `Magento_Config::config`). The
  redundant declaration under `Panth_Core::panth_extensions` has been
  removed; the menu link continues to gate on the real system-config
  resource.

## [1.0.0] — Initial release

### Added

- **Extracted from `Panth_AdvancedSEO` 1.0.5+.** This module ships the
  automatic internal-crosslink feature as a standalone package so a
  storefront can get keyword-to-anchor replacement without installing the
  full Advanced SEO suite.
- Admin config section `Panth Infotech → Crosslinks` with four settings:
  `crosslinks_enabled`, `max_links_per_page`, `excluded_tags`,
  `crosslink_time_activation`.
- Admin grid at `panth_crosslinks/crosslink/index` with full CRUD,
  mass enable / disable / delete, filterable columns, and store-view
  scoping.
- Add / Edit form with reference type switcher (Custom URL, Product by
  SKU, Category by ID) — non-URL references are resolved at render time
  via `url_rewrite` and cached in-request.
- `Panth\Crosslinks\Model\Crosslink\ReplacementService` — HTML-aware
  replacement engine that honours excluded tags (h1-h6, a, button,
  script, style by default), enforces per-keyword and per-page link
  limits, and strips dangerous URL schemes (`javascript:`, `data:`,
  `vbscript:`, `file:`) at render time as defense-in-depth.
- DI plugins on `Magento\Catalog\Helper\Output` (product and category
  description attributes) and `Magento\Cms\Model\Template\FilterProvider`
  (CMS page and block filters) to inject crosslinks into rendered
  content.
- Time-based activation — when the admin toggle is enabled, each rule's
  `active_from` / `active_to` timestamps gate whether the rule is
  eligible for a given request.

### Database

- **Table name kept as `panth_seo_crosslink`** (rather than renaming to
  `panth_crosslink`). This is deliberate: existing rows created while
  `Panth_AdvancedSEO` owned the feature remain in place and keep working
  the moment this module is enabled and `Panth_AdvancedSEO`'s crosslink
  code is removed. No data migration step is required during the split.

### Security

- Admin controllers extend `Magento\Backend\App\Action` and declare
  per-action `ADMIN_RESOURCE` constants; `_isAllowed()` enforces ACL for
  every CRUD path.
- `Save` is `HttpPostActionInterface` only and re-validates the form key
  explicitly in addition to the parent class check. All POST fields are
  read through `RequestInterface::getPostValue()`, then cast or trimmed
  before being written.
- Every DB write uses `$adapter->update() / insert() / delete()` with
  array-driven `WHERE` clauses — no string concatenation.
- Output escaping: anchors built by `ReplacementService::buildAnchor()`
  run every user-configured field through `htmlspecialchars(ENT_QUOTES |
  ENT_HTML5)`.
