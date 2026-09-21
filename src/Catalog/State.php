<?php

namespace MatijaGracanin\SidrenaCijena\Catalog;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Small database-backed compare-and-delete primitive for non-autoloaded options.
 */
final class AtomicOption
{
    public static function deleteIfEquals($name, $expected)
    {
        global $wpdb;

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
                $name,
                maybe_serialize($expected)
            )
        );

        if ($deleted) {
            wp_cache_delete($name, 'options');
            wp_cache_delete('alloptions', 'options');
        }

        return $deleted === 1;
    }

    public static function replaceIfEquals($name, $expected, $replacement)
    {
        global $wpdb;

        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                maybe_serialize($replacement),
                $name,
                maybe_serialize($expected)
            )
        );
        if ($updated) {
            wp_cache_delete($name, 'options');
            wp_cache_delete('alloptions', 'options');
        }
        return $updated === 1;
    }
}

/**
 * One lock shared by manual, scheduled and compatibility generation entry points.
 */
final class GenerationLock
{
    private $optionName;
    private $ttl;
    private $ownedValue;

    public function __construct($optionName, $ttl)
    {
        $this->optionName = $optionName;
        $this->ttl = max(60, (int) $ttl);
        $this->ownedValue = null;
    }

    public function acquire()
    {
        $candidate = array(
            'token' => function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('cjenik-', true),
            'expires_at' => time() + $this->ttl,
        );

        if (add_option($this->optionName, $candidate, '', 'no')) {
            $this->ownedValue = $candidate;
            return true;
        }

        $current = get_option($this->optionName, null);
        if (!is_array($current) || empty($current['expires_at']) || (int) $current['expires_at'] >= time()) {
            return false;
        }

        if (!AtomicOption::deleteIfEquals($this->optionName, $current)) {
            return false;
        }

        if (!add_option($this->optionName, $candidate, '', 'no')) {
            return false;
        }

        $this->ownedValue = $candidate;
        return true;
    }

    public function release()
    {
        if ($this->ownedValue === null) {
            return;
        }

        AtomicOption::deleteIfEquals($this->optionName, $this->ownedValue);
        $this->ownedValue = null;
    }

    public function refresh()
    {
        if ($this->ownedValue === null) {
            return false;
        }

        $replacement = $this->ownedValue;
        $replacement['expires_at'] = time() + $this->ttl;
        if (!AtomicOption::replaceIfEquals($this->optionName, $this->ownedValue, $replacement)) {
            return false;
        }
        $this->ownedValue = $replacement;
        return true;
    }
}

/**
 * Versioned dirty state prevents an older generation from clearing a newer edit.
 */
final class DirtyState
{
    private $optionName;

    public function __construct($optionName)
    {
        $this->optionName = $optionName;
    }

    public function mark()
    {
        $value = array(
            'token' => function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('dirty-', true),
            'updated_at' => time(),
        );
        update_option($this->optionName, $value, false);
        return $value;
    }

    public function snapshot()
    {
        return get_option($this->optionName, false);
    }

    public function clearIfUnchanged($snapshot)
    {
        if ($snapshot === false) {
            return true;
        }

        return AtomicOption::deleteIfEquals($this->optionName, $snapshot);
    }

    public function isDirty()
    {
        return get_option($this->optionName, false) !== false;
    }
}
