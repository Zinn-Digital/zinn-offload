=== Zinn® Media Offload ===
Contributors: zinndigital
Plugin URI: https://zinndigital.com/wordpress-plugins/zinn-offload
Author: Neil Lock — CEO, Zinn Digital® Ltd
Author URI: https://zinndigital.com
Tags: media, cdn, offload, storage, images
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.1.2
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

== External services ==

This plugin moves your media library to Zinn® object storage and serves it from a CDN. It
requires a Zinn Digital® account and does nothing without one.

**What is sent, and when**

* **Pairing (once, from your Zinn® dashboard).** The plugin calls
  `https://api.zinndigital.com/v1/media-offload/claim` to obtain its own scoped credential. **No
  access key is ever typed into WordPress or stored in your database in plain form.**
* **Uploads (when you add media).** Files you upload to the media library are transmitted to
  Zinn® object storage via `/v1/media-offload/uploads`, and their public URLs are rewritten to the
  CDN. This is the plugin's purpose; the files are your own media.
* **Reporting.** `/v1/media-offload/report` returns offload status and storage usage for the
  dashboard.

**No post content, user accounts or visitor data are transmitted.** Until the site is paired the
plugin makes no outbound requests.

Service terms: https://zinndigital.com/legal/terms
Privacy policy: https://zinndigital.com/legal/privacy

== Translations ==

**Every string this plugin adds to your admin is translated into 57 languages** — labels, notices,
errors and settings, not a subset. The catalogues are bundled in the plugin, so they work as soon
as you set your site language; there is no separate language pack to install.

All 41 user-visible strings are complete in every one of the 53 languages WordPress can serve
today:

Amharic (am), Arabic (ar), Azerbaijani (az), Bulgarian (bg_BG), Bengali (Bangladesh)
(bn_BD), Czech (cs_CZ), German (de_DE), Greek (el), Spanish (Spain) (es_ES), Persian
(fa_IR), French (France) (fr_FR), Gujarati (gu), Hebrew (he_IL), Hindi (hi_IN), Croatian
(hr), Hungarian (hu_HU), Armenian (hy), Indonesian (id_ID), Italian (it_IT), Japanese
(ja), Georgian (ka_GE), Kazakh (kk), Khmer (km), Kannada (kn), Korean (ko_KR), Lao (lo),
Malayalam (ml_IN), Mongolian (mn), Marathi (mr), Malay (ms_MY), Myanmar (Burmese)
(my_MM), Nepali (ne_NP), Dutch (nl_NL), Panjabi (India) (pa_IN), Polish (pl_PL), Pashto
(ps), Portuguese (Brazil) (pt_BR), Romanian (ro_RO), Russian (ru_RU), Sinhala (si_LK),
Albanian (sq), Serbian (sr_RS), Swahili (sw), Tamil (ta_IN), Telugu (te), Thai (th),
Tagalog (tl), Turkish (tr_TR), Ukrainian (uk), Urdu (ur), Uzbek (uz_UZ), Vietnamese
(vi), Chinese (China) (zh_CN)

A further 4 ship complete in the plugin — Hausa (ha), Somali (so_SO), Tajik (tg), Yoruba (yo) — but
WordPress core does not currently provide a locale for them, so WordPress cannot load them.

= Right-to-left =

Arabic, Persian, Hebrew, Pashto and Urdu are right-to-left. Every screen this plugin adds was
rendered in a real WordPress install in each of those languages and checked, not assumed.

= For translators =

`languages/` holds the `.pot` template plus a `.po`, `.mo` and `.l10n.php` for every language, so
corrections and new languages can be contributed directly.

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

= 1.1.2 =
* Added: automatic updates from the Zinn Digital® control plane — the same signed, checksum-verified update path the other Zinn® plugins use. Previously a new version could not reach an installed site.

= 1.1.0 =
* Added the Zinn® panel: links to Zinn Digital® hosting, the Zinn® marketplace, Zinn Hub® and this plugin's user guide, from inside the WordPress admin.

= 1.0.0 =
* First release.
