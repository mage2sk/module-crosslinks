# Changelog

All notable changes to this extension are documented here. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.1.5] - 2026-10-04

### Fixed
- The Store View field on the rule form now offers All Store Views, and rules saved for all store views keep that scope. Before, they opened as Default Store View and saving moved them to that store only.
- Active From and Active To dates picked in the admin are now stored as real dates. Before, saving a rule with dates stored an empty date, so a rule with time-based activation never showed again. The grid now shows these dates without a timezone shift, and Active To can no longer be earlier than Active From.
- When a save is rejected, the form keeps the values you entered and reopens as a new rule when the rule was new. Before, the fields were cleared and Nofollow switched to Yes.
- Saving, deleting, enabling or disabling rules now marks the Page Cache and Blocks HTML output caches as invalidated, so storefront pages stop showing outdated links after a cache refresh.
