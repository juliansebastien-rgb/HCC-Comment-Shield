=== HCC Comment Shield ===
Contributors: mapage
Tags: comments, spam, anti-spam, moderation, security
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 1.2.1
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

Key features:

* Shared comment spam scoring across your WordPress sites
* Local whitelist and blacklist rules for emails, domains, and keywords
* Tolerant, Balanced, and Strict protection modes
* Comment logs directly in WordPress
* Feedback learning when admins mark comments as spam or approve them
* HCC AI Tips widget in the WordPress dashboard
* Weekly HTML summary emails with anti-spam trends and suggestions

The trust service now learns not only from repeated comment text, but also from confirmed-spam email domains, exact email addresses, IP addresses, and author URL domains.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/hcc-comment-shield`.
2. Activate the plugin.
3. Go to `Settings > HCC Comment Shield`.
4. Choose your protection mode and local whitelist / blacklist rules.
5. Optionally enable the weekly summary email.

== Frequently Asked Questions ==

= Does it replace Akismet? =

It is designed as an independent anti-spam system powered by your own HCC trust service. It does not rely on Akismet.

= Does it learn from my moderation actions? =

Yes. When you mark a comment as spam, approve a moderated comment, or restore a false positive, the plugin sends that feedback to the trust service.

= What does the HCC AI Tips widget do? =

It summarizes the last few days of comment activity and suggests practical actions, such as moving to Strict mode or blacklisting recurring spam domains.

== Changelog ==

= 1.2.1 =
* Added native GitHub update support inside WordPress.
* Improved HCC AI Tips with stronger sender-reputation guidance.

= 1.2.0 =
* Added WordPress dashboard AI tips widget.
* Added weekly HTML summary email.
* Added stronger learning from confirmed spam domains, emails, IPs, and author URLs.
* Added automatic scheduling safeguard for weekly summaries on updated installs.

= 1.0.1 =
* Added feedback visibility in WordPress logs.

= 1.0.0 =
* Added admin feedback loop for spam, approved, and false positive comment decisions.

= 0.2.0 =
* Added logs page, protection modes, and local whitelist / blacklist rules.

= 0.1.0 =
* Initial release.
