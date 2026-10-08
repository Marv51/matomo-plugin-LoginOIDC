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

Put the files from this repo in <MATOMO_INSTALLATION>/plugins/LoginOIDC and activate it in the settings. The `dev/` directory is not needed.

## Local testing

`dev/` contains a Docker setup with Matomo 5 to test the plugin against your provider, see [dev/README.md](dev/README.md).

## License

GNU General Public License v3.0
