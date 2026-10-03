# Changelog

All notable changes to this extension are documented here. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.1.4] - 2026-10-03

### Fixed
- Keywords that start or end with a symbol, such as C++ or .NET, are now linked. Matching still requires the keyword to stand alone: it is not linked when a letter, digit or underscore touches it on either side.
