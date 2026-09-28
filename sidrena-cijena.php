<?php
/**
 * Plugin Name: Sidrena Cijena i Cjenik | Matija Gračanin
 * Description: Prikaz sidrene cijene i javni strojno čitljivi cjenik za WooCommerce prema odlukama NN 101/2026.
 * Version: 1.4.0
 * Requires at least: 6.0
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 9.4
 * Author: Matija Gračanin
 * Author URI: mailto:matijag@gmail.com
 * Author Email: matijag@gmail.com
 * Text Domain: sidrena-cijena
 * Requires Plugins: woocommerce
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

/*
 * Portions of the original GPLv2-or-later implementation were published by
 * Aplitap digital. Later architecture and features are by Matija Gračanin.
 * See NOTICE.md for provenance and modification details.
 */

use SidrenaCijenaCjenik\Config;

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'src/Config.php';
Config::registerLegacyConstants();
require_once plugin_dir_path(__FILE__) . 'includes/cjenik.php';

/**
 * This extension only manages products and public price-list files. It does not
 * read or write orders and does not alter the Cart or Checkout flow.
 */
add_action('before_woocommerce_init', 'sidrena_cijena_declare_wc_compatibility');
function sidrena_cijena_declare_wc_compatibility() {
    if (!class_exists('\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
        return;
    }

    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
}

/**
 * Regular price used to seed an empty anchor. The anchor is the price without a
 * special form of sale, so the active (possibly discounted) price is not used.
 */
function sidrena_cijena_get_reference_price($product) {
    if (!$product || !is_a($product, 'WC_Product')) {
        return '';
    }

    $price = $product->get_regular_price('edit');
    if ($price === '' || $price === null) {
        return '';
    }

    return wc_format_decimal($price);
}

/**
 * Returns the value as Y-m-d when it is a valid calendar date, otherwise ''.
 */
function sidrena_cijena_parse_date($value) {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return '';
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, wp_timezone());
    return $date && $date->format('Y-m-d') === $value ? $value : '';
}

function sidrena_cijena_product_created_date($product) {
    $created = $product->get_date_created();
    return $created ? $created->date_i18n('Y-m-d') : '';
}

/**
 * Date of an anchor snapshotted now. Products that existed on the reference
 * date are anchored to it; newer products are anchored to the snapshot day.
 * New and draft products have no creation date yet, so they count as newer.
 */
function sidrena_cijena_initial_anchor_date($product) {
    $created = sidrena_cijena_product_created_date($product);
    if ($created !== '' && $created <= Config::REFERENCE_DATE_ISO) {
        return Config::REFERENCE_DATE_ISO;
    }

    return current_time('Y-m-d');
}

/**
 * Date the product's anchor price refers to. Anchors stored before dates were
 * tracked were all shown as the reference date, so they keep it.
 */
function sidrena_cijena_get_anchor_date($product) {
    $stored = sidrena_cijena_parse_date($product->get_meta(Config::ANCHOR_DATE_META_KEY, true));
    if ($stored !== '') {
        return $stored;
    }

    if ($product->get_meta(Config::ANCHOR_META_KEY, true) === '') {
        return sidrena_cijena_initial_anchor_date($product);
    }

    return Config::REFERENCE_DATE_ISO;
}

/**
 * Saves a submitted anchor. An empty price is seeded from the regular price and
 * an empty or invalid date from sidrena_cijena_initial_anchor_date().
 */
function sidrena_cijena_save_anchor_input($product, $raw_price, $raw_date) {
    $product_id = $product->get_id();
    $price = $raw_price === '' ? sidrena_cijena_get_reference_price($product) : wc_format_decimal($raw_price);

    if ($price === '') {
        delete_post_meta($product_id, Config::ANCHOR_META_KEY);
        delete_post_meta($product_id, Config::ANCHOR_DATE_META_KEY);
        return;
    }

    $date = sidrena_cijena_parse_date($raw_date);
    if ($date === '') {
        $date = sidrena_cijena_initial_anchor_date($product);
    }

    update_post_meta($product_id, Config::ANCHOR_META_KEY, $price);
    update_post_meta($product_id, Config::ANCHOR_DATE_META_KEY, $date);
}

/**
 * Replaces %X in the label with the anchor date formatted by the PHP date
 * character X (only the date characters in Config::LABEL_DATE_TOKENS); %%
 * is a literal percent sign.
 */
function sidrena_cijena_format_label($label, $date) {
    $timestamp = sidrena_cijena_date_timestamp($date);

    return preg_replace_callback('/%([%a-zA-Z])/', function ($match) use ($timestamp) {
        if ($match[1] === '%') {
            return '%';
        }
        if ($timestamp === null || strpos(Config::LABEL_DATE_TOKENS, $match[1]) === false) {
            return $match[0];
        }
        return wp_date($match[1], $timestamp);
    }, $label);
}

function sidrena_cijena_date_timestamp($date) {
    $date = sidrena_cijena_parse_date($date);
    if ($date === '') {
        return null;
    }

    return DateTimeImmutable::createFromFormat('!Y-m-d', $date, wp_timezone())->getTimestamp();
}

/**
 * Every supported label token formatted for one date, for the admin preview.
 */
function sidrena_cijena_label_token_values($date) {
    $timestamp = sidrena_cijena_date_timestamp($date);
    $values = array();
    foreach (str_split(Config::LABEL_DATE_TOKENS) as $token) {
        $values[$token] = wp_date($token, $timestamp);
    }

    return $values;
}

/**
 * Older versions stored the label with the reference date written out. Turn
 * that date into placeholders so each product shows its own anchor date.
 */
add_action('plugins_loaded', 'sidrena_cijena_maybe_upgrade');
function sidrena_cijena_maybe_upgrade() {
    if ((int) get_option(Config::SCHEMA_VERSION_OPTION, 1) >= Config::SCHEMA_VERSION) {
        return;
    }

    $label = get_option(Config::LABEL_OPTION, '');
    if (is_string($label) && $label !== '') {
        $label = str_replace('%', '%%', $label);
        $label = str_replace(array('10.09.2026', '10.9.2026'), array('%d.%m.%Y', '%j.%n.%Y'), $label);
        update_option(Config::LABEL_OPTION, $label);
    }

    update_option(Config::SCHEMA_VERSION_OPTION, Config::SCHEMA_VERSION);
}

/**
 * Stores a snapshot of the current regular price only when no anchor exists.
 */
function sidrena_cijena_initialize_product_anchor($product_id) {
    if (!function_exists('wc_get_product')) {
        return 0;
    }

    $product = wc_get_product($product_id);
    if (!$product || $product->get_status() === 'trash') {
        return 0;
    }

    $targets = $product->is_type('variable') ? $product->get_children() : array($product_id);
    $initialized = 0;

    foreach ($targets as $target_id) {
        $target = wc_get_product($target_id);
        if (!$target || $target->get_status() === 'trash' || $target->get_meta(Config::ANCHOR_META_KEY, true) !== '') {
            continue;
        }

        $current_price = sidrena_cijena_get_reference_price($target);
        if ($current_price === '') {
            continue;
        }

        update_post_meta($target_id, Config::ANCHOR_META_KEY, $current_price);
        update_post_meta($target_id, Config::ANCHOR_DATE_META_KEY, sidrena_cijena_initial_anchor_date($target));
        $initialized++;
    }

    return $initialized;
}

add_action('woocommerce_update_product', 'sidrena_cijena_initialize_anchor_after_product_update', 5, 1);
add_action('woocommerce_update_product_variation', 'sidrena_cijena_initialize_anchor_after_product_update', 5, 1);
function sidrena_cijena_initialize_anchor_after_product_update($product_id) {
    if (sidrena_cijena_initialize_product_anchor($product_id) > 0 && function_exists('cjenik_queue_refresh')) {
        cjenik_queue_refresh();
    }
}

add_action('admin_init', 'sidrena_cijena_maybe_initialize_existing_anchors');
function sidrena_cijena_maybe_initialize_existing_anchors() {
    if (get_option(Config::AUTO_INITIALIZED_OPTION) === 'yes'
        || !current_user_can('manage_woocommerce')
        || !function_exists('wc_get_products')) {
        return;
    }

    $initialized = sidrena_cijena_fill_missing_from_current_prices();
    update_option(Config::AUTO_INITIALIZED_OPTION, 'yes', false);
    set_transient('sidrena_cijena_initialized_notice_' . get_current_user_id(), $initialized, 5 * MINUTE_IN_SECONDS);
}

add_action('admin_notices', 'sidrena_cijena_initialized_notice');
function sidrena_cijena_initialized_notice() {
    $transient_key = 'sidrena_cijena_initialized_notice_' . get_current_user_id();
    $initialized = get_transient($transient_key);
    if ($initialized === false) {
        return;
    }

    delete_transient($transient_key);
    printf(
        '<div class="notice notice-info is-dismissible"><p>%s</p></div>',
        esc_html(sprintf(__('Sidrena cijena: redovna cijena početno je spremljena za %d proizvoda ili varijacija. Postojeće vrijednosti nisu promijenjene.', 'sidrena-cijena'), (int) $initialized))
    );
}

function sidrena_cijena_font_family_choices() {
    return array(
        'inherit'                              => __('Naslijeđeno od teme (preporučeno)', 'sidrena-cijena'),
        'Arial, Helvetica, sans-serif'          => 'Arial',
        'Georgia, serif'                        => 'Georgia',
        'Verdana, sans-serif'                   => 'Verdana',
        "'Courier New', Courier, monospace"     => 'Courier New',
        "'Times New Roman', Times, serif"       => 'Times New Roman',
    );
}

function sidrena_cijena_font_weight_choices() {
    return array(
        'normal' => __('Normalno', 'sidrena-cijena'),
        'bold'   => __('Podebljano (bold)', 'sidrena-cijena'),
    );
}

function sidrena_cijena_font_style_choices() {
    return array(
        'normal' => __('Normalno', 'sidrena-cijena'),
        'italic' => __('Kurziv (italic)', 'sidrena-cijena'),
    );
}

add_action('plugins_loaded', 'sidrena_cijena_check_woocommerce');
function sidrena_cijena_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'sidrena_cijena_missing_woocommerce_notice');
    }
}

function sidrena_cijena_missing_woocommerce_notice() {
    echo '<div class="notice notice-error"><p>' . esc_html__('Dodatak "Sidrena Cijena" zahtijeva aktivan WooCommerce.', 'sidrena-cijena') . '</p></div>';
}

// 1. Polje na stranici za uređivanje proizvoda (tab "General", odmah ispod redovne/akcijske cijene)
add_action('woocommerce_product_options_pricing', 'sidrena_cijena_add_field');
function sidrena_cijena_add_field() {
    global $product_object;

    woocommerce_wp_text_input(array(
        'id'          => Config::ANCHOR_META_KEY,
        'label'       => sprintf(
            /* translators: %s: currency symbol */
            __('Sidrena cijena (%s)', 'sidrena-cijena'),
            get_woocommerce_currency_symbol()
        ),
        'desc_tip'    => true,
        'description' => __('Ako polje ostane prazno, dodatak će pri spremanju početno kopirati trenutačnu redovnu cijenu (bez akcijskog sniženja). Vrijednost se nakon toga neće automatski mijenjati. Provjerite iznos prema vlastitoj evidenciji.', 'sidrena-cijena'),
        'data_type'   => 'price',
    ));

    woocommerce_wp_text_input(array(
        'id'          => Config::ANCHOR_DATE_META_KEY,
        'label'       => __('Datum sidrene cijene', 'sidrena-cijena'),
        'type'        => 'date',
        'value'       => $product_object instanceof WC_Product ? sidrena_cijena_get_anchor_date($product_object) : '',
        'desc_tip'    => true,
        'description' => sprintf(
            /* translators: %s: reference date */
            __('Dan na koji je vrijedila sidrena cijena. Za proizvode koji su postojali %s to je taj dan, a za novije proizvode dan početnog spremanja cijene. Ostavite prazno za automatski odabir.', 'sidrena-cijena'),
            Config::REFERENCE_DATE
        ),
    ));
}

// 2. Spremanje vrijednosti
add_action('woocommerce_process_product_meta', 'sidrena_cijena_save_field');
function sidrena_cijena_save_field($post_id) {
    if (!current_user_can('edit_post', $post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
        return;
    }

    $product = wc_get_product($post_id);
    if (!$product || $product->is_type('variable')) {
        // The pricing group is only hidden for variable products, so its empty
        // field is still submitted. Anchors live on the variations instead.
        return;
    }

    if (isset($_POST[Config::ANCHOR_META_KEY])) {
        sidrena_cijena_save_anchor_input(
            $product,
            sanitize_text_field(wp_unslash($_POST[Config::ANCHOR_META_KEY])),
            isset($_POST[Config::ANCHOR_DATE_META_KEY]) ? sanitize_text_field(wp_unslash($_POST[Config::ANCHOR_DATE_META_KEY])) : ''
        );
    }
}

// 2b. Podrška za Quick Edit (brzo uređivanje iz popisa proizvoda)
add_action('woocommerce_product_quick_edit_end', 'sidrena_cijena_quick_edit_field');
function sidrena_cijena_quick_edit_field() {
    ?>
    <div class="inline-edit-group sidrena-cijena-quick-edit-row" style="clear:both;display:block;width:100%;float:none;">
        <label class="alignleft" style="width:100%;">
            <span class="title"><?php echo esc_html__('Sidrena cijena', 'sidrena-cijena'); ?></span>
            <span class="input-text-wrap">
                <input type="text" name="<?php echo esc_attr(Config::ANCHOR_META_KEY); ?>" class="text sidrena_cijena_quick_edit_field" value="" />
            </span>
        </label>
        <label class="alignleft" style="width:100%;">
            <span class="title"><?php echo esc_html__('Datum sidrene cijene', 'sidrena-cijena'); ?></span>
            <span class="input-text-wrap">
                <input type="date" name="<?php echo esc_attr(Config::ANCHOR_DATE_META_KEY); ?>" class="text sidrena_cijena_quick_edit_date_field" value="" />
            </span>
        </label>
    </div>
    <?php
}

add_action('manage_product_posts_custom_column', 'sidrena_cijena_output_hidden_value', 20, 2);
function sidrena_cijena_output_hidden_value($column, $post_id) {
    if ($column === 'price') {
        $product = wc_get_product($post_id);
        $value = $product ? $product->get_meta(Config::ANCHOR_META_KEY, true) : '';
        $date = $product ? sidrena_cijena_get_anchor_date($product) : '';
        echo '<div class="sidrena_cijena_hidden_value" style="display:none;">' . esc_html($value) . '</div>';
        echo '<div class="sidrena_cijena_hidden_date" style="display:none;">' . esc_html($date) . '</div>';
    }
}

add_action('woocommerce_product_quick_edit_save', 'sidrena_cijena_quick_edit_save');
function sidrena_cijena_quick_edit_save($product) {
    if (!current_user_can('edit_post', $product->get_id()) || $product->is_type('variable')) {
        return;
    }

    if (isset($_POST[Config::ANCHOR_META_KEY])) {
        sidrena_cijena_save_anchor_input(
            $product,
            sanitize_text_field(wp_unslash($_POST[Config::ANCHOR_META_KEY])),
            isset($_POST[Config::ANCHOR_DATE_META_KEY]) ? sanitize_text_field(wp_unslash($_POST[Config::ANCHOR_DATE_META_KEY])) : ''
        );
    }
}

add_action('admin_enqueue_scripts', 'sidrena_cijena_quick_edit_script');
function sidrena_cijena_quick_edit_script($hook) {
    global $post_type;

    if ($hook !== 'edit.php' || $post_type !== 'product') {
        return;
    }

    $js = "
        (function($){
            if (typeof inlineEditPost === 'undefined') { return; }
            var sidrena_cijena_wc_inline_edit = inlineEditPost.edit;
            inlineEditPost.edit = function(id) {
                sidrena_cijena_wc_inline_edit.apply(this, arguments);
                var postId = 0;
                if (typeof(id) === 'object') {
                    postId = parseInt(this.getId(id), 10);
                } else {
                    postId = parseInt(id, 10);
                }
                if (postId > 0) {
                    var \$row = $('#post-' + postId);
                    var value = \$row.find('.sidrena_cijena_hidden_value').first().text();
                    $('input.sidrena_cijena_quick_edit_field').val(value);
                    $('input.sidrena_cijena_quick_edit_date_field').val(\$row.find('.sidrena_cijena_hidden_date').first().text());

                    var \$saleLabel = $('input[name=\"_sale_price\"]').closest('label');
                    var \$ourField = $('.sidrena-cijena-quick-edit-row');
                    if (\$saleLabel.length && \$ourField.length) {
                        \$saleLabel.after(\$ourField);
                    }
                    var productType = $('#woocommerce_inline_' + postId).find('.product_type').text();
                    \$ourField.toggle(productType !== 'variable');
                }
            };
        })(jQuery);
    ";

    wp_add_inline_script('inline-edit-post', $js);
}

// 3. Prikaz ispod cijene - pokriva stranicu proizvoda, kategorije/shop, related/upsell/cross-sell, widgete itd.
function sidrena_cijena_get_style_settings() {
    $label = get_option(Config::LABEL_OPTION, Config::DEFAULT_LABEL);
    if ($label === '') {
        $label = Config::DEFAULT_LABEL;
    }

    $font_size = (int) get_option(Config::FONT_SIZE_OPTION, Config::DEFAULT_FONT_SIZE);
    if ($font_size < 8) {
        $font_size = 8;
    } elseif ($font_size > 32) {
        $font_size = 32;
    }

    $font_size_mobile = (int) get_option(Config::MOBILE_FONT_SIZE_OPTION, Config::DEFAULT_MOBILE_FONT_SIZE);
    if ($font_size_mobile < 8) {
        $font_size_mobile = 8;
    } elseif ($font_size_mobile > 32) {
        $font_size_mobile = 32;
    }

    $font_family = get_option(Config::FONT_FAMILY_OPTION, Config::DEFAULT_FONT_FAMILY);
    if (!array_key_exists($font_family, sidrena_cijena_font_family_choices())) {
        $font_family = Config::DEFAULT_FONT_FAMILY;
    }

    $font_weight = get_option(Config::FONT_WEIGHT_OPTION, Config::DEFAULT_FONT_WEIGHT);
    if (!array_key_exists($font_weight, sidrena_cijena_font_weight_choices())) {
        $font_weight = Config::DEFAULT_FONT_WEIGHT;
    }

    $font_style = get_option(Config::FONT_STYLE_OPTION, Config::DEFAULT_FONT_STYLE);
    if (!array_key_exists($font_style, sidrena_cijena_font_style_choices())) {
        $font_style = Config::DEFAULT_FONT_STYLE;
    }

    $color = get_option(Config::COLOR_OPTION, Config::DEFAULT_COLOR);
    $sanitized_color = sanitize_hex_color($color);
    if (!$sanitized_color) {
        $sanitized_color = Config::DEFAULT_COLOR;
    }

    return array(
        'label'             => $label,
        'font_size'         => $font_size,
        'font_size_mobile'  => $font_size_mobile,
        'font_family'       => $font_family,
        'font_weight'       => $font_weight,
        'font_style'        => $font_style,
        'color'             => $sanitized_color,
    );
}

function sidrena_cijena_build_line($anchor_price, $anchor_date) {
    return sidrena_cijena_build_formatted_line(wc_price($anchor_price), $anchor_date);
}

function sidrena_cijena_build_formatted_line($formatted_price, $anchor_date) {
    $s = sidrena_cijena_get_style_settings();

    return sprintf(
        '<span class="sidrena-cijena">%s: %s</span>',
        esc_html(sidrena_cijena_format_label($s['label'], $anchor_date)),
        wp_kses_post($formatted_price)
    );
}

add_action('wp_head', 'sidrena_cijena_output_css');
function sidrena_cijena_output_css() {
    if (get_option(Config::DISPLAY_ENABLED_OPTION, 'yes') !== 'yes') {
        return;
    }

    $s = sidrena_cijena_get_style_settings();
    $breakpoint = (int) Config::MOBILE_BREAKPOINT;
    ?>
    <style id="sidrena-cijena-css">
        .sidrena-cijena {
            display: block;
            font-size: <?php echo (int) $s['font_size']; ?>px;
            font-family: <?php echo $s['font_family']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted value. ?>;
            font-weight: <?php echo $s['font_weight']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted value. ?>;
            font-style: <?php echo $s['font_style']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted value. ?>;
            color: <?php echo $s['color']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitize_hex_color. ?>;
        }
        @media (max-width: <?php echo $breakpoint; ?>px) {
            .sidrena-cijena {
                font-size: <?php echo (int) $s['font_size_mobile']; ?>px;
            }
        }
    </style>
    <?php
}

add_filter('woocommerce_get_price_html', 'sidrena_cijena_append_to_price_html', 10, 2);
/**
 * Minimum and maximum anchor of the published variations, plus their shared
 * anchor date. The date is '' when the variations were anchored on different
 * days, since one label cannot state them all.
 */
function sidrena_cijena_variable_anchor_range($product) {
    $cache_key = 'anchor_range_v2_' . $product->get_id();
    $cached = wp_cache_get($cache_key, 'sidrena_cijena');
    if (is_array($cached)) {
        return $cached;
    }

    $prices = array();
    $dates = array();
    foreach ($product->get_children() as $variation_id) {
        $variation = wc_get_product($variation_id);
        if (!$variation || $variation->get_status() !== 'publish') {
            continue;
        }
        $value = $variation->get_meta(Config::ANCHOR_META_KEY, true);
        if ($value === '') {
            $value = sidrena_cijena_get_reference_price($variation);
        }
        if ($value !== '') {
            $prices[] = (float) wc_format_decimal($value);
            $dates[sidrena_cijena_get_anchor_date($variation)] = true;
        }
    }

    $range = $prices ? array(min($prices), max($prices), count($dates) === 1 ? key($dates) : '') : array();
    wp_cache_set($cache_key, $range, 'sidrena_cijena', HOUR_IN_SECONDS);
    return $range;
}

add_action('woocommerce_update_product', 'sidrena_cijena_clear_anchor_range_cache', 20, 1);
add_action('woocommerce_update_product_variation', 'sidrena_cijena_clear_anchor_range_cache', 20, 1);
function sidrena_cijena_clear_anchor_range_cache($product_id) {
    wp_cache_delete('anchor_range_v2_' . (int) $product_id, 'sidrena_cijena');
    $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
    if ($product && $product->get_parent_id()) {
        wp_cache_delete('anchor_range_v2_' . $product->get_parent_id(), 'sidrena_cijena');
    }
}

function sidrena_cijena_append_to_price_html($price_html, $product) {
    if (empty($price_html)) {
        return $price_html;
    }

    if (get_option(Config::DISPLAY_ENABLED_OPTION, 'yes') !== 'yes') {
        return $price_html;
    }

    $anchor_price = $product->get_meta(Config::ANCHOR_META_KEY, true);

    if (($anchor_price === '' || $anchor_price === null) && $product->is_type('variable')) {
        $range = sidrena_cijena_variable_anchor_range($product);
        if ($range) {
            list($minimum, $maximum, $anchor_date) = $range;
            if ($anchor_date === '') {
                // Each variation shows its own dated line once it is selected.
                return $price_html;
            }
            $formatted = wc_price($minimum);
            if ($maximum > $minimum) {
                $formatted .= ' &ndash; ' . wc_price($maximum);
            }
            return $price_html . sidrena_cijena_build_formatted_line($formatted, $anchor_date);
        }
    }

    if ($anchor_price === '' || $anchor_price === null) {
        $anchor_price = sidrena_cijena_get_reference_price($product);
        if ($anchor_price === '') {
            return $price_html;
        }
    }

    return $price_html . sidrena_cijena_build_line($anchor_price, sidrena_cijena_get_anchor_date($product));
}

// 3b. Varijabilni proizvodi - polje po varijaciji (npr. veličina/boja), i prikaz kad kupac odabere varijaciju
add_action('woocommerce_product_after_variable_attributes', 'sidrena_cijena_variation_field', 10, 3);
function sidrena_cijena_variation_field($loop, $variation_data, $variation) {
    $variation_product = wc_get_product($variation->ID);
    $value = get_post_meta($variation->ID, Config::ANCHOR_META_KEY, true);
    $date = $variation_product ? sidrena_cijena_get_anchor_date($variation_product) : '';
    ?>
    <p class="form-row form-row-first">
        <label>
            <?php
            printf(
                /* translators: %s: currency symbol */
                esc_html__('Sidrena cijena (%s)', 'sidrena-cijena'),
                esc_html(get_woocommerce_currency_symbol())
            );
            ?>
        </label>
        <input
            type="text"
            name="variable_anchor_price[<?php echo esc_attr($loop); ?>]"
            class="wc_input_price"
            value="<?php echo esc_attr($value); ?>"
            placeholder="<?php echo esc_attr__('Prazno = kopiraj redovnu cijenu', 'sidrena-cijena'); ?>"
        />
    </p>
    <p class="form-row form-row-last">
        <label><?php echo esc_html__('Datum sidrene cijene', 'sidrena-cijena'); ?></label>
        <input
            type="date"
            name="variable_anchor_price_date[<?php echo esc_attr($loop); ?>]"
            value="<?php echo esc_attr($date); ?>"
        />
    </p>
    <?php
}

add_action('woocommerce_save_product_variation', 'sidrena_cijena_save_variation_field', 10, 2);
function sidrena_cijena_save_variation_field($variation_id, $i) {
    if (!current_user_can('edit_post', $variation_id)) {
        return;
    }

    $variation = wc_get_product($variation_id);
    if ($variation && isset($_POST['variable_anchor_price'][$i])) {
        sidrena_cijena_save_anchor_input(
            $variation,
            sanitize_text_field(wp_unslash($_POST['variable_anchor_price'][$i])),
            isset($_POST['variable_anchor_price_date'][$i]) ? sanitize_text_field(wp_unslash($_POST['variable_anchor_price_date'][$i])) : ''
        );
    }
}

add_filter('woocommerce_available_variation', 'sidrena_cijena_variation_price_html', 10, 3);
function sidrena_cijena_variation_price_html($variation_data, $product, $variation) {
    if (empty($variation_data['price_html'])) {
        return $variation_data;
    }

    if (get_option(Config::DISPLAY_ENABLED_OPTION, 'yes') !== 'yes') {
        return $variation_data;
    }

    $anchor_price = $variation->get_meta(Config::ANCHOR_META_KEY, true);

    if ($anchor_price === '' || $anchor_price === null) {
        $anchor_price = sidrena_cijena_get_reference_price($variation);
        if ($anchor_price === '') {
            return $variation_data;
        }
    }

    if (strpos($variation_data['price_html'], 'class="sidrena-cijena"') === false) {
        $variation_data['price_html'] .= sidrena_cijena_build_line($anchor_price, sidrena_cijena_get_anchor_date($variation));
    }

    return $variation_data;
}

// 4. Vlastita stavka u admin izborniku s postavkama dodatka
function sidrena_cijena_menu_icon() {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" stroke="black" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="10" cy="4" r="2"/><line x1="10" y1="6" x2="10" y2="17"/><line x1="5" y1="8" x2="15" y2="8"/><path d="M5 13 a5 5 0 0 0 10 0"/></svg>';

    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

add_action('admin_menu', 'sidrena_cijena_add_menu');
function sidrena_cijena_add_menu() {
    $hook = add_menu_page(
        __('Sidrena cijena', 'sidrena-cijena'),
        __('Sidrena cijena', 'sidrena-cijena'),
        'manage_woocommerce',
        'sidrena-cijena',
        'sidrena_cijena_render_settings_page',
        sidrena_cijena_menu_icon(),
        56
    );

    add_action('admin_enqueue_scripts', function ($current_hook) use ($hook) {
        if ($current_hook !== $hook) {
            return;
        }

        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        wp_add_inline_script(
            'wp-color-picker',
            'var sidrenaCijenaLabel = ' . wp_json_encode(array(
                'defaultLabel' => Config::DEFAULT_LABEL,
                'tokens'       => sidrena_cijena_label_token_values(Config::REFERENCE_DATE_ISO),
            )) . ';',
            'before'
        );

        $js = "
            (function($){
                $('.sidrena-cijena-color-field').wpColorPicker({ change: sidrena_cijena_update_preview, clear: sidrena_cijena_update_preview });

                function sidrena_cijena_preview_label(label) {
                    return label.replace(/%([%a-zA-Z])/g, function(match, token) {
                        if (token === '%') { return '%'; }
                        return Object.prototype.hasOwnProperty.call(sidrenaCijenaLabel.tokens, token) ? sidrenaCijenaLabel.tokens[token] : match;
                    });
                }

                function sidrena_cijena_update_preview() {
                    setTimeout(function() {
                        var label = sidrena_cijena_preview_label($('#sidrena-cijena-label').val() || sidrenaCijenaLabel.defaultLabel);
                        var size = $('#sidrena-cijena-font-size').val() || 12;
                        var sizeMobile = $('#sidrena-cijena-font-size-mobile').val() || 11;
                        var family = $('#sidrena-cijena-font-family').val() || 'inherit';
                        var weight = $('#sidrena-cijena-font-weight').val() || 'normal';
                        var style = $('#sidrena-cijena-font-style').val() || 'normal';
                        var color = $('.sidrena-cijena-color-field').val() || '#666666';

                        var baseCss = {
                            'font-family': family,
                            'font-weight': weight,
                            'font-style': style,
                            'color': color
                        };

                        $('#sidrena-cijena-label-preview').text(label);
                        $('#sidrena-cijena-preview').css($.extend({ 'font-size': size + 'px' }, baseCss)).text(label + ': 199,00 €');
                        $('#sidrena-cijena-preview-mobile').css($.extend({ 'font-size': sizeMobile + 'px' }, baseCss)).text(label + ': 199,00 €');
                    }, 10);
                }

                $('#sidrena-cijena-label, #sidrena-cijena-font-size, #sidrena-cijena-font-size-mobile, #sidrena-cijena-font-family, #sidrena-cijena-font-weight, #sidrena-cijena-font-style').on('input change', sidrena_cijena_update_preview);

                sidrena_cijena_update_preview();
            })(jQuery);
        ";

        wp_add_inline_script('wp-color-picker', $js);
    });
}

function sidrena_cijena_render_settings_page() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $tabs = array(
        'sidrena-cijena' => __('Sidrena cijena', 'sidrena-cijena'),
        'cjenik'         => __('Cjenik', 'sidrena-cijena'),
        'pomoc'           => __('Pomoć', 'sidrena-cijena'),
    );

    $requested_tab = isset($_GET['tab']) && is_string($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
    $active_tab = array_key_exists($requested_tab, $tabs) ? $requested_tab : 'sidrena-cijena';
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('Sidrena cijena i cjenik', 'sidrena-cijena'); ?></h1>
        <nav class="nav-tab-wrapper" style="margin-bottom:20px;">
            <?php foreach ($tabs as $tab_slug => $tab_label) : ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=sidrena-cijena&tab=' . $tab_slug)); ?>" class="nav-tab <?php echo $active_tab === $tab_slug ? 'nav-tab-active' : ''; ?>">
                    <?php echo esc_html($tab_label); ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php
        if ($active_tab === 'cjenik') {
            cjenik_render_tab_content();
        } elseif ($active_tab === 'pomoc') {
            sidrena_cijena_render_help_tab();
        } else {
            sidrena_cijena_render_tab_content();
        }
        ?>
    </div>
    <?php
}

function sidrena_cijena_render_tab_content() {
    $tab_url = admin_url('admin.php?page=sidrena-cijena&tab=sidrena-cijena');

    if (isset($_POST['sidrena_cijena_fill_missing']) && check_admin_referer('sidrena_cijena_fill_missing_action', 'sidrena_cijena_fill_missing_nonce')) {
        if (empty($_POST['sidrena_cijena_fill_confirm'])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Prije pokretanja označite potvrdu da ćete provjeriti predložene iznose.', 'sidrena-cijena') . '</p></div>';
        } else {
            $filled = sidrena_cijena_fill_missing_from_current_prices();
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html(sprintf(__('Popunjeno je %d praznih sidrenih cijena. Postojeće vrijednosti nisu promijenjene.', 'sidrena-cijena'), $filled))
            );
        }
    }

    if (isset($_POST['sidrena_cijena_save']) && check_admin_referer('sidrena_cijena_settings_save', 'sidrena_cijena_nonce')) {
        $enabled = isset($_POST[Config::DISPLAY_ENABLED_OPTION]) ? 'yes' : 'no';
        update_option(Config::DISPLAY_ENABLED_OPTION, $enabled);

        // sanitize_text_field() would strip placeholders that look like
        // percent-encoded octets, such as %d followed by a hex digit.
        $label = isset($_POST[Config::LABEL_OPTION]) ? trim(preg_replace('/\s+/', ' ', wp_strip_all_tags(wp_unslash($_POST[Config::LABEL_OPTION])))) : '';
        if ($label === '') {
            $label = Config::DEFAULT_LABEL;
        }
        update_option(Config::LABEL_OPTION, $label);

        $font_size = isset($_POST[Config::FONT_SIZE_OPTION]) ? (int) $_POST[Config::FONT_SIZE_OPTION] : Config::DEFAULT_FONT_SIZE;
        if ($font_size < 8) {
            $font_size = 8;
        } elseif ($font_size > 32) {
            $font_size = 32;
        }
        update_option(Config::FONT_SIZE_OPTION, $font_size);

        $font_size_mobile = isset($_POST[Config::MOBILE_FONT_SIZE_OPTION]) ? (int) $_POST[Config::MOBILE_FONT_SIZE_OPTION] : Config::DEFAULT_MOBILE_FONT_SIZE;
        if ($font_size_mobile < 8) {
            $font_size_mobile = 8;
        } elseif ($font_size_mobile > 32) {
            $font_size_mobile = 32;
        }
        update_option(Config::MOBILE_FONT_SIZE_OPTION, $font_size_mobile);

        $font_family = isset($_POST[Config::FONT_FAMILY_OPTION]) ? sanitize_text_field(wp_unslash($_POST[Config::FONT_FAMILY_OPTION])) : Config::DEFAULT_FONT_FAMILY;
        if (!array_key_exists($font_family, sidrena_cijena_font_family_choices())) {
            $font_family = Config::DEFAULT_FONT_FAMILY;
        }
        update_option(Config::FONT_FAMILY_OPTION, $font_family);

        $font_weight = isset($_POST[Config::FONT_WEIGHT_OPTION]) ? sanitize_text_field(wp_unslash($_POST[Config::FONT_WEIGHT_OPTION])) : Config::DEFAULT_FONT_WEIGHT;
        if (!array_key_exists($font_weight, sidrena_cijena_font_weight_choices())) {
            $font_weight = Config::DEFAULT_FONT_WEIGHT;
        }
        update_option(Config::FONT_WEIGHT_OPTION, $font_weight);

        $font_style = isset($_POST[Config::FONT_STYLE_OPTION]) ? sanitize_text_field(wp_unslash($_POST[Config::FONT_STYLE_OPTION])) : Config::DEFAULT_FONT_STYLE;
        if (!array_key_exists($font_style, sidrena_cijena_font_style_choices())) {
            $font_style = Config::DEFAULT_FONT_STYLE;
        }
        update_option(Config::FONT_STYLE_OPTION, $font_style);

        $color = isset($_POST[Config::COLOR_OPTION]) ? sanitize_hex_color(wp_unslash($_POST[Config::COLOR_OPTION])) : '';
        if (!$color) {
            $color = Config::DEFAULT_COLOR;
        }
        update_option(Config::COLOR_OPTION, $color);

        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Postavke spremljene.', 'sidrena-cijena') . '</p></div>';
    }

    $enabled = get_option(Config::DISPLAY_ENABLED_OPTION, 'yes');
    $s = sidrena_cijena_get_style_settings();
    $label = $s['label'];
    $font_size = $s['font_size'];
    $font_size_mobile = $s['font_size_mobile'];
    $font_family = $s['font_family'];
    $font_weight = $s['font_weight'];
    $font_style = $s['font_style'];
    $color = $s['color'];
    $anchor_status = sidrena_cijena_get_anchor_status();
    ?>
        <p><?php echo esc_html__('Upravljanje prikazom referentne maloprodajne cijene bez posebnog oblika prodaje: za proizvode koji su postojali 10.09.2026. to je cijena tog dana, a za novije proizvode prva cijena, uz datum koji se sprema na svakom proizvodu.', 'sidrena-cijena'); ?></p>
        <div style="display:flex;gap:12px;flex-wrap:wrap;margin:16px 0 20px;">
            <div style="background:#fff;border:1px solid #dcdcde;padding:14px 18px;min-width:170px;"><strong style="font-size:22px;display:block;"><?php echo (int) $anchor_status['total']; ?></strong><?php echo esc_html__('proizvoda i varijacija', 'sidrena-cijena'); ?></div>
            <div style="background:#fff;border:1px solid #dcdcde;padding:14px 18px;min-width:170px;"><strong style="font-size:22px;display:block;color:#008a20;"><?php echo (int) $anchor_status['set']; ?></strong><?php echo esc_html__('s unesenom cijenom', 'sidrena-cijena'); ?></div>
            <div style="background:#fff;border:1px solid #dcdcde;padding:14px 18px;min-width:170px;"><strong style="font-size:22px;display:block;color:<?php echo $anchor_status['missing'] ? '#b32d2e' : '#008a20'; ?>;"><?php echo (int) $anchor_status['missing']; ?></strong><?php echo esc_html__('bez sidrene cijene', 'sidrena-cijena'); ?></div>
        </div>
        <form method="post" action="<?php echo esc_url($tab_url); ?>">
            <?php wp_nonce_field('sidrena_cijena_settings_save', 'sidrena_cijena_nonce'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php echo esc_html__('Prikaz sidrene cijene', 'sidrena-cijena'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr(Config::DISPLAY_ENABLED_OPTION); ?>" value="1" <?php checked($enabled, 'yes'); ?> />
                            <?php echo esc_html__('Prikaži sidrenu cijenu uz trenutačnu cijenu na cijeloj web stranici', 'sidrena-cijena'); ?>
                        </label>
                        <p class="description">
                            <?php
                            echo esc_html__('Kad je uključeno, sidrena cijena prikazuje se ispod redovne cijene svugdje gdje WooCommerce prikazuje cijenu proizvoda. Iznos i datum unosite po proizvodu u Products → uredi proizvod → tab General.', 'sidrena-cijena');
                            ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="sidrena-cijena-label"><?php echo esc_html__('Tekst oznake', 'sidrena-cijena'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="sidrena-cijena-label" name="<?php echo esc_attr(Config::LABEL_OPTION); ?>" value="<?php echo esc_attr($label); ?>" class="regular-text" />
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: reference date */
                                esc_html__('Pregled za sidrenu cijenu od %s:', 'sidrena-cijena'),
                                esc_html(Config::REFERENCE_DATE)
                            );
                            ?>
                            <strong id="sidrena-cijena-label-preview"></strong>
                        </p>
                        <p class="description"><?php echo esc_html__('Tekst koji se prikazuje ispred iznosa sidrene cijene na web stranici. Oznake za datum zamjenjuju se datumom sidrene cijene svakog proizvoda: %d dan (01–31), %j dan bez vodeće nule, %m mjesec (01–12), %n mjesec bez vodeće nule, %Y godina (2026), %y godina (26), %F naziv mjeseca, %% znak postotka. Ostavite prazno za vraćanje na zadani tekst.', 'sidrena-cijena'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="sidrena-cijena-font-family"><?php echo esc_html__('Vrsta fonta', 'sidrena-cijena'); ?></label>
                    </th>
                    <td>
                        <select id="sidrena-cijena-font-family" name="<?php echo esc_attr(Config::FONT_FAMILY_OPTION); ?>">
                            <?php foreach (sidrena_cijena_font_family_choices() as $value => $option_label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($font_family, $value); ?>><?php echo esc_html($option_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="sidrena-cijena-font-weight"><?php echo esc_html__('Debljina fonta', 'sidrena-cijena'); ?></label>
                    </th>
                    <td>
                        <select id="sidrena-cijena-font-weight" name="<?php echo esc_attr(Config::FONT_WEIGHT_OPTION); ?>">
                            <?php foreach (sidrena_cijena_font_weight_choices() as $value => $option_label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($font_weight, $value); ?>><?php echo esc_html($option_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="sidrena-cijena-font-style"><?php echo esc_html__('Stil fonta', 'sidrena-cijena'); ?></label>
                    </th>
                    <td>
                        <select id="sidrena-cijena-font-style" name="<?php echo esc_attr(Config::FONT_STYLE_OPTION); ?>">
                            <?php foreach (sidrena_cijena_font_style_choices() as $value => $option_label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($font_style, $value); ?>><?php echo esc_html($option_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="sidrena-cijena-font-size"><?php echo esc_html__('Veličina fonta', 'sidrena-cijena'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="sidrena-cijena-font-size" name="<?php echo esc_attr(Config::FONT_SIZE_OPTION); ?>" value="<?php echo esc_attr($font_size); ?>" min="8" max="32" step="1" class="small-text" /> px
                        <p class="description"><?php echo esc_html__('Veličina fonta teksta sidrene cijene na desktop ekranima, u pikselima (8-32).', 'sidrena-cijena'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="sidrena-cijena-font-size-mobile"><?php echo esc_html__('Veličina fonta (mobitel)', 'sidrena-cijena'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="sidrena-cijena-font-size-mobile" name="<?php echo esc_attr(Config::MOBILE_FONT_SIZE_OPTION); ?>" value="<?php echo esc_attr($font_size_mobile); ?>" min="8" max="32" step="1" class="small-text" /> px
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %d: breakpoint in pixels */
                                esc_html__('Veličina fonta na ekranima širine do %d px (mobiteli i manji tableti). Iznad te širine koristi se desktop veličina.', 'sidrena-cijena'),
                                (int) Config::MOBILE_BREAKPOINT
                            );
                            ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="sidrena-cijena-color"><?php echo esc_html__('Boja teksta', 'sidrena-cijena'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="sidrena-cijena-color" class="sidrena-cijena-color-field" name="<?php echo esc_attr(Config::COLOR_OPTION); ?>" value="<?php echo esc_attr($color); ?>" data-default-color="<?php echo esc_attr(Config::DEFAULT_COLOR); ?>" />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('Pregled', 'sidrena-cijena'); ?></th>
                    <td>
                        <p class="description" style="margin-top:0;"><?php echo esc_html__('Desktop', 'sidrena-cijena'); ?></p>
                        <div style="background:#fff;border:1px solid #dcdcde;padding:16px;max-width:400px;margin-bottom:12px;">
                            <div style="font-size:16px;color:#333;">189,00 €</div>
                            <span id="sidrena-cijena-preview"></span>
                        </div>
                        <p class="description"><?php echo esc_html__('Mobitel', 'sidrena-cijena'); ?></p>
                        <div style="background:#fff;border:1px solid #dcdcde;padding:16px;max-width:250px;">
                            <div style="font-size:16px;color:#333;">189,00 €</div>
                            <span id="sidrena-cijena-preview-mobile"></span>
                        </div>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Spremi promjene', 'sidrena-cijena'), 'primary', 'sidrena_cijena_save'); ?>
        </form>

        <hr />
        <h2><?php echo esc_html__('Brzo početno popunjavanje', 'sidrena-cijena'); ?></h2>
        <p><?php echo esc_html__('Dodatak automatski kopira redovnu WooCommerce cijenu (bez akcijskog sniženja) u prazno polje sidrene cijene. Ovaj alat može ponovno obraditi sve objavljene proizvode; postojeće sidrene cijene nikada ne prepisuje.', 'sidrena-cijena'); ?></p>
        <div class="notice notice-warning inline" style="margin:12px 0;padding:10px 12px;max-width:900px;">
            <p style="margin:0;"><?php echo esc_html__('Važno: automatski kopirana redovna cijena nije nužno cijena koja je vrijedila na datum sidrene cijene. Proizvodi koji su postojali 10.09.2026. dobivaju taj datum, a noviji proizvodi današnji. Predložene iznose i datume provjerite prema vlastitoj evidenciji, osobito ako se redovna cijena mijenjala nakon tog datuma.', 'sidrena-cijena'); ?></p>
        </div>
        <form method="post" action="<?php echo esc_url($tab_url); ?>">
            <?php wp_nonce_field('sidrena_cijena_fill_missing_action', 'sidrena_cijena_fill_missing_nonce'); ?>
            <label style="display:block;margin:12px 0;">
                <input type="checkbox" name="sidrena_cijena_fill_confirm" value="1" />
                <?php echo esc_html__('Razumijem da moram provjeriti iznose prema evidenciji cijena.', 'sidrena-cijena'); ?>
            </label>
            <?php submit_button(__('Popuni prazna polja redovnim cijenama', 'sidrena-cijena'), 'secondary', 'sidrena_cijena_fill_missing'); ?>
        </form>
    <?php
}

/**
 * Counts published products and their variations that need an anchor price.
 */
function sidrena_cijena_published_product_ids() {
    if (!function_exists('wc_get_products')) {
        return;
    }

    $page = 1;
    $page_size = 100;
    do {
        $ids = wc_get_products(array(
            'status' => 'publish',
            'limit' => $page_size,
            'page' => $page,
            'orderby' => 'ID',
            'order' => 'ASC',
            'return' => 'ids',
        ));
        if (is_wp_error($ids) || !is_array($ids)) {
            return;
        }
        foreach ($ids as $id) {
            yield $id;
        }
        $page++;
    } while (count($ids) === $page_size);
}

function sidrena_cijena_get_anchor_status() {
    $status = array('total' => 0, 'set' => 0, 'missing' => 0);

    if (!function_exists('wc_get_products')) {
        return $status;
    }

    foreach (sidrena_cijena_published_product_ids() as $id) {
        $product = wc_get_product($id);
        if (!$product) {
            continue;
        }

        $targets = $product->is_type('variable') ? $product->get_children() : array($id);
        foreach ($targets as $target_id) {
            $target = wc_get_product($target_id);
            if (!$target || $target->get_status() !== 'publish') {
                continue;
            }
            $status['total']++;
            if ($target->get_meta(Config::ANCHOR_META_KEY, true) === '') {
                $status['missing']++;
            } else {
                $status['set']++;
            }
        }
    }

    return $status;
}

/**
 * Fills only missing anchor prices. Existing values are intentionally preserved.
 */
function sidrena_cijena_fill_missing_from_current_prices() {
    if (!current_user_can('manage_woocommerce') || !function_exists('wc_get_products')) {
        return 0;
    }

    $filled = 0;
    foreach (sidrena_cijena_published_product_ids() as $id) {
        $product = wc_get_product($id);
        if (!$product) {
            continue;
        }

        $targets = $product->is_type('variable') ? $product->get_children() : array($id);
        foreach ($targets as $target_id) {
            $target = wc_get_product($target_id);
            if (!$target || $target->get_status() !== 'publish' || $target->get_meta(Config::ANCHOR_META_KEY, true) !== '') {
                continue;
            }

            $current_price = sidrena_cijena_get_reference_price($target);
            if ($current_price === '') {
                continue;
            }

            $target->update_meta_data(Config::ANCHOR_META_KEY, $current_price);
            $target->update_meta_data(Config::ANCHOR_DATE_META_KEY, sidrena_cijena_initial_anchor_date($target));
            $target->save_meta_data();
            // save_meta_data() does not fire the product update hooks that clear this cache.
            sidrena_cijena_clear_anchor_range_cache($target_id);
            $filled++;
        }
    }

    if ($filled > 0 && function_exists('cjenik_queue_refresh')) {
        cjenik_queue_refresh();
    }

    return $filled;
}

function sidrena_cijena_render_help_tab() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $csv_url = function_exists('cjenik_get_public_csv_url') ? cjenik_get_public_csv_url() : '';
    $xml_url = function_exists('cjenik_get_public_xml_url') ? cjenik_get_public_xml_url() : '';
    $last_generated = (int) get_option(CJENIK_LAST_GENERATED_OPTION, 0);
    $next_scheduled = wp_next_scheduled(CJENIK_CRON_HOOK);
    $last_error = get_option(CJENIK_LAST_ERROR_OPTION, '');
    $display_enabled = get_option(Config::DISPLAY_ENABLED_OPTION, 'yes') === 'yes';
    $automatic_enabled = get_option(CJENIK_ENABLED_OPTION, 'yes') === 'yes';
    $csv_enabled = get_option(CJENIK_FORMAT_CSV_OPTION, 'yes') === 'yes';
    $xml_enabled = get_option(CJENIK_FORMAT_XML_OPTION, 'yes') === 'yes';
    ?>
    <style>
        .sidrena-help-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(300px,1fr)); gap:16px; max-width:1200px; }
        .sidrena-help-card { background:#fff; border:1px solid #dcdcde; padding:18px 20px; }
        .sidrena-help-card h2 { margin-top:0; }
        .sidrena-help-card code { overflow-wrap:anywhere; }
        .sidrena-help-ok { color:#008a20; font-weight:600; }
        .sidrena-help-warning { color:#b32d2e; font-weight:600; }
    </style>

    <p style="font-size:14px;max-width:1000px;">
        <?php echo esc_html__('Ovdje su objedinjene upute za postavljanje, svakodnevni rad i provjeru dodatka. Dodatak tehnički pomaže pri prikazu sidrene cijene i objavi strojno čitljivog cjenika; vlasnik trgovine odgovoran je za točnost podataka.', 'sidrena-cijena'); ?>
    </p>

    <div class="sidrena-help-grid">
        <section class="sidrena-help-card">
            <h2><?php echo esc_html__('Brzi početak', 'sidrena-cijena'); ?></h2>
            <ol>
                <li><?php echo wp_kses_post(sprintf(__('Otvorite <a href="%s">Sidrena cijena</a> i provjerite prikaz i početno spremljene iznose.', 'sidrena-cijena'), esc_url(admin_url('admin.php?page=sidrena-cijena&tab=sidrena-cijena')))); ?></li>
                <li><?php echo wp_kses_post(sprintf(__('Otvorite <a href="%s">Cjenik</a> i unesite vrstu objekta, oznaku objekta i broj skladišta.', 'sidrena-cijena'), esc_url(admin_url('admin.php?page=sidrena-cijena&tab=cjenik')))); ?></li>
                <li><?php echo esc_html__('Provjerite adresu trgovine u WooCommerce → Postavke → Općenito.', 'sidrena-cijena'); ?></li>
                <li><?php echo esc_html__('Kliknite „Generiraj cjenik sada” i otvorite CSV i XML poveznice u anonimnom prozoru.', 'sidrena-cijena'); ?></li>
                <li><?php echo esc_html__('Na javnu stranicu dodajte shortcode za željeni format.', 'sidrena-cijena'); ?></li>
            </ol>
        </section>

        <section class="sidrena-help-card">
            <h2><?php echo esc_html__('Sidrena cijena', 'sidrena-cijena'); ?></h2>
            <ul>
                <li><?php echo esc_html__('Polje postoji na jednostavnim proizvodima i na svakoj varijaciji.', 'sidrena-cijena'); ?></li>
                <li><?php echo esc_html__('Ako je prazno, pri prvom spremanju kopira se tadašnja redovna WooCommerce cijena (bez akcijskog sniženja).', 'sidrena-cijena'); ?></li>
                <li><?php echo esc_html__('Uz iznos se sprema datum sidrene cijene: 10.09.2026. za proizvode koji su tada postojali, a dan početnog spremanja za novije proizvode. Datum se može ručno promijeniti.', 'sidrena-cijena'); ?></li>
                <li><?php echo esc_html__('Tekst oznake može sadržavati datum sidrene cijene, npr. „Sidrena cijena na dan %d.%m.%Y.”.', 'sidrena-cijena'); ?></li>
                <li><?php echo esc_html__('Nakon početnog spremanja sidrena cijena se ne mijenja zajedno s aktualnom cijenom.', 'sidrena-cijena'); ?></li>
                <li><?php echo esc_html__('Ručno unesene vrijednosti dodatak nikada automatski ne prepisuje.', 'sidrena-cijena'); ?></li>
                <li><?php echo esc_html__('Za varijabilni proizvod kupcu se prikazuje vrijednost odabrane varijacije, odnosno raspon prije odabira ako sve varijacije imaju isti datum sidrene cijene.', 'sidrena-cijena'); ?></li>
                <li><?php echo esc_html__('Izgled teksta, boja i veličina za desktop i mobitel podešavaju se u tabu Sidrena cijena.', 'sidrena-cijena'); ?></li>
            </ul>
            <p><strong><?php echo esc_html__('Napomena:', 'sidrena-cijena'); ?></strong> <?php echo esc_html__('automatski kopiranu redovnu cijenu treba provjeriti prema evidenciji, osobito ako se mijenjala nakon 10.09.2026.', 'sidrena-cijena'); ?></p>
        </section>

        <section class="sidrena-help-card">
            <h2><?php echo esc_html__('Podaci u cjeniku', 'sidrena-cijena'); ?></h2>
            <p><?php echo esc_html__('Svaki red CSV-a i svaki XML proizvod sadrži:', 'sidrena-cijena'); ?></p>
            <p><?php echo esc_html__('naziv, šifru proizvoda, brend, jedinicu mjere, cijenu po jedinici, maloprodajnu cijenu, oznaku i naziv posebnog oblika prodaje, sidrenu cijenu, barkod i dostupnost.', 'sidrena-cijena'); ?></p>
            <ul>
                <li><?php echo esc_html__('Barkod, jedinica mjere i uključivanje u cjenik uređuju se u podacima proizvoda, uz SKU.', 'sidrena-cijena'); ?></li>
                <li><?php echo esc_html__('Brend se preuzima iz WooCommerce Brands ili podržanih dodataka za brendove.', 'sidrena-cijena'); ?></li>
                <li><?php echo esc_html__('Skriveni proizvodi automatski se izostavljaju, osim ako ih ručno uključite.', 'sidrena-cijena'); ?></li>
                <li><?php echo esc_html__('Varijacije se izvoze kao zasebne stavke.', 'sidrena-cijena'); ?></li>
            </ul>
        </section>

        <section class="sidrena-help-card">
            <h2><?php echo esc_html__('Javne adrese i shortcodeovi', 'sidrena-cijena'); ?></h2>
            <p><strong>CSV:</strong><br><a href="<?php echo esc_url($csv_url); ?>"><code><?php echo esc_html($csv_url); ?></code></a></p>
            <p><strong>XML:</strong><br><a href="<?php echo esc_url($xml_url); ?>"><code><?php echo esc_html($xml_url); ?></code></a></p>
            <table class="widefat striped">
                <tbody>
                    <tr><td><code>[sidrena_cjenik]</code></td><td><?php echo esc_html__('CSV gumb', 'sidrena-cijena'); ?></td></tr>
                    <tr><td><code>[sidrena_cjenik format="xml"]</code></td><td><?php echo esc_html__('XML gumb', 'sidrena-cijena'); ?></td></tr>
                    <tr><td><code>[sidrena_cjenik format="oba"]</code></td><td><?php echo esc_html__('CSV i XML gumbi', 'sidrena-cijena'); ?></td></tr>
                    <tr><td><code>[sidrena_cjenik format="oba" arhiva="da"]</code></td><td><?php echo esc_html__('Oba formata i arhive', 'sidrena-cijena'); ?></td></tr>
                    <tr><td><code>[sidrena_cjenik tekst="Preuzmi cjenik"]</code></td><td><?php echo esc_html__('Vlastiti tekst jednog gumba', 'sidrena-cijena'); ?></td></tr>
                </tbody>
            </table>
        </section>

        <section class="sidrena-help-card">
            <h2><?php echo esc_html__('Automatsko generiranje', 'sidrena-cijena'); ?></h2>
            <ul>
                <li><?php echo esc_html__('Cjenik se generira svakodnevno u odabrano vrijeme prije 8:00.', 'sidrena-cijena'); ?></li>
                <li><?php echo esc_html__('Promjena proizvoda, cijene ili zalihe stavlja osvježavanje u red bez usporavanja spremanja proizvoda.', 'sidrena-cijena'); ?></li>
                <li><?php echo esc_html__('Javni zahtjev uvijek preuzima zadnju dovršenu datoteku; osvježavanje se obavlja u pozadini.', 'sidrena-cijena'); ?></li>
                <li><?php echo esc_html__('Arhivske datoteke čuvaju se najmanje 30 dana.', 'sidrena-cijena'); ?></li>
            </ul>
            <p><strong><?php echo esc_html__('Važno:', 'sidrena-cijena'); ?></strong> <?php echo esc_html__('WP-Cron ovisi o posjetima stranici. Za zajamčeno izvršavanje prije 8:00 postavite poslužiteljski cron koji redovito poziva wp-cron.php.', 'sidrena-cijena'); ?></p>
        </section>

        <section class="sidrena-help-card">
            <h2><?php echo esc_html__('Dijagnostika ove instalacije', 'sidrena-cijena'); ?></h2>
            <table class="widefat striped">
                <tbody>
                    <tr><td><?php echo esc_html__('Verzija dodatka', 'sidrena-cijena'); ?></td><td><strong><?php echo esc_html(Config::VERSION); ?></strong></td></tr>
                    <tr><td><?php echo esc_html__('Prikaz sidrene cijene', 'sidrena-cijena'); ?></td><td class="<?php echo $display_enabled ? 'sidrena-help-ok' : 'sidrena-help-warning'; ?>"><?php echo $display_enabled ? esc_html__('Uključen', 'sidrena-cijena') : esc_html__('Isključen', 'sidrena-cijena'); ?></td></tr>
                    <tr><td><?php echo esc_html__('Automatski cjenik', 'sidrena-cijena'); ?></td><td class="<?php echo $automatic_enabled ? 'sidrena-help-ok' : 'sidrena-help-warning'; ?>"><?php echo $automatic_enabled ? esc_html__('Uključen', 'sidrena-cijena') : esc_html__('Isključen', 'sidrena-cijena'); ?></td></tr>
                    <tr><td><?php echo esc_html__('Formati', 'sidrena-cijena'); ?></td><td><?php echo esc_html(implode(', ', array_filter(array($csv_enabled ? 'CSV' : '', $xml_enabled ? 'XML' : '')))); ?></td></tr>
                    <tr><td><?php echo esc_html__('Zadnje generiranje', 'sidrena-cijena'); ?></td><td><?php echo $last_generated ? esc_html(wp_date('d.m.Y. H:i', $last_generated)) : '<span class="sidrena-help-warning">' . esc_html__('Nije još generiran', 'sidrena-cijena') . '</span>'; ?></td></tr>
                    <tr><td><?php echo esc_html__('Sljedeće planirano', 'sidrena-cijena'); ?></td><td><?php echo $next_scheduled ? esc_html(wp_date('d.m.Y. H:i', $next_scheduled)) : '<span class="sidrena-help-warning">' . esc_html__('Nije zakazano', 'sidrena-cijena') . '</span>'; ?></td></tr>
                    <tr><td><?php echo esc_html__('Zadnja pogreška', 'sidrena-cijena'); ?></td><td><?php echo $last_error ? '<span class="sidrena-help-warning">' . esc_html($last_error) . '</span>' : '<span class="sidrena-help-ok">' . esc_html__('Nema zabilježene pogreške', 'sidrena-cijena') . '</span>'; ?></td></tr>
                </tbody>
            </table>
        </section>

        <section class="sidrena-help-card">
            <h2><?php echo esc_html__('Ako nešto ne radi', 'sidrena-cijena'); ?></h2>
            <ul>
                <li><strong><?php echo esc_html__('CSV/XML vraća 404:', 'sidrena-cijena'); ?></strong> <?php echo esc_html__('generirajte cjenik ručno. Ako se problem nastavi, otvorite Postavke → Stalne veze i kliknite Spremi promjene, zatim očistite cache.', 'sidrena-cijena'); ?></li>
                <li><strong><?php echo esc_html__('XML poveznica se ne prikazuje:', 'sidrena-cijena'); ?></strong> <?php echo esc_html__('uključite Generiraj XML, spremite postavke i ponovno generirajte cjenik.', 'sidrena-cijena'); ?></li>
                <li><strong><?php echo esc_html__('Cjenik kasni:', 'sidrena-cijena'); ?></strong> <?php echo esc_html__('provjerite WP-Cron ili postavite pravi poslužiteljski cron.', 'sidrena-cijena'); ?></li>
                <li><strong><?php echo esc_html__('Nedostaju podaci:', 'sidrena-cijena'); ?></strong> <?php echo esc_html__('u tabu Cjenik pogledajte Provjeru spremnosti i popunite označena polja.', 'sidrena-cijena'); ?></li>
                <li><strong><?php echo esc_html__('Promjena se ne vidi:', 'sidrena-cijena'); ?></strong> <?php echo esc_html__('očistite cache WordPress dodatka, poslužitelja i CDN-a te ponovno otvorite javnu adresu.', 'sidrena-cijena'); ?></li>
            </ul>
        </section>

        <section class="sidrena-help-card">
            <h2><?php echo esc_html__('Propisi i kontakt', 'sidrena-cijena'); ?></h2>
            <p><a href="https://narodne-novine.nn.hr/clanci/sluzbeni/2026_09_101_1212.html" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('NN 101/2026, broj 1212 — sidrena cijena', 'sidrena-cijena'); ?></a></p>
            <p><a href="https://narodne-novine.nn.hr/clanci/sluzbeni/2026_09_101_1213.html" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('NN 101/2026, broj 1213 — objava cjenika', 'sidrena-cijena'); ?></a></p>
            <p><?php echo esc_html__('Dodatak je tehnička pomoć, a ne pravni savjet ili jamstvo usklađenosti.', 'sidrena-cijena'); ?></p>
            <p><strong><?php echo esc_html__('Autor:', 'sidrena-cijena'); ?></strong> Matija Gračanin<br><a href="mailto:matijag@gmail.com">matijag@gmail.com</a></p>
        </section>
    </div>
    <?php
}
