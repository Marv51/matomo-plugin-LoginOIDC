![CI Pipeline](https://github.com/Marv51/matomo-plugin-LoginOIDC/actions/workflows/ci-pipeline.yml/badge.svg)
# Matomo LoginOIDC Plugin

> **This is a fork** of [dominik-th/matomo-plugin-LoginOIDC](https://github.com/dominik-th/matomo-plugin-LoginOIDC), which has not been updated since 2023.
> It adds security fixes, OpenID Connect discovery and an option to disable password login for linked users, see the [changelog](CHANGELOG.md).
> Please report issues [in this repository](https://github.com/Marv51/matomo-plugin-LoginOIDC/issues), not upstream.

## Description

Login via third party authentication services.

Easily add a "Login with GitHub" button your Matomo instance. You can also setup any other service to do the authentication for you.
OpenID Connect providers only need their domain or issuer URL, see the [FAQ](docs/faq.md).

## Installation

The Matomo Marketplace offers the original plugin, not this fork.

Download `LoginOIDC-<version>.zip` from the [releases](https://github.com/Marv51/matomo-plugin-LoginOIDC/releases), extract it into <MATOMO_INSTALLATION>/plugins/ and activate the plugin in the settings.
To update, replace the `LoginOIDC` folder.

## Local testing

`dev/` contains a Docker setup with Matomo 5 to test the plugin against your provider, see [dev/README.md](dev/README.md).

## Releasing

1. Set the new version in `plugin.json` and add a matching `### <version>` section to the [changelog](CHANGELOG.md).
2. Merge into `5.x-dev`, then tag the merge commit and push the tag, e.g. `git tag v5.1.0 && git push origin v5.1.0`.

The release workflow checks that the tag matches `plugin.json`, builds the zip without `dev/`, `tests/`, `.github/` and `screenshots/`, and publishes a GitHub release with the zip, its SHA-256 checksum and the changelog section as notes.
Pull requests get the zip attached to their workflow run, so packaging can be checked before a release.

## License

GNU General Public License v3.0
