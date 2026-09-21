<?php
/**
 * Cjenik modul - strojno čitljiv cjenik (XML/CSV) prema Odluci o objavi cjenika
 * proizvoda i usluga kao mjeri izravne kontrole cijena (NN 101/2026), na snazi od 1.10.2026.
 *
 * NAPOMENA: Odluka propisuje koje podatke cjenik mora sadržavati, ali ne propisuje
 * točan raspored/redoslijed CSV stupaca ni XSD shemu za XML. Ova implementacija je
 * dobronamjerno tumačenje zahtjeva - preporučujemo provjeru s računovođom/pravnikom
 * prije 1.10.2026.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CJENIK_UNIT_META_KEY', '_cjenik_unit');
define('CJENIK_BARCODE_META_KEY', '_cjenik_barcode');
define('CJENIK_VISIBILITY_META_KEY', '_cjenik_visibility_override');
define('CJENIK_ENABLED_OPTION', 'sidrena_cijena_cjenik_enabled');
define('CJENIK_LOCATION_LABEL_OPTION', 'sidrena_cijena_cjenik_location_label');
define('CJENIK_OBJECT_TYPE_OPTION', 'sidrena_cijena_cjenik_object_type');
define('CJENIK_STORAGE_NUMBER_OPTION', 'sidrena_cijena_cjenik_storage_number');
define('CJENIK_LAST_GENERATED_OPTION', 'sidrena_cijena_cjenik_last_generated');
define('CJENIK_LAST_FILES_OPTION', 'sidrena_cijena_cjenik_last_files');
define('CJENIK_LAST_ERROR_OPTION', 'sidrena_cijena_cjenik_last_error');
define('CJENIK_DB_VERSION_OPTION', 'sidrena_cijena_cjenik_db_version');
define('CJENIK_DIRTY_OPTION', 'sidrena_cijena_cjenik_dirty_since');
define('CJENIK_GENERATION_LOCK', 'sidrena_cijena_cjenik_generation_lock');
define('CJENIK_CRON_HOOK', 'sidrena_cijena_cjenik_cron_generate');
define('CJENIK_REFRESH_HOOK', 'sidrena_cijena_cjenik_refresh');
define('CJENIK_DEFAULT_STORAGE_NUMBER', '01');
define('CJENIK_DEFAULT_OBJECT_TYPE', 'Internet trgovina');
define('CJENIK_MIN_RETENTION_DAYS', 30);

define('CJENIK_CRON_TIME_OPTION', 'sidrena_cijena_cjenik_cron_time');
define('CJENIK_DEFAULT_CRON_TIME', '07:30');
define('CJENIK_DELIMITER_OPTION', 'sidrena_cijena_cjenik_delimiter');
define('CJENIK_DEFAULT_DELIMITER', ';');
define('CJENIK_FORMAT_CSV_OPTION', 'sidrena_cijena_cjenik_format_csv');
define('CJENIK_FORMAT_XML_OPTION', 'sidrena_cijena_cjenik_format_xml');
define('CJENIK_RETENTION_DAYS_OPTION', 'sidrena_cijena_cjenik_retention_days');
define('CJENIK_NOTIFY_ENABLED_OPTION', 'sidrena_cijena_cjenik_notify_enabled');
define('CJENIK_NOTIFY_EMAIL_OPTION', 'sidrena_cijena_cjenik_notify_email');
define('CJENIK_SALE_LABEL_OPTION', 'sidrena_cijena_cjenik_sale_label');
define('CJENIK_DEFAULT_SALE_LABEL', 'Akcijska prodaja');

function cjenik_cron_time_choices() {
    $choices = array();
    for ($h = 5; $h <= 7; $h++) {
        foreach (array('00', '15', '30', '45') as $m) {
            if ($h === 7 && $m === '45') {
                continue;
            }
            $time = sprintf('%02d:%s', $h, $m);
            $choices[$time] = $time;
        }
    }
    return $choices;
}

function cjenik_delimiter_choices() {
    return array(
        ';'    => __('točka-zarez ( ; ) - preporučeno za Excel na hrvatskom', 'sidrena-cijena-i-cjenik-aplitap'),
        ','    => __('zarez ( , )', 'sidrena-cijena-i-cjenik-aplitap'),
        "\t"   => __('tabulator', 'sidrena-cijena-i-cjenik-aplitap'),
    );
}

function cjenik_unit_choices() {
    return array(
        'kom' => __('kom (komad)', 'sidrena-cijena-i-cjenik-aplitap'),
        'kg'  => __('kg', 'sidrena-cijena-i-cjenik-aplitap'),
        'g'   => __('g', 'sidrena-cijena-i-cjenik-aplitap'),
        'L'   => __('L', 'sidrena-cijena-i-cjenik-aplitap'),
        'ml'  => __('ml', 'sidrena-cijena-i-cjenik-aplitap'),
        'm'   => __('m', 'sidrena-cijena-i-cjenik-aplitap'),
        'm2'  => __('m2', 'sidrena-cijena-i-cjenik-aplitap'),
    );
}

function cjenik_visibility_choices() {
    return array(
        'auto'    => __('Automatski (prema vidljivosti na webu)', 'sidrena-cijena-i-cjenik-aplitap'),
        'include' => __('Uvijek uključi u cjenik', 'sidrena-cijena-i-cjenik-aplitap'),
        'exclude' => __('Uvijek izostavi iz cjenika', 'sidrena-cijena-i-cjenik-aplitap'),
    );
}

function cjenik_product_included($product) {
    $override = $product->get_meta(CJENIK_VISIBILITY_META_KEY, true);

    if ($override === 'include') {
        return true;
    }

    if ($override === 'exclude') {
        return false;
    }

    return $product->get_catalog_visibility() !== 'hidden';
}

// 1. Polja na proizvodu (tab Inventory, odmah ispod SKU-a): barkod, jedinica mjere, uključenost u cjenik
add_action('woocommerce_product_options_sku', 'cjenik_add_product_fields');
function cjenik_add_product_fields() {
    global $product_object;

    $unit = $product_object ? $product_object->get_meta(CJENIK_UNIT_META_KEY, true) : '';
    if ($unit === '') {
        $unit = 'kom';
    }

    $visibility_override = $product_object ? $product_object->get_meta(CJENIK_VISIBILITY_META_KEY, true) : '';
    if ($visibility_override === '') {
        $visibility_override = 'auto';
    }

    woocommerce_wp_text_input(array(
        'id'          => CJENIK_BARCODE_META_KEY,
        'label'       => __('Barkod (EAN)', 'sidrena-cijena-i-cjenik-aplitap'),
        'desc_tip'    => true,
        'description' => __('Barkod proizvoda za potrebe cjenika. Nije obavezno, ali zakon zahtijeva ovaj podatak u cjeniku ako je dostupan.', 'sidrena-cijena-i-cjenik-aplitap'),
    ));

    woocommerce_wp_select(array(
        'id'          => CJENIK_UNIT_META_KEY,
        'label'       => __('Jedinica mjere', 'sidrena-cijena-i-cjenik-aplitap'),
        'desc_tip'    => true,
        'description' => __('Jedinica mjere za potrebe cjenika.', 'sidrena-cijena-i-cjenik-aplitap'),
        'options'     => cjenik_unit_choices(),
        'value'       => $unit,
    ));

    woocommerce_wp_select(array(
        'id'          => CJENIK_VISIBILITY_META_KEY,
        'label'       => __('Prikaz u cjeniku', 'sidrena-cijena-i-cjenik-aplitap'),
        'desc_tip'    => true,
        'description' => __('Automatski = proizvod je u cjeniku samo ako je vidljiv na web stranici (nije postavljen na "Hidden"). Ovdje možete ručno izostaviti proizvod iz cjenika, ili ga uključiti čak i ako je sakriven na webu.', 'sidrena-cijena-i-cjenik-aplitap'),
        'options'     => cjenik_visibility_choices(),
        'value'       => $visibility_override,
    ));
}

// 2. Spremanje
add_action('woocommerce_process_product_meta', 'cjenik_save_product_fields');
function cjenik_save_product_fields($post_id) {
    if (!current_user_can('edit_post', $post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
        return;
    }

    if (isset($_POST[CJENIK_BARCODE_META_KEY])) {
        update_post_meta($post_id, CJENIK_BARCODE_META_KEY, sanitize_text_field(wp_unslash($_POST[CJENIK_BARCODE_META_KEY])));
    }

    if (isset($_POST[CJENIK_VISIBILITY_META_KEY])) {
        $visibility_override = sanitize_text_field(wp_unslash($_POST[CJENIK_VISIBILITY_META_KEY]));
        if (!array_key_exists($visibility_override, cjenik_visibility_choices())) {
            $visibility_override = 'auto';
        }
        update_post_meta($post_id, CJENIK_VISIBILITY_META_KEY, $visibility_override);
    }

    if (isset($_POST[CJENIK_UNIT_META_KEY])) {
        $unit = sanitize_text_field(wp_unslash($_POST[CJENIK_UNIT_META_KEY]));
        if (!array_key_exists($unit, cjenik_unit_choices())) {
            $unit = 'kom';
        }
        update_post_meta($post_id, CJENIK_UNIT_META_KEY, $unit);
    }
}

// 2b. Podrška za Quick Edit
add_action('woocommerce_product_quick_edit_end', 'cjenik_quick_edit_field');
function cjenik_quick_edit_field() {
    ?>
    <div class="inline-edit-group cjenik-quick-edit-row" style="clear:both;display:block;width:100%;float:none;">
        <label class="alignleft" style="width:100%;">
            <span class="title"><?php echo esc_html__('Prikaz u cjeniku', 'sidrena-cijena-i-cjenik-aplitap'); ?></span>
            <span class="input-text-wrap">
                <select class="cjenik_quick_edit_field" name="<?php echo esc_attr(CJENIK_VISIBILITY_META_KEY); ?>">
                    <?php foreach (cjenik_visibility_choices() as $value => $option_label) : ?>
                        <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($option_label); ?></option>
                    <?php endforeach; ?>
                </select>
            </span>
        </label>
    </div>
    <?php
}

add_action('manage_product_posts_custom_column', 'cjenik_output_hidden_visibility_value', 20, 2);
function cjenik_output_hidden_visibility_value($column, $post_id) {
    if ($column === 'price') {
        $value = get_post_meta($post_id, CJENIK_VISIBILITY_META_KEY, true);
        if ($value === '') {
            $value = 'auto';
        }
        echo '<div class="cjenik_hidden_visibility_value" style="display:none;">' . esc_html($value) . '</div>';
    }
}

add_action('woocommerce_product_quick_edit_save', 'cjenik_quick_edit_save');
function cjenik_quick_edit_save($product) {
    if (!current_user_can('edit_post', $product->get_id())) {
        return;
    }

    if (isset($_POST[CJENIK_VISIBILITY_META_KEY])) {
        $visibility_override = sanitize_text_field(wp_unslash($_POST[CJENIK_VISIBILITY_META_KEY]));
        if (!array_key_exists($visibility_override, cjenik_visibility_choices())) {
            $visibility_override = 'auto';
        }
        update_post_meta($product->get_id(), CJENIK_VISIBILITY_META_KEY, $visibility_override);
    }
}

add_action('admin_enqueue_scripts', 'cjenik_quick_edit_script');
function cjenik_quick_edit_script($hook) {
    global $post_type;

    if ($hook !== 'edit.php' || $post_type !== 'product') {
        return;
    }

    $js = "
        (function($){
            if (typeof inlineEditPost === 'undefined') { return; }
            var cjenik_wc_inline_edit = inlineEditPost.edit;
            inlineEditPost.edit = function(id) {
                cjenik_wc_inline_edit.apply(this, arguments);
                var postId = 0;
                if (typeof(id) === 'object') {
                    postId = parseInt(this.getId(id), 10);
                } else {
                    postId = parseInt(id, 10);
                }
                if (postId > 0) {
                    var \$row = $('#post-' + postId);
                    var value = \$row.find('.cjenik_hidden_visibility_value').first().text();
                    if (value) {
                        $('select.cjenik_quick_edit_field').val(value);
                    }
                }
            };
        })(jQuery);
    ";

    wp_add_inline_script('inline-edit-post', $js);
}

// 3. Prikupljanje podataka za sve proizvode (i varijacije varijabilnih proizvoda)
function cjenik_collect_rows() {
    $rows = array();

    if (!class_exists('WooCommerce') || !function_exists('wc_get_products')) {
        return $rows;
    }

    $product_ids = wc_get_products(array(
        'status' => 'publish',
        'limit'  => -1,
        'return' => 'ids',
    ));

    foreach ($product_ids as $product_id) {
        $product = wc_get_product($product_id);
        if (!$product) {
            continue;
        }

        if (!cjenik_product_included($product)) {
            continue;
        }

        if ($product->is_type('variable')) {
            foreach ($product->get_children() as $variation_id) {
                $variation = wc_get_product($variation_id);
                if ($variation && $variation->get_status() === 'publish') {
                    $rows[] = cjenik_build_row($variation, $product);
                }
            }
        } else {
            $rows[] = cjenik_build_row($product);
        }
    }

    return $rows;
}

function cjenik_get_inherited_meta($product, $parent, $key, $default = '') {
    $value = $product->get_meta($key, true);
    if ($value === '' && $parent) {
        $value = $parent->get_meta($key, true);
    }

    return $value === '' ? $default : $value;
}

function cjenik_get_brand_names($product, $parent = null) {
    $product_id = $parent ? $parent->get_id() : $product->get_id();
    $taxonomies = apply_filters(
        'sidrena_cijena_cjenik_brand_taxonomies',
        array('product_brand', 'pwb-brand', 'yith_product_brand')
    );

    foreach ($taxonomies as $taxonomy) {
        if (!taxonomy_exists($taxonomy)) {
            continue;
        }

        $terms = wp_get_post_terms($product_id, $taxonomy, array('fields' => 'names'));
        if (!is_wp_error($terms) && !empty($terms)) {
            return $terms;
        }
    }

    return array();
}

function cjenik_get_barcode($product, $parent = null) {
    $keys = apply_filters(
        'sidrena_cijena_cjenik_barcode_meta_keys',
        array(CJENIK_BARCODE_META_KEY, '_global_unique_id', '_ean', '_barcode', '_alg_ean')
    );

    foreach ($keys as $key) {
        $value = cjenik_get_inherited_meta($product, $parent, $key);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function cjenik_build_row($product, $parent = null) {
    $name = $parent ? $parent->get_name() . ' - ' . wc_get_formatted_variation($product, true, false) : $product->get_name();
    $name = wp_strip_all_tags($name);

    $brand_names = cjenik_get_brand_names($product, $parent);

    $price = $product->get_price();
    $anchor_price = cjenik_get_inherited_meta($product, $parent, SIDRENA_CIJENA_META_KEY);
    if ($anchor_price === '') {
        $anchor_price = $price;
    }
    $unit = cjenik_get_inherited_meta($product, $parent, CJENIK_UNIT_META_KEY, 'kom');
    $barcode = cjenik_get_barcode($product, $parent);
    $is_sale = $product->is_on_sale();
    $sale_name = $is_sale ? get_option(CJENIK_SALE_LABEL_OPTION, CJENIK_DEFAULT_SALE_LABEL) : '';
    $sale_name = apply_filters('sidrena_cijena_cjenik_sale_name', $sale_name, $product, $parent);
    $unit_price = apply_filters('sidrena_cijena_cjenik_unit_price', $price, $product, $parent, $unit);

    $row = array(
        'naziv'                  => $name,
        'sifra_proizvoda'        => $product->get_sku(),
        'brend'                  => implode(', ', $brand_names),
        'jedinica_mjere'         => $unit,
        'cijena_po_jedinici'     => $unit_price !== '' ? wc_format_decimal($unit_price, 2) : '',
        'maloprodajna_cijena'    => wc_format_decimal($price, 2),
        'poseban_oblik_prodaje'  => $is_sale ? 'DA' : 'NE',
        'naziv_posebnog_oblika_prodaje' => $sale_name,
        'sidrena_cijena'         => $anchor_price !== '' ? wc_format_decimal($anchor_price, 2) : '',
        'barkod'                 => $barcode,
        'dostupnost'             => $product->is_in_stock() ? __('Raspoloživo', 'sidrena-cijena-i-cjenik-aplitap') : __('Nije raspoloživo', 'sidrena-cijena-i-cjenik-aplitap'),
    );

    return apply_filters('sidrena_cijena_cjenik_row', $row, $product, $parent);
}

// 4. Gradnja CSV-a
function cjenik_build_csv($rows) {
    $headers = array(
        'naziv', 'sifra_proizvoda', 'brend', 'jedinica_mjere', 'cijena_po_jedinici',
        'maloprodajna_cijena', 'poseban_oblik_prodaje', 'naziv_posebnog_oblika_prodaje',
        'sidrena_cijena', 'barkod', 'dostupnost',
    );

    $delimiter = get_option(CJENIK_DELIMITER_OPTION, CJENIK_DEFAULT_DELIMITER);
    if (!array_key_exists($delimiter, cjenik_delimiter_choices())) {
        $delimiter = CJENIK_DEFAULT_DELIMITER;
    }

    $handle = fopen('php://temp', 'w+');
    fwrite($handle, "\xEF\xBB\xBF");
    fputcsv($handle, $headers, $delimiter);

    foreach ($rows as $row) {
        $ordered_row = array();
        foreach ($headers as $header) {
            $ordered_row[] = isset($row[$header]) ? $row[$header] : '';
        }
        fputcsv($handle, $ordered_row, $delimiter);
    }

    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);

    return $csv;
}

// 5. Gradnja XML-a
function cjenik_build_xml($rows, $meta) {
    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><cjenik/>');
    $xml->addAttribute('datum_generiranja', $meta['generated_at']);
    $xml->addAttribute('vrsta_prodajnog_objekta', $meta['object_type']);
    $xml->addAttribute('prodajno_mjesto', $meta['location_label']);
    $xml->addAttribute('adresa', $meta['address']);
    $xml->addAttribute('oznaka_objekta', $meta['location_label']);
    $xml->addAttribute('broj_skladista', $meta['storage_number']);

    foreach ($rows as $row) {
        $item = $xml->addChild('proizvod');
        foreach ($row as $key => $value) {
            // Assignment through a SimpleXML node safely escapes XML special characters.
            $child = $item->addChild($key);
            $child[0] = (string) $value;
        }
    }

    return $xml->asXML();
}

// 6. Naziv datoteke: vrsta objekta, adresa, oznaka objekta, broj skladišta i vremenska oznaka.
function cjenik_build_filename($extension, $meta, $timestamped = true) {
    $parts = array(
        'cjenik',
        sanitize_title($meta['object_type']),
        sanitize_title($meta['address']),
        sanitize_title($meta['location_label']),
        sanitize_title($meta['storage_number']),
    );

    if ($timestamped) {
        $parts[] = wp_date('Ymd-His');
    } else {
        $parts[] = 'trenutni';
    }

    return implode('_', array_filter($parts)) . '.' . $extension;
}

function cjenik_get_meta() {
    $location_label = get_option(CJENIK_LOCATION_LABEL_OPTION, '');
    $object_type = get_option(CJENIK_OBJECT_TYPE_OPTION, CJENIK_DEFAULT_OBJECT_TYPE);
    if ($object_type === '') {
        $object_type = CJENIK_DEFAULT_OBJECT_TYPE;
    }
    $storage_number = get_option(CJENIK_STORAGE_NUMBER_OPTION, CJENIK_DEFAULT_STORAGE_NUMBER);
    if ($storage_number === '') {
        $storage_number = CJENIK_DEFAULT_STORAGE_NUMBER;
    }

    $address_parts = array_filter(array(
        get_option('woocommerce_store_address'),
        get_option('woocommerce_store_address_2'),
        get_option('woocommerce_store_postcode'),
        get_option('woocommerce_store_city'),
    ));
    $address = implode(', ', $address_parts);

    return array(
        'object_type'     => $object_type,
        'location_label'  => $location_label !== '' ? $location_label : get_bloginfo('name'),
        'storage_number'  => $storage_number,
        'address'         => $address,
        'generated_at'    => wp_date('Y-m-d\TH:i:sP'),
    );
}

function cjenik_get_dir() {
    $upload_dir = wp_upload_dir();
    $dir = trailingslashit($upload_dir['basedir']) . 'cjenik';

    if (!file_exists($dir)) {
        wp_mkdir_p($dir);
    }

    return array(
        'path' => $dir,
        'url'  => trailingslashit($upload_dir['baseurl']) . 'cjenik',
    );
}

function cjenik_write_file($path, $contents) {
    return file_put_contents($path, $contents, LOCK_EX) !== false;
}

// 7. Generiranje i spremanje datoteka (arhivska + "trenutna" verzija)
function cjenik_generate() {
    try {
        $rows = cjenik_collect_rows();
        $meta = cjenik_get_meta();
        $location = cjenik_get_dir();

        $do_csv = get_option(CJENIK_FORMAT_CSV_OPTION, 'yes') === 'yes';
        $do_xml = get_option(CJENIK_FORMAT_XML_OPTION, 'yes') === 'yes';

        if (!$do_csv && !$do_xml) {
            throw new Exception('Odaberite barem jedan format cjenika (CSV ili XML).');
        }

        $urls = array(
            'csv_url' => '',
            'xml_url' => '',
            'csv_file' => '',
            'xml_file' => '',
            'csv_download_name' => '',
            'xml_download_name' => '',
        );

        if ($do_csv) {
            $csv = cjenik_build_csv($rows);
            $csv_archive_name = cjenik_build_filename('csv', $meta, true);
            $csv_current_name = cjenik_build_filename('csv', $meta, false);

            if (!cjenik_write_file(trailingslashit($location['path']) . $csv_archive_name, $csv)
                || !cjenik_write_file(trailingslashit($location['path']) . $csv_current_name, $csv)) {
                throw new Exception('Zapisivanje CSV datoteke nije uspjelo (provjerite dozvole na wp-content/uploads/cjenik).');
            }

            $urls['csv_url'] = trailingslashit($location['url']) . $csv_current_name;
            $urls['csv_file'] = $csv_current_name;
            $urls['csv_download_name'] = $csv_archive_name;
        }

        if ($do_xml) {
            $xml = cjenik_build_xml($rows, $meta);
            $xml_archive_name = cjenik_build_filename('xml', $meta, true);
            $xml_current_name = cjenik_build_filename('xml', $meta, false);

            if (!cjenik_write_file(trailingslashit($location['path']) . $xml_archive_name, $xml)
                || !cjenik_write_file(trailingslashit($location['path']) . $xml_current_name, $xml)) {
                throw new Exception('Zapisivanje XML datoteke nije uspjelo (provjerite dozvole na wp-content/uploads/cjenik).');
            }

            $urls['xml_url'] = trailingslashit($location['url']) . $xml_current_name;
            $urls['xml_file'] = $xml_current_name;
            $urls['xml_download_name'] = $xml_archive_name;
        }

        update_option(CJENIK_LAST_GENERATED_OPTION, time());
        update_option(CJENIK_LAST_FILES_OPTION, $urls, false);
        delete_option(CJENIK_LAST_ERROR_OPTION);
        delete_option(CJENIK_DIRTY_OPTION);

        cjenik_cleanup_old_files();

        return $urls;
    } catch (Throwable $e) {
        update_option(CJENIK_LAST_ERROR_OPTION, $e->getMessage(), false);
        cjenik_notify_failure($e->getMessage());
        return false;
    }
}

function cjenik_notify_failure($message) {
    if (get_option(CJENIK_NOTIFY_ENABLED_OPTION, 'no') !== 'yes') {
        return;
    }

    $email = get_option(CJENIK_NOTIFY_EMAIL_OPTION, get_option('admin_email'));
    if (!is_email($email)) {
        return;
    }

    wp_mail(
        $email,
        sprintf('[%s] Greška pri generiranju cjenika', get_bloginfo('name')),
        "Generiranje cjenika nije uspjelo.\n\nGreška: " . $message . "\n\nVrijeme: " . wp_date('d.m.Y. H:i')
    );
}

// 8. Brisanje arhivskih datoteka starijih od zadanog broja dana
function cjenik_cleanup_old_files() {
    $location = cjenik_get_dir();
    $retention_days = (int) get_option(CJENIK_RETENTION_DAYS_OPTION, CJENIK_MIN_RETENTION_DAYS);
    if ($retention_days < CJENIK_MIN_RETENTION_DAYS) {
        $retention_days = CJENIK_MIN_RETENTION_DAYS;
    }
    $cutoff = time() - ($retention_days * DAY_IN_SECONDS);

    $files = glob(trailingslashit($location['path']) . 'cjenik_*');
    if (!$files) {
        return;
    }

    foreach ($files as $file) {
        if (strpos(basename($file), '_trenutni.') !== false) {
            continue;
        }
        if (filemtime($file) < $cutoff) {
            unlink($file);
        }
    }
}

// 9. Automatsko generiranje - dnevno prije 8:00 i odgođeno nakon promjene proizvoda.
add_action('init', 'cjenik_schedule_cron');
function cjenik_schedule_cron() {
    $time = get_option(CJENIK_CRON_TIME_OPTION, CJENIK_DEFAULT_CRON_TIME);
    if (!array_key_exists($time, cjenik_cron_time_choices())) {
        $time = CJENIK_DEFAULT_CRON_TIME;
    }

    $scheduled_time = get_option('sidrena_cijena_cjenik_scheduled_time', '');

    if (!wp_next_scheduled(CJENIK_CRON_HOOK) || $scheduled_time !== $time) {
        wp_clear_scheduled_hook(CJENIK_CRON_HOOK);

        list($hour, $minute) = array_map('intval', explode(':', $time));
        $now = new DateTimeImmutable('now', wp_timezone());
        $next = $now->setTime($hour, $minute, 0);
        if ($next <= $now) {
            $next = $next->modify('+1 day');
        }

        wp_schedule_event($next->getTimestamp(), 'daily', CJENIK_CRON_HOOK);
        update_option('sidrena_cijena_cjenik_scheduled_time', $time);
    }
}

add_action(CJENIK_CRON_HOOK, 'cjenik_maybe_generate');
add_action(CJENIK_REFRESH_HOOK, 'cjenik_maybe_generate');
function cjenik_maybe_generate() {
    if (get_option(CJENIK_ENABLED_OPTION, 'yes') === 'yes') {
        cjenik_generate();
    }
}

function cjenik_queue_refresh() {
    if (get_option(CJENIK_ENABLED_OPTION, 'yes') !== 'yes') {
        return;
    }

    update_option(CJENIK_DIRTY_OPTION, time(), false);

    if (!wp_next_scheduled(CJENIK_REFRESH_HOOK)) {
        wp_schedule_single_event(time() + 120, CJENIK_REFRESH_HOOK);
    }
}

add_action('woocommerce_update_product', 'cjenik_queue_refresh', 10, 0);
add_action('woocommerce_product_set_stock', 'cjenik_queue_refresh', 10, 0);
add_action('woocommerce_variation_set_stock', 'cjenik_queue_refresh', 10, 0);

add_action('init', 'cjenik_register_rewrite_rule');
function cjenik_register_rewrite_rule() {
    add_rewrite_rule('^cjenik-proizvoda\.csv$', 'index.php?sidrena_cjenik_download=csv', 'top');
    add_rewrite_rule('^cjenik-proizvoda\.xml$', 'index.php?sidrena_cjenik_download=xml', 'top');
}

add_filter('query_vars', 'cjenik_register_query_var');
function cjenik_register_query_var($vars) {
    $vars[] = 'sidrena_cjenik_download';
    return $vars;
}

function cjenik_get_public_url($format) {
    $format = strtolower((string) $format);
    if (!in_array($format, array('csv', 'xml'), true)) {
        return '';
    }

    if (get_option('permalink_structure')) {
        return home_url('/cjenik-proizvoda.' . $format);
    }

    return add_query_arg('sidrena_cjenik_download', $format, home_url('/'));
}

function cjenik_get_public_csv_url() {
    return cjenik_get_public_url('csv');
}

function cjenik_get_public_xml_url() {
    return cjenik_get_public_url('xml');
}

function cjenik_public_format_config($format) {
    $formats = array(
        'csv' => array(
            'option' => CJENIK_FORMAT_CSV_OPTION,
            'mime'   => 'text/csv; charset=utf-8',
            'label'  => 'CSV',
        ),
        'xml' => array(
            'option' => CJENIK_FORMAT_XML_OPTION,
            'mime'   => 'application/xml; charset=utf-8',
            'label'  => 'XML',
        ),
    );

    return isset($formats[$format]) ? $formats[$format] : null;
}

function cjenik_public_error($status, $message, $retry_after = 0) {
    status_header($status);
    nocache_headers();
    if ($retry_after > 0) {
        header('Retry-After: ' . (int) $retry_after);
    }
    header('Content-Type: text/plain; charset=utf-8');
    echo esc_html($message);
    exit;
}

add_action('template_redirect', 'cjenik_handle_public_download');
function cjenik_handle_public_download() {
    $format = strtolower((string) get_query_var('sidrena_cjenik_download'));
    $config = cjenik_public_format_config($format);
    if (!$config) {
        return;
    }

    if (get_option($config['option'], 'yes') !== 'yes') {
        cjenik_public_error(404, sprintf(__('%s cjenik nije uključen.', 'sidrena-cijena-i-cjenik-aplitap'), $config['label']));
    }

    if (get_option(CJENIK_DIRTY_OPTION) && !get_transient(CJENIK_GENERATION_LOCK)) {
        set_transient(CJENIK_GENERATION_LOCK, '1', MINUTE_IN_SECONDS);
        $refreshed = cjenik_generate();
        if ($refreshed !== false) {
            delete_transient(CJENIK_GENERATION_LOCK);
            wp_clear_scheduled_hook(CJENIK_REFRESH_HOOK);
        } else {
            set_transient(CJENIK_GENERATION_LOCK, '1', 5 * MINUTE_IN_SECONDS);
            cjenik_public_error(503, __('Cjenik se trenutačno ne može osvježiti. Pokušajte ponovno za nekoliko minuta.', 'sidrena-cijena-i-cjenik-aplitap'), 300);
        }
    }

    if (get_option(CJENIK_DIRTY_OPTION)) {
        cjenik_public_error(503, __('Cjenik se upravo osvježava. Pokušajte ponovno za minutu.', 'sidrena-cijena-i-cjenik-aplitap'), 60);
    }

    $last_files = get_option(CJENIK_LAST_FILES_OPTION, array());
    $file_key = $format . '_file';
    $download_key = $format . '_download_name';
    $filename = isset($last_files[$file_key]) ? basename($last_files[$file_key]) : '';
    $download_name = isset($last_files[$download_key]) ? basename($last_files[$download_key]) : $filename;
    $location = cjenik_get_dir();
    $path = $filename !== '' ? trailingslashit($location['path']) . $filename : '';

    if ($path === ''
        || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== $format
        || strpos($filename, 'cjenik_') !== 0
        || !is_readable($path)) {
        cjenik_public_error(404, sprintf(__('%s cjenik još nije generiran.', 'sidrena-cijena-i-cjenik-aplitap'), $config['label']));
    }

    $modified = filemtime($path);
    $etag = '"' . md5_file($path) . '"';
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $modified) . ' GMT');
    header('Cache-Control: public, max-age=60, must-revalidate');

    $client_etag = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim(wp_unslash($_SERVER['HTTP_IF_NONE_MATCH'])) : '';
    $client_modified = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? strtotime(wp_unslash($_SERVER['HTTP_IF_MODIFIED_SINCE'])) : false;
    if ($client_etag === $etag || ($client_etag === '' && $client_modified !== false && $client_modified >= $modified)) {
        status_header(304);
        exit;
    }

    header('Content-Type: ' . $config['mime']);
    header('Content-Disposition: attachment; filename="' . sanitize_file_name($download_name) . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Robots-Tag: noindex, nofollow', true);
    readfile($path);
    exit;
}

function cjenik_get_archive_files($format) {
    if (!in_array($format, array('csv', 'xml'), true)) {
        return array();
    }

    $location = cjenik_get_dir();
    $files = glob(trailingslashit($location['path']) . 'cjenik_*.' . $format);
    $archives = array();
    foreach ((array) $files as $file) {
        if (strpos(basename($file), '_trenutni.') === false && is_file($file)) {
            $archives[$file] = filemtime($file);
        }
    }
    arsort($archives);

    return $archives;
}

add_shortcode('sidrena_cjenik', 'cjenik_download_shortcode');
function cjenik_download_shortcode($atts) {
    $atts = shortcode_atts(
        array(
            'tekst' => '',
            'format' => 'csv',
            'arhiva' => 'ne',
        ),
        $atts,
        'sidrena_cjenik'
    );

    $requested_format = strtolower(sanitize_key($atts['format']));
    $formats = in_array($requested_format, array('oba', 'both'), true) ? array('csv', 'xml') : array($requested_format);
    $last_generated = (int) get_option(CJENIK_LAST_GENERATED_OPTION, 0);
    $last_files = get_option(CJENIK_LAST_FILES_OPTION, array());
    if (!$last_generated) {
        return '<span class="sidrena-cjenik-nedostupan">' . esc_html__('Cjenik trenutačno nije dostupan.', 'sidrena-cijena-i-cjenik-aplitap') . '</span>';
    }

    $html = '<div class="sidrena-cjenik-download">';
    $shown_formats = array();
    foreach ($formats as $format) {
        $config = cjenik_public_format_config($format);
        if (!$config || get_option($config['option'], 'yes') !== 'yes' || empty($last_files[$format . '_file'])) {
            continue;
        }

        $button_text = $atts['tekst'] !== '' && count($formats) === 1
            ? $atts['tekst']
            : sprintf(__('Preuzmi važeći cjenik (%s)', 'sidrena-cijena-i-cjenik-aplitap'), $config['label']);
        $html .= '<a style="margin-right:8px;" class="button sidrena-cjenik-button" href="' . esc_url(cjenik_get_public_url($format)) . '">' . esc_html($button_text) . '</a>';
        $shown_formats[] = $format;
    }

    if (!$shown_formats) {
        return '<span class="sidrena-cjenik-nedostupan">' . esc_html__('Odabrani format cjenika trenutačno nije dostupan.', 'sidrena-cijena-i-cjenik-aplitap') . '</span>';
    }

    $html .= '<small style="display:block;margin-top:6px;">' . esc_html(sprintf(__('Ažurirano: %s', 'sidrena-cijena-i-cjenik-aplitap'), wp_date('d.m.Y. H:i', $last_generated))) . '</small>';

    if (strtolower($atts['arhiva']) === 'da') {
        $location = cjenik_get_dir();
        foreach ($shown_formats as $format) {
            $archives = cjenik_get_archive_files($format);
            if (!$archives) {
                continue;
            }

            $html .= '<details style="margin-top:10px;"><summary>' . esc_html(sprintf(__('Arhiva %s cjenika', 'sidrena-cijena-i-cjenik-aplitap'), strtoupper($format))) . '</summary><ul>';
            foreach ($archives as $file => $modified) {
                $url = trailingslashit($location['url']) . rawurlencode(basename($file));
                $html .= '<li><a href="' . esc_url($url) . '">' . esc_html(wp_date('d.m.Y. H:i', $modified)) . '</a></li>';
            }
            $html .= '</ul></details>';
        }
    }

    return $html . '</div>';
}

register_activation_hook(dirname(__DIR__) . '/sidrena-cijena.php', 'cjenik_activate');
function cjenik_activate() {
    cjenik_register_rewrite_rule();
    flush_rewrite_rules(false);
    cjenik_schedule_cron();
    update_option(CJENIK_DB_VERSION_OPTION, SIDRENA_CIJENA_VERSION, false);
}

add_action('init', 'cjenik_maybe_upgrade', 20);
function cjenik_maybe_upgrade() {
    if (get_option(CJENIK_DB_VERSION_OPTION) === SIDRENA_CIJENA_VERSION) {
        return;
    }

    flush_rewrite_rules(false);
    update_option(CJENIK_DB_VERSION_OPTION, SIDRENA_CIJENA_VERSION, false);
    cjenik_queue_refresh();
}

register_deactivation_hook(dirname(__DIR__) . '/sidrena-cijena.php', 'cjenik_deactivate');
function cjenik_deactivate() {
    wp_clear_scheduled_hook(CJENIK_CRON_HOOK);
    wp_clear_scheduled_hook(CJENIK_REFRESH_HOOK);
    flush_rewrite_rules(false);
}

function cjenik_get_readiness($rows, $meta) {
    $report = array(
        'total'          => count($rows),
        'missing_price'  => 0,
        'missing_anchor' => 0,
        'missing_sku'    => 0,
        'missing_brand'  => 0,
        'missing_barcode'=> 0,
        'settings_ok'    => !empty($meta['object_type']) && !empty($meta['location_label']) && !empty($meta['storage_number']) && !empty($meta['address']),
    );

    foreach ($rows as $row) {
        if ($row['maloprodajna_cijena'] === '') {
            $report['missing_price']++;
        }
        if ($row['sidrena_cijena'] === '') {
            $report['missing_anchor']++;
        }
        if ($row['sifra_proizvoda'] === '') {
            $report['missing_sku']++;
        }
        if ($row['brend'] === '') {
            $report['missing_brand']++;
        }
        if ($row['barkod'] === '') {
            $report['missing_barcode']++;
        }
    }

    return $report;
}

// 10. Sadržaj taba "Cjenik" (poziva se iz glavne stranice postavki u sidrena-cijena.php)
function cjenik_render_tab_content() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $tab_url = admin_url('admin.php?page=sidrena-cijena&tab=cjenik');

    if (isset($_POST['cjenik_save']) && check_admin_referer('cjenik_settings_save', 'cjenik_nonce')) {
        $auto_enabled = isset($_POST[CJENIK_ENABLED_OPTION]) ? 'yes' : 'no';
        update_option(CJENIK_ENABLED_OPTION, $auto_enabled);
        if ($auto_enabled === 'no') {
            delete_option(CJENIK_DIRTY_OPTION);
            wp_clear_scheduled_hook(CJENIK_REFRESH_HOOK);
        }

        $location_label = isset($_POST[CJENIK_LOCATION_LABEL_OPTION]) ? sanitize_text_field(wp_unslash($_POST[CJENIK_LOCATION_LABEL_OPTION])) : '';
        update_option(CJENIK_LOCATION_LABEL_OPTION, $location_label);

        $object_type = isset($_POST[CJENIK_OBJECT_TYPE_OPTION]) ? sanitize_text_field(wp_unslash($_POST[CJENIK_OBJECT_TYPE_OPTION])) : CJENIK_DEFAULT_OBJECT_TYPE;
        update_option(CJENIK_OBJECT_TYPE_OPTION, $object_type !== '' ? $object_type : CJENIK_DEFAULT_OBJECT_TYPE);

        $storage_number = isset($_POST[CJENIK_STORAGE_NUMBER_OPTION]) ? sanitize_text_field(wp_unslash($_POST[CJENIK_STORAGE_NUMBER_OPTION])) : '';
        update_option(CJENIK_STORAGE_NUMBER_OPTION, $storage_number !== '' ? $storage_number : CJENIK_DEFAULT_STORAGE_NUMBER);

        $cron_time = isset($_POST[CJENIK_CRON_TIME_OPTION]) ? sanitize_text_field(wp_unslash($_POST[CJENIK_CRON_TIME_OPTION])) : CJENIK_DEFAULT_CRON_TIME;
        if (!array_key_exists($cron_time, cjenik_cron_time_choices())) {
            $cron_time = CJENIK_DEFAULT_CRON_TIME;
        }
        update_option(CJENIK_CRON_TIME_OPTION, $cron_time);

        $delimiter = isset($_POST[CJENIK_DELIMITER_OPTION]) ? wp_unslash($_POST[CJENIK_DELIMITER_OPTION]) : CJENIK_DEFAULT_DELIMITER;
        if (!array_key_exists($delimiter, cjenik_delimiter_choices())) {
            $delimiter = CJENIK_DEFAULT_DELIMITER;
        }
        update_option(CJENIK_DELIMITER_OPTION, $delimiter);

        update_option(CJENIK_FORMAT_CSV_OPTION, isset($_POST[CJENIK_FORMAT_CSV_OPTION]) ? 'yes' : 'no');
        update_option(CJENIK_FORMAT_XML_OPTION, isset($_POST[CJENIK_FORMAT_XML_OPTION]) ? 'yes' : 'no');
        if (!isset($_POST[CJENIK_FORMAT_CSV_OPTION]) && !isset($_POST[CJENIK_FORMAT_XML_OPTION])) {
            update_option(CJENIK_FORMAT_CSV_OPTION, 'yes');
        }

        $sale_label = isset($_POST[CJENIK_SALE_LABEL_OPTION]) ? sanitize_text_field(wp_unslash($_POST[CJENIK_SALE_LABEL_OPTION])) : CJENIK_DEFAULT_SALE_LABEL;
        update_option(CJENIK_SALE_LABEL_OPTION, $sale_label !== '' ? $sale_label : CJENIK_DEFAULT_SALE_LABEL);

        $retention_days = isset($_POST[CJENIK_RETENTION_DAYS_OPTION]) ? (int) $_POST[CJENIK_RETENTION_DAYS_OPTION] : CJENIK_MIN_RETENTION_DAYS;
        if ($retention_days < CJENIK_MIN_RETENTION_DAYS) {
            $retention_days = CJENIK_MIN_RETENTION_DAYS;
        }
        update_option(CJENIK_RETENTION_DAYS_OPTION, $retention_days);

        update_option(CJENIK_NOTIFY_ENABLED_OPTION, isset($_POST[CJENIK_NOTIFY_ENABLED_OPTION]) ? 'yes' : 'no');

        $notify_email = isset($_POST[CJENIK_NOTIFY_EMAIL_OPTION]) ? sanitize_email(wp_unslash($_POST[CJENIK_NOTIFY_EMAIL_OPTION])) : '';
        update_option(CJENIK_NOTIFY_EMAIL_OPTION, $notify_email !== '' ? $notify_email : get_option('admin_email'));

        cjenik_schedule_cron();
        cjenik_queue_refresh();

        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Postavke spremljene.', 'sidrena-cijena-i-cjenik-aplitap') . '</p></div>';
    }

    if (isset($_POST['cjenik_generate_now']) && check_admin_referer('cjenik_generate_now_action', 'cjenik_generate_nonce')) {
        $result = cjenik_generate();
        if ($result === false) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Generiranje cjenika nije uspjelo. Provjerite dozvole na wp-content/uploads.', 'sidrena-cijena-i-cjenik-aplitap') . '</p></div>';
        } else {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Cjenik je upravo generiran.', 'sidrena-cijena-i-cjenik-aplitap') . '</p></div>';
        }
    }

    $enabled = get_option(CJENIK_ENABLED_OPTION, 'yes');
    $location_label = get_option(CJENIK_LOCATION_LABEL_OPTION, '');
    $object_type = get_option(CJENIK_OBJECT_TYPE_OPTION, CJENIK_DEFAULT_OBJECT_TYPE);
    $storage_number = get_option(CJENIK_STORAGE_NUMBER_OPTION, CJENIK_DEFAULT_STORAGE_NUMBER);
    $cron_time = get_option(CJENIK_CRON_TIME_OPTION, CJENIK_DEFAULT_CRON_TIME);
    $delimiter = get_option(CJENIK_DELIMITER_OPTION, CJENIK_DEFAULT_DELIMITER);
    $format_csv = get_option(CJENIK_FORMAT_CSV_OPTION, 'yes');
    $format_xml = get_option(CJENIK_FORMAT_XML_OPTION, 'yes');
    $retention_days = get_option(CJENIK_RETENTION_DAYS_OPTION, CJENIK_MIN_RETENTION_DAYS);
    $notify_enabled = get_option(CJENIK_NOTIFY_ENABLED_OPTION, 'no');
    $notify_email = get_option(CJENIK_NOTIFY_EMAIL_OPTION, get_option('admin_email'));
    $sale_label = get_option(CJENIK_SALE_LABEL_OPTION, CJENIK_DEFAULT_SALE_LABEL);

    $meta = cjenik_get_meta();
    $dir = cjenik_get_dir();
    $last_files = get_option(CJENIK_LAST_FILES_OPTION, array());
    $csv_url = cjenik_get_public_csv_url();
    $xml_url = cjenik_get_public_xml_url();
    $last_generated = get_option(CJENIK_LAST_GENERATED_OPTION, '');
    $last_error = get_option(CJENIK_LAST_ERROR_OPTION, '');
    $next_scheduled = wp_next_scheduled(CJENIK_CRON_HOOK);
    $preview_rows = cjenik_collect_rows();
    $readiness = cjenik_get_readiness($preview_rows, $meta);
    ?>
    <p>
        <?php
        printf(
            /* translators: %s: NN reference */
            esc_html__('Automatski generira strojno čitljiv cjenik (XML i/ili CSV) prema Odluci o objavi cjenika proizvoda i usluga (%s), na snazi od 1.10.2026.', 'sidrena-cijena-i-cjenik-aplitap'),
            'NN 101/2026'
        );
        ?>
    </p>
    <div class="notice notice-warning inline" style="margin:0 0 20px;padding:12px;">
        <p style="margin:0;"><?php echo esc_html__('Ova funkcionalnost je tehnička pomoć temeljena na javno dostupnom tumačenju propisa. Preporučujemo provjeru s računovođom ili pravnikom prije 1.10.2026., osobito oko točnog popisa brendova, barkodova i jedinica mjere za vaše proizvode.', 'sidrena-cijena-i-cjenik-aplitap'); ?></p>
    </div>

    <?php if (empty($meta['address'])) : ?>
        <div class="notice notice-error inline" style="margin:0 0 20px;padding:12px;">
            <p style="margin:0;">
                <?php
                printf(
                    /* translators: %s: link to WooCommerce store address settings */
                    wp_kses(
                        __('Adresa trgovine nije postavljena u WooCommerce postavkama. Molimo unesite je pod <a href="%s">WooCommerce → Settings → General</a> - koristi se u nazivu datoteke cjenika.', 'sidrena-cijena-i-cjenik-aplitap'),
                        array('a' => array('href' => array()))
                    ),
                    esc_url(admin_url('admin.php?page=wc-settings&tab=general'))
                );
                ?>
            </p>
        </div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url($tab_url); ?>">
        <?php wp_nonce_field('cjenik_settings_save', 'cjenik_nonce'); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php echo esc_html__('Generiranje cjenika', 'sidrena-cijena-i-cjenik-aplitap'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr(CJENIK_ENABLED_OPTION); ?>" value="1" <?php checked($enabled, 'yes'); ?> />
                        <?php echo esc_html__('Automatski generiraj cjenik (dnevno prije 8:00 i nakon promjene proizvoda ili zalihe)', 'sidrena-cijena-i-cjenik-aplitap'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="cjenik-location-label"><?php echo esc_html__('Oznaka prodajnog mjesta / objekta', 'sidrena-cijena-i-cjenik-aplitap'); ?></label>
                </th>
                <td>
                    <input type="text" id="cjenik-location-label" name="<?php echo esc_attr(CJENIK_LOCATION_LABEL_OPTION); ?>" value="<?php echo esc_attr($location_label); ?>" class="regular-text" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>" />
                    <p class="description"><?php echo esc_html__('Kratka oznaka po kojoj se prepoznaje ova web trgovina/prodajno mjesto. Koristi se u nazivu datoteke cjenika. Ostavite prazno za naziv stranice.', 'sidrena-cijena-i-cjenik-aplitap'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="cjenik-object-type"><?php echo esc_html__('Vrsta prodajnog objekta', 'sidrena-cijena-i-cjenik-aplitap'); ?></label>
                </th>
                <td>
                    <input type="text" id="cjenik-object-type" name="<?php echo esc_attr(CJENIK_OBJECT_TYPE_OPTION); ?>" value="<?php echo esc_attr($object_type); ?>" class="regular-text" />
                    <p class="description"><?php echo esc_html__('Primjer: Internet trgovina. Ovaj podatak ulazi u naziv arhivske datoteke.', 'sidrena-cijena-i-cjenik-aplitap'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="cjenik-storage-number"><?php echo esc_html__('Broj skladišta', 'sidrena-cijena-i-cjenik-aplitap'); ?></label>
                </th>
                <td>
                    <input type="text" id="cjenik-storage-number" name="<?php echo esc_attr(CJENIK_STORAGE_NUMBER_OPTION); ?>" value="<?php echo esc_attr($storage_number); ?>" class="regular-text" />
                </td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Adresa (iz WooCommerce postavki)', 'sidrena-cijena-i-cjenik-aplitap'); ?></th>
                <td><?php echo esc_html($meta['address'] ? $meta['address'] : __('nije postavljeno', 'sidrena-cijena-i-cjenik-aplitap')); ?></td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="cjenik-cron-time"><?php echo esc_html__('Vrijeme dnevnog generiranja', 'sidrena-cijena-i-cjenik-aplitap'); ?></label>
                </th>
                <td>
                    <select id="cjenik-cron-time" name="<?php echo esc_attr(CJENIK_CRON_TIME_OPTION); ?>">
                        <?php foreach (cjenik_cron_time_choices() as $value => $option_label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($cron_time, $value); ?>><?php echo esc_html($option_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php echo esc_html__('Zakonski rok je 8:00 - preporučujemo sigurnosnu marginu. WordPress WP-Cron ovisi o posjetima web-stranici; za zajamčeno izvršavanje postavite pravi poslužiteljski cron koji redovito pokreće wp-cron.php.', 'sidrena-cijena-i-cjenik-aplitap'); ?></p>
                    <?php if ($next_scheduled) : ?>
                        <p class="description"><strong><?php echo esc_html__('Sljedeće planirano izvršavanje:', 'sidrena-cijena-i-cjenik-aplitap'); ?></strong> <?php echo esc_html(wp_date('d.m.Y. H:i', $next_scheduled)); ?></p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="cjenik-delimiter"><?php echo esc_html__('CSV razdjelnik', 'sidrena-cijena-i-cjenik-aplitap'); ?></label>
                </th>
                <td>
                    <select id="cjenik-delimiter" name="<?php echo esc_attr(CJENIK_DELIMITER_OPTION); ?>">
                        <?php foreach (cjenik_delimiter_choices() as $value => $option_label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($delimiter, $value); ?>><?php echo esc_html($option_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Format datoteka', 'sidrena-cijena-i-cjenik-aplitap'); ?></th>
                <td>
                    <label style="display:block;margin-bottom:6px;">
                        <input type="checkbox" name="<?php echo esc_attr(CJENIK_FORMAT_CSV_OPTION); ?>" value="1" <?php checked($format_csv, 'yes'); ?> />
                        <?php echo esc_html__('Generiraj CSV', 'sidrena-cijena-i-cjenik-aplitap'); ?>
                    </label>
                    <label style="display:block;">
                        <input type="checkbox" name="<?php echo esc_attr(CJENIK_FORMAT_XML_OPTION); ?>" value="1" <?php checked($format_xml, 'yes'); ?> />
                        <?php echo esc_html__('Generiraj XML', 'sidrena-cijena-i-cjenik-aplitap'); ?>
                    </label>
                    <p class="description"><?php echo esc_html__('Zakon prihvaća jedan od ova dva formata - generiranje oba je najsigurnija opcija.', 'sidrena-cijena-i-cjenik-aplitap'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="cjenik-sale-label"><?php echo esc_html__('Naziv posebnog oblika prodaje', 'sidrena-cijena-i-cjenik-aplitap'); ?></label>
                </th>
                <td>
                    <input type="text" id="cjenik-sale-label" name="<?php echo esc_attr(CJENIK_SALE_LABEL_OPTION); ?>" value="<?php echo esc_attr($sale_label); ?>" class="regular-text" />
                    <p class="description"><?php echo esc_html__('Upisuje se u cjenik za proizvode koje WooCommerce trenutačno označava kao snižene.', 'sidrena-cijena-i-cjenik-aplitap'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="cjenik-retention-days"><?php echo esc_html__('Čuvanje arhive (dana)', 'sidrena-cijena-i-cjenik-aplitap'); ?></label>
                </th>
                <td>
                    <input type="number" id="cjenik-retention-days" name="<?php echo esc_attr(CJENIK_RETENTION_DAYS_OPTION); ?>" value="<?php echo esc_attr($retention_days); ?>" min="<?php echo esc_attr(CJENIK_MIN_RETENTION_DAYS); ?>" max="365" class="small-text" />
                    <p class="description">
                        <?php
                        printf(
                            /* translators: %d: minimum days */
                            esc_html__('Zakonski minimum je %d dana. Možete postaviti i duže razdoblje.', 'sidrena-cijena-i-cjenik-aplitap'),
                            (int) CJENIK_MIN_RETENTION_DAYS
                        );
                        ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Email obavijest kod greške', 'sidrena-cijena-i-cjenik-aplitap'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr(CJENIK_NOTIFY_ENABLED_OPTION); ?>" value="1" <?php checked($notify_enabled, 'yes'); ?> />
                        <?php echo esc_html__('Pošalji email ako generiranje cjenika ne uspije', 'sidrena-cijena-i-cjenik-aplitap'); ?>
                    </label>
                    <p>
                        <input type="email" name="<?php echo esc_attr(CJENIK_NOTIFY_EMAIL_OPTION); ?>" value="<?php echo esc_attr($notify_email); ?>" class="regular-text" />
                    </p>
                </td>
            </tr>
        </table>
        <?php submit_button(__('Spremi promjene', 'sidrena-cijena-i-cjenik-aplitap'), 'primary', 'cjenik_save'); ?>
    </form>

    <hr />

    <h2><?php echo esc_html__('Provjera spremnosti', 'sidrena-cijena-i-cjenik-aplitap'); ?></h2>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin:12px 0 16px;">
        <?php
        $readiness_cards = array(
            array(__('Stavki u cjeniku', 'sidrena-cijena-i-cjenik-aplitap'), $readiness['total'], false),
            array(__('Bez maloprodajne cijene', 'sidrena-cijena-i-cjenik-aplitap'), $readiness['missing_price'], true),
            array(__('Bez sidrene cijene', 'sidrena-cijena-i-cjenik-aplitap'), $readiness['missing_anchor'], true),
            array(__('Bez šifre', 'sidrena-cijena-i-cjenik-aplitap'), $readiness['missing_sku'], true),
            array(__('Bez brenda', 'sidrena-cijena-i-cjenik-aplitap'), $readiness['missing_brand'], true),
            array(__('Bez barkoda', 'sidrena-cijena-i-cjenik-aplitap'), $readiness['missing_barcode'], true),
        );
        foreach ($readiness_cards as $card) :
            $card_color = $card[2] && $card[1] > 0 ? '#b32d2e' : '#008a20';
            ?>
            <div style="background:#fff;border:1px solid #dcdcde;padding:12px 14px;min-width:150px;">
                <strong style="font-size:20px;display:block;color:<?php echo esc_attr($card_color); ?>;"><?php echo (int) $card[1]; ?></strong>
                <?php echo esc_html($card[0]); ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if (!$readiness['settings_ok']) : ?>
        <div class="notice notice-error inline" style="margin:0 0 16px;padding:10px 12px;"><p style="margin:0;"><?php echo esc_html__('Dovršite vrstu objekta, oznaku objekta, broj skladišta i WooCommerce adresu prije objave cjenika.', 'sidrena-cijena-i-cjenik-aplitap'); ?></p></div>
    <?php elseif ($readiness['missing_price'] || $readiness['missing_anchor'] || $readiness['missing_sku']) : ?>
        <div class="notice notice-warning inline" style="margin:0 0 16px;padding:10px 12px;"><p style="margin:0;"><?php echo esc_html__('Cjenik se može generirati, ali označena prazna polja treba dopuniti prije javne objave.', 'sidrena-cijena-i-cjenik-aplitap'); ?></p></div>
    <?php else : ?>
        <div class="notice notice-success inline" style="margin:0 0 16px;padding:10px 12px;"><p style="margin:0;"><?php echo esc_html__('Osnovna obavezna polja i podaci o objektu su popunjeni.', 'sidrena-cijena-i-cjenik-aplitap'); ?></p></div>
    <?php endif; ?>

    <hr />

    <h2><?php echo esc_html__('Trenutni cjenik', 'sidrena-cijena-i-cjenik-aplitap'); ?></h2>
    <?php if ($last_error) : ?>
        <div class="notice notice-error inline" style="margin:0 0 12px;padding:10px 12px;"><p style="margin:0;"><strong><?php echo esc_html__('Zadnja pogreška:', 'sidrena-cijena-i-cjenik-aplitap'); ?></strong> <?php echo esc_html($last_error); ?></p></div>
    <?php endif; ?>
    <?php if ($last_generated) : ?>
        <p>
            <?php
            printf(
                /* translators: %s: date/time */
                esc_html__('Zadnji put generiran: %s', 'sidrena-cijena-i-cjenik-aplitap'),
                esc_html(wp_date('d.m.Y. H:i', $last_generated))
            );
            ?>
        </p>
    <?php else : ?>
        <p><?php echo esc_html__('Cjenik još nije generiran.', 'sidrena-cijena-i-cjenik-aplitap'); ?></p>
    <?php endif; ?>
    <?php if ($last_generated) : ?><p>
        <?php if ($format_csv === 'yes') : ?>
            <a class="button button-secondary" href="<?php echo esc_url($csv_url); ?>"><?php echo esc_html__('Preuzmi CSV cjenik', 'sidrena-cijena-i-cjenik-aplitap'); ?></a>
        <?php endif; ?>
        <?php if ($format_csv === 'yes' && $format_xml === 'yes') : ?>
            &nbsp;|&nbsp;
        <?php endif; ?>
        <?php if ($format_xml === 'yes') : ?>
            <a href="<?php echo esc_url($xml_url); ?>" target="_blank" rel="noopener"><?php echo esc_html__('XML cjenik', 'sidrena-cijena-i-cjenik-aplitap'); ?></a>
        <?php endif; ?>
    </p><?php endif; ?>
    <p class="description"><?php echo esc_html__('Ove poveznice moraju biti javno dostupne i ne smiju biti blokirane sigurnosnim dodatkom, firewallom ili Cloudflareom - provjerite otvara li se poveznica u anonimnom prozoru preglednika.', 'sidrena-cijena-i-cjenik-aplitap'); ?></p>
    <?php if ($format_csv === 'yes') : ?>
        <p><strong><?php echo esc_html__('Stalna javna CSV adresa:', 'sidrena-cijena-i-cjenik-aplitap'); ?></strong> <code><?php echo esc_html($csv_url); ?></code></p>
    <?php endif; ?>
    <?php if ($format_xml === 'yes') : ?>
        <p><strong><?php echo esc_html__('Stalna javna XML adresa:', 'sidrena-cijena-i-cjenik-aplitap'); ?></strong> <code><?php echo esc_html($xml_url); ?></code></p>
    <?php endif; ?>
    <p><?php echo wp_kses_post(__('Kratki kodovi: <code>[sidrena_cjenik]</code> za CSV, <code>[sidrena_cjenik format="xml"]</code> za XML te <code>[sidrena_cjenik format="oba" arhiva="da"]</code> za oba formata i njihove arhive.', 'sidrena-cijena-i-cjenik-aplitap')); ?></p>

    <form method="post" action="<?php echo esc_url($tab_url); ?>">
        <?php wp_nonce_field('cjenik_generate_now_action', 'cjenik_generate_nonce'); ?>
        <?php submit_button(__('Generiraj cjenik sada', 'sidrena-cijena-i-cjenik-aplitap'), 'secondary', 'cjenik_generate_now'); ?>
    </form>

    <hr />

    <?php
    $preview_total = count($preview_rows);
    $preview_limit = 100;
    $preview_shown = array_slice($preview_rows, 0, $preview_limit);
    ?>
    <h2><?php echo esc_html__('Pregled podataka', 'sidrena-cijena-i-cjenik-aplitap'); ?></h2>
    <p>
        <?php
        if ($preview_total > $preview_limit) {
            printf(
                /* translators: 1: shown count, 2: total count */
                esc_html__('Prikazano prvih %1$d od ukupno %2$d proizvoda/varijacija koji ulaze u cjenik.', 'sidrena-cijena-i-cjenik-aplitap'),
                (int) $preview_limit,
                (int) $preview_total
            );
        } else {
            printf(
                /* translators: %d: total count */
                esc_html__('Ukupno %d proizvoda/varijacija ulazi u cjenik.', 'sidrena-cijena-i-cjenik-aplitap'),
                (int) $preview_total
            );
        }
        ?>
    </p>

    <?php if ($preview_total === 0) : ?>
        <p><?php echo esc_html__('Nema proizvoda koji ulaze u cjenik.', 'sidrena-cijena-i-cjenik-aplitap'); ?></p>
    <?php else : ?>
        <div style="overflow-x:auto;">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Naziv', 'sidrena-cijena-i-cjenik-aplitap'); ?></th>
                        <th><?php echo esc_html__('Šifra', 'sidrena-cijena-i-cjenik-aplitap'); ?></th>
                        <th><?php echo esc_html__('Brend', 'sidrena-cijena-i-cjenik-aplitap'); ?></th>
                        <th><?php echo esc_html__('Jed. mjere', 'sidrena-cijena-i-cjenik-aplitap'); ?></th>
                        <th><?php echo esc_html__('Cijena/jed.', 'sidrena-cijena-i-cjenik-aplitap'); ?></th>
                        <th><?php echo esc_html__('Maloprodajna', 'sidrena-cijena-i-cjenik-aplitap'); ?></th>
                        <th><?php echo esc_html__('Akcija', 'sidrena-cijena-i-cjenik-aplitap'); ?></th>
                        <th><?php echo esc_html__('Naziv akcije', 'sidrena-cijena-i-cjenik-aplitap'); ?></th>
                        <th><?php echo esc_html__('Sidrena cijena', 'sidrena-cijena-i-cjenik-aplitap'); ?></th>
                        <th><?php echo esc_html__('Barkod', 'sidrena-cijena-i-cjenik-aplitap'); ?></th>
                        <th><?php echo esc_html__('Dostupnost', 'sidrena-cijena-i-cjenik-aplitap'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($preview_shown as $row) : ?>
                        <tr>
                            <td><?php echo esc_html($row['naziv']); ?></td>
                            <td><?php echo esc_html($row['sifra_proizvoda']); ?></td>
                            <td><?php echo esc_html($row['brend']); ?></td>
                            <td><?php echo esc_html($row['jedinica_mjere']); ?></td>
                            <td><?php echo esc_html($row['cijena_po_jedinici']); ?></td>
                            <td><?php echo esc_html($row['maloprodajna_cijena']); ?></td>
                            <td><?php echo esc_html($row['poseban_oblik_prodaje']); ?></td>
                            <td><?php echo esc_html($row['naziv_posebnog_oblika_prodaje']); ?></td>
                            <td>
                                <?php
                                if ($row['sidrena_cijena'] === '') {
                                    echo '<span style="color:#b32d2e;">' . esc_html__('nije uneseno', 'sidrena-cijena-i-cjenik-aplitap') . '</span>';
                                } else {
                                    echo esc_html($row['sidrena_cijena']);
                                }
                                ?>
                            </td>
                            <td>
                                <?php
                                if ($row['barkod'] === '') {
                                    echo '<span style="color:#b32d2e;">' . esc_html__('nije uneseno', 'sidrena-cijena-i-cjenik-aplitap') . '</span>';
                                } else {
                                    echo esc_html($row['barkod']);
                                }
                                ?>
                            </td>
                            <td><?php echo esc_html($row['dostupnost']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php
}
