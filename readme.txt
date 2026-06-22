=== Flex Explorer ===
Contributors: flexatech
Tags: file manager, files, browser, download, admin
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.2.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight, read-only file browser for WordPress. Browse folders and view files inside wp-content from the admin.

== Description ==

Flex Explorer adds a simple, read-only file browser to the WordPress admin. It
lets administrators look through the contents of the `wp-content` directory and
preview text files and images without leaving wp-admin or opening an FTP client.

It never modifies your site: it cannot create, edit, upload, rename, move or
delete files. You can download an individual file, or a whole folder as a ZIP,
for offline viewing, and the originals are always left untouched. Every request is
restricted to the `wp-content` directory, is limited to administrators, and is
verified with a nonce.

**Features**

* Browse folders inside `wp-content` with a breadcrumb path.
* List files and folders with size and last-modified date.
* Search filenames in the current folder and everything below it.
* Preview text and code files inline (up to 2 MB).
* Preview common images (JPEG, PNG, GIF, WebP, BMP, ICO) inline (up to 5 MB).
* Download any single file, or the current folder packaged as a ZIP.
* Uses the jQuery bundled with WordPress and plain CSS - no build step, no external libraries.

**Security**

* Access is limited to users with the `manage_options` capability.
* On multisite, access is further restricted to network super admins, since a
  per-site administrator should not be able to read the shared `wp-content`
  tree.
* If the site defines `DISALLOW_FILE_EDIT` as true, Flex Explorer disables
  itself (including its admin menu) to respect that lockdown.
* All AJAX requests are nonce-verified.
* Every path is resolved with `realpath()` and confined to the `wp-content`
  directory; path traversal and symlink escapes are rejected.
* `wp-config.php`, `.htaccess` and `.htpasswd` can never be viewed or
  downloaded, and are skipped when building a ZIP.
* Downloads are always sent as attachments (`application/octet-stream`) so the
  browser never renders file contents inline.
* ZIP archives are bounded (file count and total size) and skip symlinks so a
  large or looping tree cannot exhaust server resources.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/flex-explorer`, or install
   through the WordPress **Plugins** screen.
2. Activate the plugin through the **Plugins** screen.
3. Open **Tools → Flex Explorer** from the admin menu.

== Frequently Asked Questions ==

= Who can use Flex Explorer? =

Users with the `manage_options` capability (administrators). On a multisite
network, access is limited to super admins, because `manage_options` is granted
to every per-site administrator while `wp-content` is shared across the network.

= Why is Flex Explorer missing from my admin menu? =

If your site (or your host) defines the `DISALLOW_FILE_EDIT` constant as true,
Flex Explorer disables itself entirely to honour that file-access lockdown. The
same applies to per-site administrators on multisite, who are not super admins.

= Can it edit or delete files? =

No. It never changes anything on disk: it cannot edit, rename, move, upload or
delete files. It can browse, search, preview, and download copies (including a
folder as a ZIP), but the originals are always left untouched.

= How does search work? =

Type in the search box to match filenames in the folder you are currently
viewing and all of its subfolders. Matching is a case-insensitive match on the
file name; results are capped, so narrow your search if you hit the limit.

= Can I download a whole folder? =

Yes. Use **Download folder as ZIP** to package the current folder (and its
subfolders) into a single archive. Very large folders are refused to protect
the server, and `wp-config.php`, `.htaccess` and `.htpasswd` are never
included. ZIP support requires the PHP `zip` extension.

= Which folders can I browse? =

Only the `wp-content` directory and its subfolders. Paths outside it, and
symbolic links that point outside it, are rejected.

= Why can't I open wp-config.php? =

Sensitive files (`wp-config.php`, `.htaccess`, `.htpasswd`) are blocked from
viewing by design.

== Screenshots ==

1. Browsing wp-content with a file previewed alongside the listing.

== Changelog ==

= 0.2.4 =
* Derive the content root solely from `wp_upload_dir()` and drop the `WP_CONTENT_DIR` fallback, so the browser root is resolved entirely through core location functions with no internal constants.

= 0.2.3 =
* Resolve the content directory through `wp_upload_dir()` instead of the `WP_CONTENT_DIR` constant, so the browser root follows custom content/uploads locations.
* Restore the original `zlib.output_compression` setting after a download streams, keeping the change confined to the single download request.

= 0.2.2 =
* New: search filenames in the current folder and all subfolders (results are bounded); matching folds multibyte characters where available.

= 0.2.1 =
* Lowered the ZIP size limit to 50 MB so archives stay within typical shared-hosting execution limits.

= 0.2.0 =
* New: download an individual file (sent as an attachment, never rendered inline).
* New: download the current folder as a ZIP, with file-count and total-size limits, skipping symlinks and blocked files.
* Downloads flush pending output buffers and disable on-the-fly compression before streaming, so binary files and archives are never corrupted or truncated.

= 0.1.1 =
* Security: on multisite, restrict access to network super admins instead of every per-site administrator.
* Security: disable the plugin (including its admin menu) when `DISALLOW_FILE_EDIT` is defined as true.
* Move the admin page under **Tools** instead of a top-level menu item.

= 0.1.0 =
* Initial release: read-only browsing of wp-content with inline text and image preview.

== Upgrade Notice ==

= 0.2.4 =
Resolves the content directory entirely through core functions with no internal constants. Recommended maintenance update.

= 0.2.3 =
Uses core location functions for the content directory and tidies download handling. Recommended maintenance update.

= 0.2.2 =
Adds filename search and confirms compatibility with WordPress 7.0.

= 0.2.0 =
Adds single-file and folder-as-ZIP downloads.

= 0.1.1 =
Security: tightens access on multisite and honours DISALLOW_FILE_EDIT. Recommended for all multisite users.
