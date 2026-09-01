=== Zinn® Media Offload ===
Contributors: zinndigital
Plugin URI: https://zinndigital.com
Author: Neil Lock — CEO, Zinn Digital® Ltd
Author URI: https://zinndigital.com
Tags: media, cdn, offload, storage, images
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Move your media library to Zinn® object storage and serve it from a CDN — without ever typing an access key into WordPress.

== Description ==

Images and video are usually the largest thing a WordPress site serves, and the slowest. This plugin moves your media library to object storage and serves it from a CDN, so your web server stops sending the bytes and your pages get faster everywhere in the world.

**You never enter an access key.** That is the difference between this plugin and the rest of the category.

Every other media-offload plugin asks you to paste an S3 access key and secret into WordPress, where they sit in the `wp_options` table — readable by every other plugin on your site, copied into every migration export, and included in the backup you send to a developer. A leaked pair of those is your entire bucket.

Instead, you turn media offload on in your Zinn Digital® dashboard, paste a short pairing code here once, and this plugin holds a token that works for **this site's folder and nothing else**. Before each upload it asks Zinn Digital® for a one-time signed link, and the file goes straight from your web server to storage. If the token is ever exposed, you revoke it from your dashboard in one click — which is not something you can do to a leaked access key.

= What it does =

* Uploads new media — the original **and every thumbnail size WordPress generates** — to your Zinn® storage.
* Rewrites image URLs, including `srcset` responsive images, to your CDN address.
* Moves your existing media library in the background, a few files at a time, picking up where it left off.
* Optionally removes the local copies once every size has uploaded successfully.

= What it does not do =

* It adds no endpoint, no shortcode and nothing to your public pages.
* It never deletes anything from storage. Retention is decided in your dashboard.
* It does not delete local files after a partial upload — if any size fails, every local copy stays.
* It has no settings of its own. Which bucket, whether URLs are rewritten and whether local copies are removed are all decided in your Zinn Digital® dashboard, so there is only ever one place an answer lives.

= What it needs =

* A Zinn Digital® account with object storage enabled for this site.
* Outbound HTTPS from your web server.

== Installation ==

1. Install and activate the plugin.
2. In your Zinn Digital® dashboard, open the site, turn on **Media offload**, and copy the pairing code.
3. In WordPress go to **Settings → Zinn® Media Offload**, paste the code, and press **Connect this site**.

New uploads move immediately. Your existing library moves in the background over the following hours.

== Frequently Asked Questions ==

= What happens to my images if I deactivate the plugin? =

Media that has already moved keeps working — its address does not change, because it is served from the CDN and the CDN is still there. New uploads stay on your web server.

= What happens if Zinn Digital® is unreachable when I upload something? =

The upload succeeds and the file stays on your server, exactly as it would without this plugin. The settings screen shows what went wrong, and the background pass picks the file up later.

= Will it delete my local files? =

Only if you switch that on in your Zinn Digital® dashboard, and only for an attachment where **every** size uploaded successfully. A partial upload never removes anything, because there would then be thumbnails in neither place and no original to regenerate them from.

= Does it work with responsive images? =

Yes. It rewrites `srcset` and sized-image requests as well as the plain attachment URL. A plugin that only rewrote the plain URL would serve your full-size images from the CDN and every responsive candidate from your server — which looks correct on a desktop and is wrong on a phone.

= Can I use my own bucket? =

Yes. Attach your own S3-compatible bucket in your Zinn Digital® dashboard; the plugin works the same way and still never holds the key.

== Changelog ==

= 1.0.0 =
* First release.
