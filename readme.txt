=== Tiny Backup ===
Contributors: murillotorres
Tags: backup, database, export, download, zip
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Download your database and wp-content folder (with or without uploads) straight from the dashboard.

== Description ==

Tools → Tiny Backup offers four downloads:

* **Database**: every table with the site's prefix, as .sql or .sql.gz.
* **wp-content without uploads**: themes, plugins, translations and the rest, as .zip.
* **wp-content with uploads**: everything.
* **Uploads only**: the media library, usually the biggest part of a site.

Backups are built while they download, so nothing is ever written to the server and the download starts right away, even on big sites.

* The zip writer is built in (no ZipArchive needed) and supports ZIP64 for files over 4 GB and more than 65,535 entries. Images, video and other compressed formats are stored as-is; text is deflated.
* The SQL dump runs through $wpdb (no mysqldump needed), reads every table inside one transaction for a consistent snapshot, writes binary columns as hex, skips generated columns and keeps each INSERT under 1 MB.
* Caches and other backup plugins' folders can be left out with one checkbox.
* Anything that could not be read is listed in tiny-backup-skipped.txt inside the zip.

Only administrators (super admins on multisite) can download backups.

== Frequently Asked Questions ==

= How do I restore a backup? =

Import the .sql or .sql.gz file with phpMyAdmin, Adminer or `wp db import`. Each zip holds the wp-content folder itself: extract it in the WordPress folder, where wp-config.php is.

= My download stops halfway. =

Some hosts kill PHP after a fixed time and don't let plugins change that; the backup page warns you when it detects this. Download wp-content and uploads separately to keep each download shorter.

== Changelog ==

= 1.0.0 =
* First release.
