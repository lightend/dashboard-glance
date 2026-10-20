=== Dashboard Glance ===
Contributors: mmobe
Donate link: 
Tags: dashboard, at a glance, cpt, post type, post status, admin
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds content control and detailed status counters to the "At a Glance" widget on the WordPress Dashboard.

== Description ==

**Dashboard Glance** gives you full control over the content and metrics displayed in the "At a Glance" widget on your WordPress Dashboard.

Add or remove items, reorder them with drag-and-drop, inspect detailed status breakdowns (drafts, pending review, scheduled, updates available, and more), assign custom Dashicons, and quickly link directly to "Add New" screens or front-end archives.

Display of all items strictly respects WordPress user capabilities and roles. Administrators can apply their glance configuration as the default across the entire site for all existing users or for newly registered users.

### Supported Items
* Custom post types (CPTs)
* Posts, Pages, and Media
* Revisions (Administrators only)
* Installed Plugins & Active/Inactive/Update metrics
* Active and installed Themes & update alerts
* Registered Users count
* Site Health status and issue indicators
* Personal Data Export and Erasure Requests (GDPR privacy requests)
* Gravity Forms
* Formidable Forms
* Ninja Forms
* GravityView
* GiveWP Forms
* Sliced Invoices & Quotes

### Detailed Status Counters
Dashboard Glance lets you toggle and view counts for items matching specific statuses:
* **Mine** (content authored by the currently logged-in user)
* **Scheduled** (future posts)
* **Pending Review**
* **Drafts**
* **Private**
* **Archived** (when using post status plugins)
* **Trash**
* **Unattached** (Media library items)
* **Confirmed, Failed, Completed** (Data privacy requests)
* **Spam & Moderation** (Comments)
* **Active & Inactive** (Plugins, Gravity Forms)
* **Updates Available** (Plugins & Themes)
* **Must-Use, Drop-Ins, Favorites, Recently Active** (Plugins)
* **Paused** (Plugins & Themes during recovery mode)
* **Good, Recommended Improvements, Critical Issues** (Site Health)

### Security & Modern Standards
* Built for WordPress 6.2+ and PHP 7.4 - 8.3+.
* Complete CSRF protection with WordPress nonces on every action.
* Strict role and capability verification for all actions.
* Clean, modular, standards-compliant JavaScript and CSS.

### Developer Filters
Easily customize Dashboard Glance using WordPress filter hooks:

* `dg_glance_selection` - Limit or extend the available items in the dropdown.
* `dg_labels` - Customize glance labels dynamically.
* `dg_option_icons` - Customize default Dashicon for specific post types or items.
* `dg_dashicons` - Restrict or expand the available icons in the icon picker.
* `dg_view_at_a_glance` - Customize the capability required to view the At a Glance metabox (default: `edit_posts`).
* `dg_show_mine` - Toggle visibility of author-specific post count.
* `dg_show_zero_count` - Show or hide items when their total count is zero.
* `dg_show_add_new` - Show or hide quick "Add New" shortcut buttons.
* `dg_show_all_status` - Enable or disable detailed status pills.
* `dg_show_zero_count_status` - Show or hide status pills when their count is zero.
* `dg_show_mustuse` - Show or hide Must-Use plugin counters.
* `dg_show_dropins` - Show or hide Drop-in plugin counters.
* `dg_show_archive` - Show or hide archive links for public post types.
* `dg_show_settings` - Show or hide the settings tray.

*(Note: Legacy `gt_*` filter hooks are also supported for backward compatibility.)*

== Installation ==

1. Upload the `dashboard-glance` folder to your `/wp-content/plugins/` directory, or install it directly via the WordPress Plugins repository.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Visit the WordPress Dashboard (`index.php`) and customize your "At a Glance" widget using the settings icon.

== Frequently Asked Questions ==

= Who can edit glanced items? =
By default, any user with the `read` capability can configure their personal glance items. Site-wide default assignment requires `edit_dashboard` or `manage_options` permissions.

= Can I migrate from the old Glance That plugin? =
Yes. Dashboard Glance automatically detects and imports any previous configuration and sort orders from `Glance That` without any data loss.

= Can I reorder items? =
Yes! Simply drag and drop the items inside the At a Glance widget. Your custom sort order is saved automatically.

== Screenshots ==

1. At a Glance widget enhanced with custom post types and status badges.
2. Settings tray and item management form.
3. Built-in Dashicon picker with instant search.

== Changelog ==

= 1.0.0 =
* Initial release of Dashboard Glance (modernized, secure fork of Glance That).
* Full security overhaul: Added nonce verification and strict capability checks to all AJAX endpoints.
* Fixed broken access control and removed IDOR vulnerabilities.
* Full compatibility with WordPress 6.2+ and PHP 7.4 through 8.3+.
* Fixed PHP 8 count() fatal error risk on external plugin API requests with transient caching.
* Fixed syntax logic error in draft post capability check.
* Removed obsolete external donation and Twitter sharing links.
* Modernized admin styles and Dashicons selector.
* Added automatic migration from legacy Glance That user settings.
