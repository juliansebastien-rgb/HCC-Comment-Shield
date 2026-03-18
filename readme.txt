=== HCC Comment Shield ===
Contributors: mapage
Tags: comments, spam, anti-spam, moderation, security
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 0.1.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Shared anti-spam comment scoring powered by the HCC trust service.

== Description ==

HCC Comment Shield is a standalone WordPress plugin focused on comment spam.

It sends comment signals to the HCC trust service, which aggregates patterns across sites and returns a recommendation:

* allow
* moderate
* spam

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/hcc-comment-shield`.
2. Activate the plugin.
3. Go to `Settings > HCC Comment Shield`.
4. Choose whether medium-risk comments should be moderated and high-risk comments marked as spam.

== Changelog ==

= 0.1.0 =
* Initial release.
