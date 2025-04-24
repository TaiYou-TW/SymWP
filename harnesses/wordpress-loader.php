<?php

function my_wp_initial_constants()
{
    global $blog_id;

    /**#@+
     * Constants for expressing human-readable data sizes in their respective number of bytes.
     *
     * @since 4.4.0
     * @since 6.0.0 `PB_IN_BYTES`, `EB_IN_BYTES`, `ZB_IN_BYTES`, and `YB_IN_BYTES` were added.
     */
    define('KB_IN_BYTES', 1024);
    define('MB_IN_BYTES', 1024 * KB_IN_BYTES);
    define('GB_IN_BYTES', 1024 * MB_IN_BYTES);
    define('TB_IN_BYTES', 1024 * GB_IN_BYTES);
    define('PB_IN_BYTES', 1024 * TB_IN_BYTES);
    define('EB_IN_BYTES', 1024 * PB_IN_BYTES);
    define('ZB_IN_BYTES', 1024 * EB_IN_BYTES);
    define('YB_IN_BYTES', 1024 * ZB_IN_BYTES);
    /**#@-*/

    define('WP_START_TIMESTAMP', microtime(true));

    $current_limit = ini_get('memory_limit');

    // Define memory limits.
    define('WP_MEMORY_LIMIT', $current_limit);

    define('WP_MAX_MEMORY_LIMIT', $current_limit);

    // Set memory limits.
    ini_set('memory_limit', WP_MEMORY_LIMIT);

    $blog_id = 1;

    define('WP_CONTENT_DIR', ABSPATH . 'wp-content'); // No trailing slash, full paths only - WP_CONTENT_URL is defined further down.

    /*
     * Add define( 'WP_DEVELOPMENT_MODE', 'core' ), or define( 'WP_DEVELOPMENT_MODE', 'plugin' ), or
     * define( 'WP_DEVELOPMENT_MODE', 'theme' ), or define( 'WP_DEVELOPMENT_MODE', 'all' ) to wp-config.php
     * to signify development mode for WordPress core, a plugin, a theme, or all three types respectively.
     */
    define('WP_DEVELOPMENT_MODE', '');

    // Add define( 'WP_DEBUG', true ); to wp-config.php to enable display of notices during development.
    define('WP_DEBUG', false);

    /*
     * Add define( 'WP_DEBUG_DISPLAY', null ); to wp-config.php to use the globally configured setting
     * for 'display_errors' and not force errors to be displayed. Use false to force 'display_errors' off.
     */
    define('WP_DEBUG_DISPLAY', null);

    // Add define( 'WP_DEBUG_LOG', true ); to enable error logging to wp-content/debug.log.
    define('WP_DEBUG_LOG', false);

    define('WP_CACHE', false);

    /*
     * Add define( 'SCRIPT_DEBUG', true ); to wp-config.php to enable loading of non-minified,
     * non-concatenated scripts and stylesheets.
     */
    define('SCRIPT_DEBUG', false);

    /**
     * Private
     */
    define('MEDIA_TRASH', false);

    define('SHORTINIT', false);

    // Constants for features added to WP that should short-circuit their plugin implementations.
    define('WP_FEATURE_BETTER_PASSWORDS', true);

    /**#@+
     * Constants for expressing human-readable intervals
     * in their respective number of seconds.
     *
     * Please note that these values are approximate and are provided for convenience.
     * For example, MONTH_IN_SECONDS wrongly assumes every month has 30 days and
     * YEAR_IN_SECONDS does not take leap years into account.
     *
     * If you need more accuracy please consider using the DateTime class (https://www.php.net/manual/en/class.datetime.php).
     *
     * @since 3.5.0
     * @since 4.4.0 Introduced `MONTH_IN_SECONDS`.
     */
    define('MINUTE_IN_SECONDS', 60);
    define('HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS);
    define('DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS);
    define('WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS);
    define('MONTH_IN_SECONDS', 30 * DAY_IN_SECONDS);
    define('YEAR_IN_SECONDS', 365 * DAY_IN_SECONDS);
    /**#@-*/
}

function my_wp_set_lang_dir()
{
    define('WP_LANG_DIR', WP_CONTENT_DIR . '/languages');
    define('LANGDIR', 'wp-content/languages');
}

function my_wp_plugin_directory_constants()
{
    define('WP_CONTENT_URL', get_option('siteurl') . '/wp-content'); // Full URL - WP_CONTENT_DIR is defined further up.
    define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins'); // Full path, no trailing slash.
    define('WP_PLUGIN_URL', WP_CONTENT_URL . '/plugins'); // Full URL, no trailing slash.
    define('PLUGINDIR', 'wp-content/plugins'); // Relative to ABSPATH. For back compat.
    define('WPMU_PLUGIN_DIR', WP_CONTENT_DIR . '/mu-plugins'); // Full path, no trailing slash.
    define('WPMU_PLUGIN_URL', WP_CONTENT_URL . '/mu-plugins'); // Full URL, no trailing slash.
    define('MUPLUGINDIR', 'wp-content/mu-plugins'); // Relative to ABSPATH. For back compat.
}

function my_wp_cookie_constants()
{
    define('COOKIEHASH', '85613653295dc0889e009cc5f528c28a'); // some non-sense value
    define('USER_COOKIE', 'wordpressuser_' . COOKIEHASH);
    define('PASS_COOKIE', 'wordpresspass_' . COOKIEHASH);
    define('AUTH_COOKIE', 'wordpress_' . COOKIEHASH);
    define('SECURE_AUTH_COOKIE', 'wordpress_sec_' . COOKIEHASH);
    define('LOGGED_IN_COOKIE', 'wordpress_logged_in_' . COOKIEHASH);
    define('TEST_COOKIE', 'wordpress_test_cookie');
    define('COOKIEPATH', preg_replace('|https?://[^/]+|i', '', get_option('home') . '/'));
    define('SITECOOKIEPATH', preg_replace('|https?://[^/]+|i', '', get_option('siteurl') . '/'));
    define('ADMIN_COOKIE_PATH', SITECOOKIEPATH . 'wp-admin');
    define('PLUGINS_COOKIE_PATH', preg_replace('|https?://[^/]+|i', '', WP_PLUGIN_URL));
    define('COOKIE_DOMAIN', '');
    define('RECOVERY_MODE_COOKIE', 'wordpress_rec_' . COOKIEHASH);
}

function my_require_wp_db()
{
    global $wpdb;

    require_once ABSPATH . WPINC . '/class-wpdb.php';

    require_once WP_CONTENT_DIR . '/db.php';
}

define('ABSPATH', './WordPress/');
define('WPINC', 'wp-includes');

require ABSPATH . WPINC . '/version.php';
require ABSPATH . WPINC . '/compat.php';
require ABSPATH . WPINC . '/load.php';

require ABSPATH . WPINC . '/class-wp-paused-extensions-storage.php';
require ABSPATH . WPINC . '/class-wp-exception.php';
require ABSPATH . WPINC . '/class-wp-fatal-error-handler.php';
require ABSPATH . WPINC . '/class-wp-recovery-mode-cookie-service.php';
require ABSPATH . WPINC . '/class-wp-recovery-mode-key-service.php';
require ABSPATH . WPINC . '/class-wp-recovery-mode-link-service.php';
require ABSPATH . WPINC . '/class-wp-recovery-mode-email-service.php';
require ABSPATH . WPINC . '/class-wp-recovery-mode.php';
require ABSPATH . WPINC . '/error-protection.php';
require ABSPATH . WPINC . '/default-constants.php';
require_once ABSPATH . WPINC . '/plugin.php';

my_wp_initial_constants();

my_wp_set_lang_dir();

require ABSPATH . WPINC . '/class-wp-list-util.php';
require ABSPATH . WPINC . '/class-wp-token-map.php';
require ABSPATH . WPINC . '/formatting.php';
require ABSPATH . WPINC . '/meta.php';
require ABSPATH . WPINC . '/functions.php';
require ABSPATH . WPINC . '/class-wp-meta-query.php';
require ABSPATH . WPINC . '/class-wp-matchesmapregex.php';
require ABSPATH . WPINC . '/class-wp.php';
require ABSPATH . WPINC . '/class-wp-error.php';
require ABSPATH . WPINC . '/pomo/mo.php';
require ABSPATH . WPINC . '/l10n/class-wp-translation-controller.php';
require ABSPATH . WPINC . '/l10n/class-wp-translations.php';
require ABSPATH . WPINC . '/l10n/class-wp-translation-file.php';
require ABSPATH . WPINC . '/l10n/class-wp-translation-file-mo.php';
require ABSPATH . WPINC . '/l10n/class-wp-translation-file-php.php';

global $wpdb;
my_require_wp_db();

$GLOBALS['table_prefix'] = 'wp_';

wp_set_wpdb_vars();

wp_start_object_cache();

require_once ABSPATH . WPINC . '/l10n.php';
require_once ABSPATH . WPINC . '/class-wp-textdomain-registry.php';
require_once ABSPATH . WPINC . '/class-wp-locale.php';
require_once ABSPATH . WPINC . '/class-wp-locale-switcher.php';

require ABSPATH . WPINC . '/class-wp-walker.php';
require ABSPATH . WPINC . '/class-wp-ajax-response.php';
require ABSPATH . WPINC . '/capabilities.php';
require ABSPATH . WPINC . '/class-wp-roles.php';
require ABSPATH . WPINC . '/class-wp-role.php';
require ABSPATH . WPINC . '/class-wp-user.php';
require ABSPATH . WPINC . '/class-wp-query.php';
require ABSPATH . WPINC . '/query.php';
require ABSPATH . WPINC . '/class-wp-date-query.php';
require ABSPATH . WPINC . '/theme.php';
require ABSPATH . WPINC . '/class-wp-theme.php';
require ABSPATH . WPINC . '/class-wp-theme-json-schema.php';
require ABSPATH . WPINC . '/class-wp-theme-json-data.php';
require ABSPATH . WPINC . '/class-wp-theme-json.php';
require ABSPATH . WPINC . '/class-wp-theme-json-resolver.php';
require ABSPATH . WPINC . '/class-wp-duotone.php';
require ABSPATH . WPINC . '/global-styles-and-settings.php';
require ABSPATH . WPINC . '/class-wp-block-template.php';
require ABSPATH . WPINC . '/class-wp-block-templates-registry.php';
require ABSPATH . WPINC . '/block-template-utils.php';
require ABSPATH . WPINC . '/block-template.php';
require ABSPATH . WPINC . '/theme-templates.php';
require ABSPATH . WPINC . '/theme-previews.php';
require ABSPATH . WPINC . '/template.php';
require ABSPATH . WPINC . '/https-detection.php';
require ABSPATH . WPINC . '/https-migration.php';
require ABSPATH . WPINC . '/class-wp-user-request.php';
require ABSPATH . WPINC . '/user.php';
require ABSPATH . WPINC . '/class-wp-user-query.php';
require ABSPATH . WPINC . '/class-wp-session-tokens.php';
require ABSPATH . WPINC . '/class-wp-user-meta-session-tokens.php';
require ABSPATH . WPINC . '/general-template.php';
require ABSPATH . WPINC . '/link-template.php';
require ABSPATH . WPINC . '/author-template.php';
require ABSPATH . WPINC . '/robots-template.php';
require ABSPATH . WPINC . '/post.php';
require ABSPATH . WPINC . '/class-walker-page.php';
require ABSPATH . WPINC . '/class-walker-page-dropdown.php';
require ABSPATH . WPINC . '/class-wp-post-type.php';
require ABSPATH . WPINC . '/class-wp-post.php';
require ABSPATH . WPINC . '/post-template.php';
require ABSPATH . WPINC . '/revision.php';
require ABSPATH . WPINC . '/post-formats.php';
require ABSPATH . WPINC . '/post-thumbnail-template.php';
require ABSPATH . WPINC . '/category.php';
require ABSPATH . WPINC . '/class-walker-category.php';
require ABSPATH . WPINC . '/class-walker-category-dropdown.php';
require ABSPATH . WPINC . '/category-template.php';
require ABSPATH . WPINC . '/comment.php';
require ABSPATH . WPINC . '/class-wp-comment.php';
require ABSPATH . WPINC . '/class-wp-comment-query.php';
require ABSPATH . WPINC . '/class-walker-comment.php';
require ABSPATH . WPINC . '/comment-template.php';
require ABSPATH . WPINC . '/rewrite.php';
require ABSPATH . WPINC . '/class-wp-rewrite.php';
require ABSPATH . WPINC . '/feed.php';
require ABSPATH . WPINC . '/bookmark.php';
require ABSPATH . WPINC . '/bookmark-template.php';
require ABSPATH . WPINC . '/kses.php';
require ABSPATH . WPINC . '/cron.php';
require ABSPATH . WPINC . '/deprecated.php';
require ABSPATH . WPINC . '/script-loader.php';
require ABSPATH . WPINC . '/taxonomy.php';
require ABSPATH . WPINC . '/class-wp-taxonomy.php';
require ABSPATH . WPINC . '/class-wp-term.php';
require ABSPATH . WPINC . '/class-wp-term-query.php';
require ABSPATH . WPINC . '/class-wp-tax-query.php';
require ABSPATH . WPINC . '/update.php';
require ABSPATH . WPINC . '/canonical.php';
require ABSPATH . WPINC . '/shortcodes.php';
require ABSPATH . WPINC . '/embed.php';
require ABSPATH . WPINC . '/class-wp-embed.php';
require ABSPATH . WPINC . '/class-wp-oembed.php';
require ABSPATH . WPINC . '/class-wp-oembed-controller.php';
require ABSPATH . WPINC . '/media.php';
require ABSPATH . WPINC . '/http.php';
// require ABSPATH . WPINC . '/html-api/html5-named-character-references.php';
// require ABSPATH . WPINC . '/html-api/class-wp-html-attribute-token.php';
// require ABSPATH . WPINC . '/html-api/class-wp-html-span.php';
// require ABSPATH . WPINC . '/html-api/class-wp-html-doctype-info.php';
// require ABSPATH . WPINC . '/html-api/class-wp-html-text-replacement.php';
// require ABSPATH . WPINC . '/html-api/class-wp-html-decoder.php';
// require ABSPATH . WPINC . '/html-api/class-wp-html-tag-processor.php';
// require ABSPATH . WPINC . '/html-api/class-wp-html-unsupported-exception.php';
// require ABSPATH . WPINC . '/html-api/class-wp-html-active-formatting-elements.php';
// require ABSPATH . WPINC . '/html-api/class-wp-html-open-elements.php';
// require ABSPATH . WPINC . '/html-api/class-wp-html-token.php';
// require ABSPATH . WPINC . '/html-api/class-wp-html-stack-event.php';
// require ABSPATH . WPINC . '/html-api/class-wp-html-processor-state.php';
// require ABSPATH . WPINC . '/html-api/class-wp-html-processor.php';
require ABSPATH . WPINC . '/class-wp-http.php';
require ABSPATH . WPINC . '/class-wp-http-streams.php';
require ABSPATH . WPINC . '/class-wp-http-curl.php';
require ABSPATH . WPINC . '/class-wp-http-proxy.php';
require ABSPATH . WPINC . '/class-wp-http-cookie.php';
require ABSPATH . WPINC . '/class-wp-http-encoding.php';
require ABSPATH . WPINC . '/class-wp-http-response.php';
require ABSPATH . WPINC . '/class-wp-http-requests-response.php';
require ABSPATH . WPINC . '/class-wp-http-requests-hooks.php';
require ABSPATH . WPINC . '/widgets.php';
require ABSPATH . WPINC . '/class-wp-widget.php';
require ABSPATH . WPINC . '/class-wp-widget-factory.php';
require ABSPATH . WPINC . '/nav-menu-template.php';
require ABSPATH . WPINC . '/nav-menu.php';
require ABSPATH . WPINC . '/admin-bar.php';
require ABSPATH . WPINC . '/class-wp-application-passwords.php';
// require ABSPATH . WPINC . '/rest-api.php';
// require ABSPATH . WPINC . '/rest-api/class-wp-rest-server.php';
// require ABSPATH . WPINC . '/rest-api/class-wp-rest-response.php';
require ABSPATH . WPINC . '/rest-api/class-wp-rest-request.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-posts-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-attachments-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-global-styles-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-post-types-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-post-statuses-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-revisions-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-global-styles-revisions-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-template-revisions-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-autosaves-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-template-autosaves-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-taxonomies-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-terms-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-menu-items-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-menus-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-menu-locations-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-users-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-comments-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-search-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-blocks-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-block-types-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-block-renderer-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-settings-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-themes-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-plugins-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-block-directory-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-edit-site-export-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-pattern-directory-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-block-patterns-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-block-pattern-categories-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-application-passwords-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-site-health-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-sidebars-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-widget-types-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-widgets-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-templates-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-url-details-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-navigation-fallback-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-font-families-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-font-faces-controller.php';
// require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-font-collections-controller.php';
// require ABSPATH . WPINC . '/rest-api/fields/class-wp-rest-meta-fields.php';
// require ABSPATH . WPINC . '/rest-api/fields/class-wp-rest-comment-meta-fields.php';
// require ABSPATH . WPINC . '/rest-api/fields/class-wp-rest-post-meta-fields.php';
// require ABSPATH . WPINC . '/rest-api/fields/class-wp-rest-term-meta-fields.php';
// require ABSPATH . WPINC . '/rest-api/fields/class-wp-rest-user-meta-fields.php';
// require ABSPATH . WPINC . '/rest-api/search/class-wp-rest-search-handler.php';
// require ABSPATH . WPINC . '/rest-api/search/class-wp-rest-post-search-handler.php';
// require ABSPATH . WPINC . '/rest-api/search/class-wp-rest-term-search-handler.php';
// require ABSPATH . WPINC . '/rest-api/search/class-wp-rest-post-format-search-handler.php';
// require ABSPATH . WPINC . '/sitemaps.php';
// require ABSPATH . WPINC . '/sitemaps/class-wp-sitemaps.php';
// require ABSPATH . WPINC . '/sitemaps/class-wp-sitemaps-index.php';
// require ABSPATH . WPINC . '/sitemaps/class-wp-sitemaps-provider.php';
// require ABSPATH . WPINC . '/sitemaps/class-wp-sitemaps-registry.php';
// require ABSPATH . WPINC . '/sitemaps/class-wp-sitemaps-renderer.php';
// require ABSPATH . WPINC . '/sitemaps/class-wp-sitemaps-stylesheet.php';
// require ABSPATH . WPINC . '/sitemaps/providers/class-wp-sitemaps-posts.php';
// require ABSPATH . WPINC . '/sitemaps/providers/class-wp-sitemaps-taxonomies.php';
// require ABSPATH . WPINC . '/sitemaps/providers/class-wp-sitemaps-users.php';
require ABSPATH . WPINC . '/class-wp-block-bindings-source.php';
require ABSPATH . WPINC . '/class-wp-block-bindings-registry.php';
require ABSPATH . WPINC . '/class-wp-block-editor-context.php';
require ABSPATH . WPINC . '/class-wp-block-type.php';
require ABSPATH . WPINC . '/class-wp-block-pattern-categories-registry.php';
require ABSPATH . WPINC . '/class-wp-block-patterns-registry.php';
require ABSPATH . WPINC . '/class-wp-block-styles-registry.php';
require ABSPATH . WPINC . '/class-wp-block-type-registry.php';
require ABSPATH . WPINC . '/class-wp-block.php';
require ABSPATH . WPINC . '/class-wp-block-list.php';
require ABSPATH . WPINC . '/class-wp-block-metadata-registry.php';
require ABSPATH . WPINC . '/class-wp-block-parser-block.php';
require ABSPATH . WPINC . '/class-wp-block-parser-frame.php';
require ABSPATH . WPINC . '/class-wp-block-parser.php';
require ABSPATH . WPINC . '/class-wp-classic-to-block-menu-converter.php';
require ABSPATH . WPINC . '/class-wp-navigation-fallback.php';
require ABSPATH . WPINC . '/block-bindings.php';
require ABSPATH . WPINC . '/block-bindings/pattern-overrides.php';
require ABSPATH . WPINC . '/block-bindings/post-meta.php';
require ABSPATH . WPINC . '/blocks.php';
require ABSPATH . WPINC . '/blocks/index.php';
require ABSPATH . WPINC . '/block-editor.php';
require ABSPATH . WPINC . '/block-patterns.php';
require ABSPATH . WPINC . '/class-wp-block-supports.php';
require ABSPATH . WPINC . '/block-supports/utils.php';
require ABSPATH . WPINC . '/block-supports/align.php';
require ABSPATH . WPINC . '/block-supports/custom-classname.php';
require ABSPATH . WPINC . '/block-supports/generated-classname.php';
require ABSPATH . WPINC . '/block-supports/settings.php';
require ABSPATH . WPINC . '/block-supports/elements.php';
require ABSPATH . WPINC . '/block-supports/colors.php';
require ABSPATH . WPINC . '/block-supports/typography.php';
require ABSPATH . WPINC . '/block-supports/border.php';
require ABSPATH . WPINC . '/block-supports/layout.php';
require ABSPATH . WPINC . '/block-supports/position.php';
require ABSPATH . WPINC . '/block-supports/spacing.php';
require ABSPATH . WPINC . '/block-supports/dimensions.php';
require ABSPATH . WPINC . '/block-supports/duotone.php';
require ABSPATH . WPINC . '/block-supports/shadow.php';
require ABSPATH . WPINC . '/block-supports/background.php';
require ABSPATH . WPINC . '/block-supports/block-style-variations.php';
require ABSPATH . WPINC . '/style-engine.php';
require ABSPATH . WPINC . '/style-engine/class-wp-style-engine.php';
require ABSPATH . WPINC . '/style-engine/class-wp-style-engine-css-declarations.php';
require ABSPATH . WPINC . '/style-engine/class-wp-style-engine-css-rule.php';
require ABSPATH . WPINC . '/style-engine/class-wp-style-engine-css-rules-store.php';
require ABSPATH . WPINC . '/style-engine/class-wp-style-engine-processor.php';
require ABSPATH . WPINC . '/fonts/class-wp-font-face-resolver.php';
require ABSPATH . WPINC . '/fonts/class-wp-font-collection.php';
require ABSPATH . WPINC . '/fonts/class-wp-font-face.php';
require ABSPATH . WPINC . '/fonts/class-wp-font-library.php';
require ABSPATH . WPINC . '/fonts/class-wp-font-utils.php';
require ABSPATH . WPINC . '/fonts.php';
require ABSPATH . WPINC . '/class-wp-script-modules.php';
require ABSPATH . WPINC . '/script-modules.php';
// require ABSPATH . WPINC . '/interactivity-api/class-wp-interactivity-api.php';
// require ABSPATH . WPINC . '/interactivity-api/class-wp-interactivity-api-directives-processor.php';
// require ABSPATH . WPINC . '/interactivity-api/interactivity-api.php';
require ABSPATH . WPINC . '/class-wp-plugin-dependencies.php';

my_wp_plugin_directory_constants();

$GLOBALS['wp_plugin_paths'] = [];

my_wp_cookie_constants();

require ABSPATH . WPINC . '/pluggable.php';
require ABSPATH . WPINC . '/pluggable-deprecated.php';

require_once ABSPATH . '/wp-admin/includes/admin.php';

// Use admin as current user to avoid permission problem
wp_set_current_user(1);