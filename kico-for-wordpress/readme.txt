=== Kico for WordPress ===
Contributors: kico
Tags: ai, agent, mcp, elementor, automation
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Lets your Kico assistant and agents manage this site, with every change snapshotted, logged and reversible.

== Description ==

Kico for WordPress connects a site to Kico (https://kico.dev). Once an administrator connects it, Kico's assistant, the agents its user makes and the dev agents of their Kico projects can manage the site: posts and pages, Elementor layouts and Gutenberg blocks, media, menus and options, plugins and themes, site files and the database, and PHP inside WordPress.

It is distributed by Kico, not the wordpress.org directory: it runs code Kico's agents send, which the directory's guideline 8 does not allow.

= What a connected Kico can do =

Read this before connecting. A connected Kico acts as the administrator who connected it, and can do more than wp-admin allows:

* run any PHP inside WordPress, and any SQL on the site's database;
* read and change every file PHP may, including wp-config.php with the database password and the secret keys;
* create, change and delete users (administrators too), content, media, menus, options, plugins and themes;
* do all of that even when wp-config.php sets DISALLOW_FILE_EDIT or DISALLOW_FILE_MODS: those settings limit wp-admin's editors and installers, not PHP. Settings > Kico says which of them this site sets;
* on a multisite network, reach every site of it (they share the database and the files). That is why only super admins connect it there.

Protection inside the site cannot limit this. What protects the site is the key, which only Kico holds, Kico's own approvals, and the snapshots and the log kept outside the site.

= How it is kept safe =

* Approvals: Kico's writes run at once when its user asked for the work, or when a schedule the user made runs it. Writes started by an event or an incoming mail wait for the user's approval (on a site shared with an organization, an approver's), unless the user allowed that tool to run without asking.
* Pairing: an administrator (a super admin on multisite) clicks "Connect to Kico" in Settings > Kico and confirms in Kico. wp-admin and Kico show the same match code; confirm only when they match. The pairing works only in the browser that started it, and only for the address the site's own routes are on. Kico creates a key pair and keeps the private key sealed; the site stores only the public key, bound to that administrator.
* The pairing route answers only while a pairing started in wp-admin waits (10 minutes); otherwise it does not exist.
* Every request is signed (HTTP Message Signatures, Ed25519, over the method, host, path, query and body, with a nonce used once and the time). Unsigned requests get 404; the endpoint does not show in the REST index. No secret is ever in a URL.
* The administrator's capability is checked again on every call.
* Snapshots: before every write, what it changes is recorded and sent to Kico, kept there encrypted (by default 90 days while connected, 30 days after disconnecting), and Kico rolls back one operation with one click.
* Audit log: every call with its full arguments, hash-chained, on the site (Settings > Kico, kept 180 days, KICO_AUDIT_RETENTION_DAYS changes it) and mirrored in Kico (a year after disconnecting).
* Notifications: every administrator (and on multisite every super admin) gets an email when Kico is connected, disconnected, or gets a new key. Settings > Kico shows mails WordPress could not send.
* Updates: Kico signs every release; the plugin installs an update only when its signature verifies with the release key pinned in the plugin. WordPress updates the plugin on its own by default; Settings > Kico and the Plugins screen turn that off, and WordPress's own settings (AUTOMATIC_UPDATER_DISABLED, DISALLOW_FILE_MODS) still apply. Kico can also update it from the site's card, through WordPress's own upgrader, with the old version snapshotted first.
* The live preview: the site's pages may be framed by the Kico it is paired with, only for frame requests, and the site's own Content-Security-Policy is kept.

= How to revoke =

* Disconnect in Kico, or in Settings > Kico. Either takes effect at once.
* `define( 'KICO_DISABLED', true );` in wp-config.php turns the plugin off completely.
* Or rename the plugin's folder over FTP.

Deleting the plugin removes its tables, options and scheduled job on every site of the network. Kico's own copy (snapshots, its log) stays in Kico until the connection is deleted there.

= Privacy =

When connected, Kico can read and change the site's content and data, which may include visitors' personal data (comments, orders, form entries, accounts). Snapshots of what Kico changes are kept encrypted in Kico for the times above. The plugin suggests a paragraph for the site's privacy policy (Settings > Privacy > Policy Guide).

= What it needs =

WordPress 6.9 or later (the Abilities API) and PHP 7.4 or later with sodium (standard since PHP 7.2). It bundles the WordPress MCP Adapter, and uses the MCP Adapter plugin instead when that is active.

On hosts that limit PHP (disabled functions, open_basedir, read-only files) the plugin reports the limits (the env tool) and the agent picks another route; it never refuses on its own.

== Changelog ==

= 0.2.2 =
* Kico Cloud moved from kico.dev to app.kico.dev: Connect to Kico goes to https://app.kico.dev by default (kico.dev still forwards there).
* Kico tells a connected site when it moves to another address, in a request signed with the site's key: previews may then be framed from the new address, and from the old one for 90 more days, so they keep working while Kico moves. Settings > Kico's address follows when it was the old one. The change is in the site's log.
* Connecting the same Kico again after it moved replaces its old key, as it did before.

= 0.2.1 =
* "Update to <version>" in Kico: the site runs WordPress's own plugin upgrader, as wp-admin's "Update now" does, with the same signature check. The plugin's folder is snapshotted first, so the update rolls back from Kico.
* While the plugin replaces its own files it answers from the code already loaded, then checks in a request of its own that the new version loads, and puts the old one back at once when it does not.
* Auto-updates of the plugin, on by default: Settings > Kico and the Plugins screen's toggle are one setting, and WordPress's own settings still apply (Settings > Kico says which one stops them).
* Every answer to Kico says the plugin's version, so Kico's card shows an update made in wp-admin without a site check.

= 0.2.0 =
* Pairing: the site's address must match its routes; the confirm page shows where the key goes; a match code in wp-admin and Kico; the pairing works only in the browser that started it; the pair route exists only while a pairing waits, with a per-address limit.
* Multisite: only super admins connect Kico and only they can be acted as.
* Signatures cover the host too; a request Kico signed but a proxy changed gets a clear 401 instead of 404.
* The live preview keeps the site's Content-Security-Policy and changes headers only for frame requests.
* Every administrator is mailed on connect, disconnect and key change; failed mails show in Settings > Kico.
* Updates are signed and checked before they are installed; "View details" works.
* Uninstall removes everything on every site; the site's audit log is kept 180 days.
* Settings > Kico says what a connected Kico can do; a privacy policy suggestion; translatable.
* A new Settings > Kico in Kico's look, an icon on the Updates screen, and the organization a site is shared with.
* Requests pass web application firewalls (a signed, compressed envelope); large snapshots and rollbacks travel in parts; long calls finish in the background.
* An operation that dies (fatal error, exit, time limit) still leaves its log entry and snapshot.
* Database snapshots keep only the rows a statement changes; PHP and WP-CLI calls also keep the files they change; rollbacks are checked and find re-created rows by their unique key.
* Page caches are refreshed after every change, including the server's (HestiaCP/nginx, Varnish, Cloudflare); cache_check shows what visitors see.

= 0.1.0 =
* First version: pairing, signed MCP endpoint, files, database, PHP and WP-CLI, content, media, options, other plugins' abilities, Elementor and blocks, snapshots and rollback, audit log, live preview in Kico.
