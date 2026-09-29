=== Auth Gate Popup ===
Contributors: lawrancebabu
Tags: login popup, registration, members only, woocommerce, access control
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Forced login and registration popup for logged-out visitors, with login required before WooCommerce add-to-cart.

== Description ==

Auth Gate Popup shows a full-screen login / sign-up modal to every logged-out visitor. The modal cannot be dismissed, so visitors must log in or create an account to use the site. WooCommerce products are not purchasable by guests.

Features:

* Forced login / register popup for logged-out visitors.
* No close button, and the Escape key cannot dismiss the popup.
* Configurable popup image and alt text, with bundled default artwork.
* Admin base (accent) color setting under Settings > Auth Gate Popup.
* Visitor registration from the popup, with first name and last name.
* Configurable terms checkbox text, optional or required, or hidden.
* Automatic login immediately after registration.
* Forgot password flow inside the popup, plus a success message after reset.
* Login required before adding WooCommerce products to the cart.
* Enables public registration on activation and sets the default role to "customer" (falls back to "subscriber" when WooCommerce is not active).
* Translation ready.

The bundled default image is sample artwork from the author's own site, topshelfpeptide.com. Replace it with your own image in the settings.

== Installation ==

1. In WordPress admin, go to Plugins > Add New > Upload Plugin.
2. Upload the plugin zip file.
3. Activate the plugin.
4. Go to Settings > Auth Gate Popup to set the popup image, alt text, base color and terms text.

== Frequently Asked Questions ==

= Can visitors close the popup? =

No. There is no close button and the Escape key is blocked. Visitors must log in or register.

= Can I turn off the terms checkbox? =

Yes. Untick "Require terms checkbox" to make it optional, or clear the terms text to hide it.

= What happens on deactivation? =

Public registration and the default role set on activation are left unchanged. Review them under Settings > General.

= What happens on uninstall? =

The plugin's settings options are deleted. Registration settings and per-user consent timestamps are kept.

= Does it work with other login or security plugins? =

If another plugin heavily customizes registration, test on a staging site first.

== Screenshots ==

1. Login view of the popup.

== Changelog ==

= 1.1.0 =
* Renamed all internal keys to a neutral agp_ prefix, text domain to auth-gate-popup and main file to auth-gate-popup.php.
* Added one-time migration of settings and terms consent user meta from the pre 1.1.0 keys.
* Added settings for image alt text, terms text and whether the terms checkbox is required, with generic defaults.
* Default image is now resolved at runtime instead of being saved as an absolute URL.
* Added translation loading and a .pot file.
* Admin media picker strings are now translatable.
* Error messages from WordPress core are shown as plain text in the popup.
* Removed an empty template_redirect callback, unused CSS and an unused localized nonce.
* Hardened settings sanitization for non-array input.
* Added uninstall cleanup and WordPress Coding Standards config.

= 1.0.2 =
* Initial release.

== Upgrade Notice ==

= 1.1.0 =
The main plugin file was renamed. After updating, reactivate the plugin. Settings are migrated automatically, but custom terms text must be re-entered under Settings > Auth Gate Popup.
