<?php
/**
 * Pure helpers for unit fields (no WordPress calls, unit-testable).
 *
 * The backend owns field mapping and labels. These helpers only fill gaps
 * where the plugin must be robust against an older/newer backend:
 *
 * - derived "_formatted" companions for area fields the backend may send
 *   without one (currently pool_area → pool_area_formatted), formatted
 *   exactly like the backend's formatArea() ("79,5 m²", empty when 0/null)
 * - a numeric sort value for floor columns, so client-side sorting orders
 *   GG < UG < EG < OG < DG instead of comparing label strings
 *
 * Floor labels ("GG", "EG+OG", "1. OG") are NEVER computed here — the
 * plugin always renders floor_label / floor_to_label as sent.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ImmoAdmin_Unit_Fields {

    /**
     * Raw area keys that get a derived "<key>_formatted" meta when the
     * backend sends the raw key but no formatted variant.
     */
    public static function derived_area_fields() {
        return array('pool_area');
    }

    /**
     * Meta keys whose column should sort by the numeric `floor` value.
     */
    public static function floor_sort_keys() {
        return array('floor', 'floor_label');
    }

    /**
     * Format an area like the backend's formatArea():
     * 80 → "80 m²", 79.9 → "79,9 m²", 1234.5 → "1.234,5 m²",
     * null / "" / 0 / non-numeric → "".
     *
     * @param mixed $value
     * @return string
     */
    public static function format_area($value) {
        if ($value === null || $value === '' || is_bool($value) || is_array($value)) {
            return '';
        }
        if (!is_numeric($value)) {
            return '';
        }

        $num = (float) $value;
        if ($num == 0.0 || is_nan($num) || is_infinite($num)) {
            return '';
        }

        if (fmod($num, 1.0) != 0.0) {
            $formatted = number_format($num, 2, ',', '.');
            // "79,90" → "79,9", "10,00" → "10"
            $formatted = rtrim(rtrim($formatted, '0'), ',');
        } else {
            $formatted = number_format($num, 0, ',', '.');
        }

        return $formatted . ' m²';
    }

    /**
     * Add derived fields to a unit's metaFields before they are written.
     *
     * Only acts when the raw key is PRESENT (null included, which yields an
     * empty formatted value so stale meta gets cleared) and the backend did
     * not send its own formatted value. An older backend that does not send
     * the raw key at all leaves the array untouched.
     *
     * @param array $meta
     * @return array
     */
    public static function augment_meta_fields($meta) {
        if (!is_array($meta)) {
            return array();
        }

        foreach (self::derived_area_fields() as $key) {
            $formatted_key = $key . '_formatted';
            if (array_key_exists($key, $meta) && !array_key_exists($formatted_key, $meta)) {
                $meta[$formatted_key] = self::format_area($meta[$key]);
            }
        }

        return $meta;
    }

    /**
     * True when a column's sort meta key means "sort by floor".
     *
     * @param string $key
     * @return bool
     */
    public static function is_floor_sort_key($key) {
        return in_array((string) $key, self::floor_sort_keys(), true);
    }

    /**
     * Sort value for a floor column: the integer floor (GG = negative,
     * maisonettes = lower floor) or the given fallback when the unit has
     * no numeric floor.
     *
     * @param mixed  $floor_meta Raw `floor` post meta.
     * @param string $fallback   Value to use when floor is empty/non-numeric.
     * @return string
     */
    public static function floor_sort_value($floor_meta, $fallback = '') {
        if (is_int($floor_meta)) {
            return (string) $floor_meta;
        }
        if (is_string($floor_meta) && $floor_meta !== '' && is_numeric(trim($floor_meta))) {
            return (string) (int) round((float) trim($floor_meta));
        }
        if (is_float($floor_meta)) {
            return (string) (int) round($floor_meta);
        }
        return (string) $fallback;
    }
}
