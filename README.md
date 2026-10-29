<p align="center">
  <img src="https://kico.dev/og/wordpress.jpg" width="640" alt="Kico for WordPress: an AI agent inside your WordPress site">
</p>

# Kico for WordPress

A plugin that connects a self-hosted WordPress site to [Kico](https://kico.dev), the AI app builder for teams. Your assistant, agents and dev agents can then change posts, pages, Elementor layouts, plugins, themes, files and the database, and run WP-CLI.

- Every write is snapshotted outside the site and can be rolled back. A rollback can be undone too.
- Writes that a mail or an event started wait for your approval.
- Updates install only when the release is signed with a key pinned in the plugin.
- Requires WordPress 6.9 or later and PHP 7.4 or later.

[What it does](https://kico.dev/wordpress) · [Troubleshooting](https://kico.dev/help/wordpress) · [Download](https://kico.dev/download#wordpress)

## Install

Download `kico-for-wordpress.zip` from the [latest release](../../releases/latest) or from [kico.dev/download](https://kico.dev/download#wordpress), then in wp-admin go to **Plugins → Add New → Upload Plugin**. Open **Settings → Kico** and click **Connect to Kico**. Kico is invite-only: [request access](https://kico.dev/request).

Each release has the signed zip and its manifest (`kico-for-wordpress.json`, with the sha256 and the Ed25519 signature the plugin checks before it installs an update).

## Source

This repository is a mirror: Kico for WordPress is developed inside Kico's own repository and copied here on every release.

- `kico-for-wordpress/`: the plugin.
- `sql-parser/`: builds the namespaced SQL parser the plugin uses for row-level snapshots.
- `build.sh`: builds the zip (Composer and php-scoper run in Docker when they are not installed).
- `tests/unit.php`: unit checks.

Issues and security reports: hello@kico.dev.

## License

GPLv2 or later. See [`kico-for-wordpress/LICENSE`](kico-for-wordpress/LICENSE).
