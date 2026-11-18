<?php
/** Publication integration with a temporary uploads directory and option store. */
require __DIR__ . '/compliance.php';

use SidrenaCijenaCjenik\Catalog\CatalogEngine;

function add_option($name, $value, ...$args) {
    if (array_key_exists($name, $GLOBALS['test_options'])) { return false; }
    $GLOBALS['test_options'][$name] = $value;
    return true;
}
function delete_option($name) { unset($GLOBALS['test_options'][$name]); return true; }
function wp_cache_delete(...$args) {}
function maybe_serialize($value) { return is_array($value) ? serialize($value) : $value; }
function get_bloginfo($name) { return 'Testna trgovina'; }

final class CatalogTestDatabase
{
    public $options = 'test_options';
    public function prepare($query, ...$values) { return array($query, $values); }
    public function query($prepared) {
        list($query, $values) = $prepared;
        if (strpos($query, 'DELETE') === 0) {
            list($name, $expected) = $values;
            if (maybe_serialize(get_option($name)) !== $expected) { return 0; }
            delete_option($name);
            return 1;
        }
        list($replacement, $name, $expected) = $values;
        if (maybe_serialize(get_option($name)) !== $expected) { return 0; }
        update_option($name, unserialize($replacement));
        return 1;
    }
}
$wpdb = new CatalogTestDatabase();
$test_upload_dir = sys_get_temp_dir() . '/sidrena-publication-' . uniqid();
mkdir($test_upload_dir);
$directory = cjenik_get_dir()['path'];
$products = array(1 => new CatalogTestProduct(1, 'simple', '8.00'));
$products[1]->meta['_anchor_price'] = '10.00';
$test_options = array(
    'sidrena_cijena_cjenik_trader_id' => '12345678901',
    'woocommerce_store_address' => 'Testna 1',
    'woocommerce_store_city' => 'Zagreb',
    'sidrena_cijena_cjenik_kind' => 'roba',
    CJENIK_DELIMITER_OPTION => ',', // Legacy setting must no longer change CSV format.
);
$test_upload_dir = sys_get_temp_dir() . '/sidrena-publication-' . uniqid();
mkdir($test_upload_dir);
$directory = cjenik_get_dir()['path'];

try {
    $engine = new CatalogEngine();
    $first = $engine->generate();
    check(is_array($first), 'Complete merchant/product data must publish both formats.');
    $firstCsv = file_get_contents($directory . '/' . $first['csv_file']);
    check(strpos($firstCsv, "naziv;sifra_proizvoda;brend;neto_kolicina;") !== false, 'A legacy comma setting must not bypass the prescribed semicolon CSV delimiter.');
    $second = $engine->generate();
    check(is_array($second) && $second['csv_download_name'] !== $first['csv_download_name'], 'Repeated same-minute publication must preserve separate archives.');
    check(is_file($directory . '/' . $first['csv_download_name']), 'Replacing a catalog must retain its archive.');
    check(isset(get_option('sidrena_cijena_cjenik_replaced_at')[ $first['csv_download_name'] ]), 'Replacement time must be recorded for retention.');

    $filesBeforeFailure = get_option(CJENIK_LAST_FILES_OPTION);
    $currentBeforeFailure = file_get_contents($directory . '/' . $second['csv_file']);
    unset($test_options['sidrena_cijena_cjenik_trader_id']);
    check($engine->generate() === false, 'Missing merchant identification must block publication.');
    check(get_option(CJENIK_LAST_FILES_OPTION) === $filesBeforeFailure, 'A failed generation must not replace the published file pointers.');
    check(file_get_contents($directory . '/' . $second['csv_file']) === $currentBeforeFailure, 'A failed generation must preserve the last complete public catalog.');
    check(get_option(CJENIK_GENERATION_LOCK, false) === false, 'A failed generation must release its generation lock.');
    $test_options['sidrena_cijena_cjenik_trader_id'] = '12345678901';

    // Gaps in single products publish the rest of the price list, with warnings.
    unset($products[1]->meta['_anchor_price']);
    $products[2] = new CatalogTestProduct(2, 'simple', '');
    $products[2]->meta['_anchor_price'] = '7.00';
    $products[3] = new CatalogTestProduct(3, 'simple', '5.00');
    $products[3]->meta['_anchor_price'] = '-1.00';
    $products[4] = new CatalogTestProduct(4, 'simple', '3.00');
    $products[4]->meta['_anchor_price'] = '3.00';
    $products[4]->meta[CJENIK_UNIT_META_KEY] = 'kg';
    $products[4]->meta['_cjenik_unit_required'] = 'yes';
    $products[5] = new CatalogTestProduct(5, 'simple', '2.00');
    $products[5]->meta['_anchor_price'] = '2.00';
    $products[5]->meta['_cjenik_net_quantity'] = '-3';
    $gapped = $engine->generate();
    check(is_array($gapped), 'Gaps in individual products must not block publication.');
    check(get_option(CJENIK_LAST_ERROR_OPTION, '') === '', 'A price list published with gaps is not an error.');
    $published = array();
    foreach (array_slice(explode("\n", trim(file_get_contents($directory . '/' . $gapped['csv_file']))), 1) as $line) {
        $fields = str_getcsv($line, ';', '"', '');
        $published[$fields[0]] = array_combine(CatalogEngine::columns(), $fields);
    }
    check(!isset($published['Product 2']), 'A product without a retail price has no price in force and must be left out.');
    check(isset($published['Product 1']) && $published['Product 1']['bazna_cijena'] === '', 'A product on sale without a base price must be listed with an empty base price.');
    check(isset($published['Product 3']) && $published['Product 3']['bazna_cijena'] === '', 'A negative base price must be published empty, never as entered.');
    check(isset($published['Product 4']) && $published['Product 4']['cijena_po_jedinici'] === '' && $published['Product 4']['maloprodajna_cijena'] !== '', 'A missing required unit price must not hold back the product.');
    check(isset($published['Product 5']) && $published['Product 5']['neto_kolicina'] === '' && $published['Product 5']['cijena_po_jedinici'] === '', 'An invalid net quantity must be published empty, with no unit price derived from it.');
    $warnings = get_option(CJENIK_LAST_WARNINGS_OPTION);
    check($warnings['skipped_no_price']['count'] === 1 && $warnings['skipped_no_price']['names'] === array('Product 2'), 'Left-out products must be reported by name.');
    check($warnings['missing_base_price']['count'] === 2 && $warnings['missing_unit_price']['count'] === 1 && $warnings['invalid_quantity']['count'] === 1, 'Every published gap must be counted by kind.');

    $products[1]->meta['_anchor_price'] = '10.00';
    unset($products[2], $products[3], $products[4], $products[5]);
    check(is_array($engine->generate()) && get_option(CJENIK_LAST_WARNINGS_OPTION, false) === false, 'A complete price list must clear the previous warnings.');
    $test_options['sidrena_cijena_cjenik_kind'] = 'usluge';
    $services = $engine->generate();
    check(is_array($services), 'Services must publish using their own schema.');
    $serviceCsv = file_get_contents($directory . '/' . $services['csv_file']);
    check(strpos($serviceCsv, "naziv;maloprodajna_cijena;poseban_oblik_prodaje;bazna_cijena") !== false, 'Service CSV must use the shorter prescribed schema.');
} finally {
    foreach (glob($directory . '/*') as $file) { unlink($file); }
    rmdir($directory);
    rmdir($test_upload_dir);
}

echo "OK: publication, same-minute archives, delimiter, service schema, partial-data publication and failed-generation preservation checks passed.\n";
