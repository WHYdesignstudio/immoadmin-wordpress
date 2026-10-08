<?php
/**
 * ImmoAdmin filter widgets — data helpers (v2.14.0).
 *
 * Everything the filter elements and the units-table need to agree on:
 * group names, which values a table row exposes, how options and range
 * bounds are derived from the synced units, and how numbers are formatted.
 *
 * Deliberately free of \Bricks\Element so it can be unit-tested and loaded
 * before Bricks. The matching itself runs client-side; its JS twin lives in
 * bricks/assets/js/filter-logic.js (number formatting is implemented in both
 * places and tested against the same cases).
 *
 * @package ImmoAdmin\Bricks
 */

if (!defined('ABSPATH')) {
    exit;
}

if (class_exists('ImmoAdmin_Filter_Data')) {
    return;
}

class ImmoAdmin_Filter_Data {

    /** Sentinel the backend stores for "GG" (Gartengeschoss), see packages/shared/src/floors.ts */
    const FLOOR_GG = -10;

    /** Row attribute carrying the filter values (JSON). Only emitted when a group is set. */
    const ROW_ATTR = 'data-immoadmin-filter-values';

    /**
     * Status values whose prices may appear in the DOM. Same allowlist as
     * ImmoAdmin_Visibility::$public_statuses — everything else (reserved,
     * sold, rented, anything the backend adds later) fails closed.
     */
    const PUBLIC_STATUSES = array('available', '');

    /** Sun path order, as in the design mockup: Ost · Süd · West · Nord */
    const ORIENTATION_ORDER = array('east', 'south', 'west', 'north');

    /**
     * Keys every filterable row carries. Custom keys come on top (see
     * ImmoAdmin_Units_Table: "Zusätzliche Felder" + keys registered by
     * filter elements rendered earlier on the page).
     */
    public static function standard_keys() {
        return array(
            'building_name', 'floor', 'floor_to', 'orientation', 'room_count',
            'living_area', 'usable_area', 'purchase_price', 'rent_cold', 'rent_warm', 'price_per_sqm',
        );
    }

    /** Numeric keys where the sync writes 0 for "no value" (formatNumber() in sync-payload.ts). */
    public static function zero_means_empty_keys() {
        return array('room_count', 'living_area', 'usable_area', 'purchase_price', 'rent_cold', 'rent_warm', 'price_per_sqm');
    }

    // -----------------------------------------------------------------
    // Field definitions (builder selects)
    // -----------------------------------------------------------------

    /** Fields of "ImmoAdmin Filter-Buttons": key => [label, match]. */
    public static function button_fields() {
        return array(
            'building_name' => array('label' => esc_html__('Haus (Gebäude)', 'immoadmin'), 'match' => 'text'),
            'floor'         => array('label' => esc_html__('Geschoss', 'immoadmin'), 'match' => 'floor'),
            'orientation'   => array('label' => esc_html__('Ausrichtung', 'immoadmin'), 'match' => 'list'),
            'room_count'    => array('label' => esc_html__('Zimmer', 'immoadmin'), 'match' => 'number'),
            '__custom'      => array('label' => esc_html__('Eigenes Feld …', 'immoadmin'), 'match' => 'text'),
        );
    }

    /** Fields of "ImmoAdmin Filter-Bereich": key => [label, kind (area|price|number)]. */
    public static function range_fields() {
        return array(
            'living_area'    => array('label' => esc_html__('Wohnfläche', 'immoadmin'), 'kind' => 'area'),
            'usable_area'    => array('label' => esc_html__('Nutzfläche', 'immoadmin'), 'kind' => 'area'),
            'purchase_price' => array('label' => esc_html__('Kaufpreis', 'immoadmin'), 'kind' => 'price'),
            'rent_cold'      => array('label' => esc_html__('Miete (kalt)', 'immoadmin'), 'kind' => 'price'),
            'rent_warm'      => array('label' => esc_html__('Miete (warm)', 'immoadmin'), 'kind' => 'price'),
            'price_per_sqm'  => array('label' => esc_html__('Preis pro m²', 'immoadmin'), 'kind' => 'price'),
            '__custom'       => array('label' => esc_html__('Eigenes Feld (Zahl) …', 'immoadmin'), 'kind' => 'number'),
        );
    }

    // -----------------------------------------------------------------
    // Small pure helpers
    // -----------------------------------------------------------------

    /**
     * Group names connect filters and tables, so both sides must normalise
     * the same way: lower case, a-z 0-9 _ -, max 64 chars. "Wohnungen " and
     * "wohnungen" are the same group.
     */
    public static function sanitize_group($raw) {
        if (!is_scalar($raw)) {
            return '';
        }
        $group = strtolower(trim((string) $raw));
        $group = preg_replace('/[^a-z0-9_-]+/', '-', $group);
        $group = trim((string) $group, '-');
        return substr($group, 0, 64);
    }

    /** Meta key typed by the designer → safe meta key or ''. */
    public static function sanitize_meta_key($raw) {
        if (!is_scalar($raw)) {
            return '';
        }
        $key = strtolower(trim((string) $raw));
        return preg_match('/^[a-z0-9_]{1,64}$/', $key) ? $key : '';
    }

    /** "a, b ,c" → ['a','b','c'] (valid meta keys only, unique). */
    public static function parse_key_list($raw) {
        if (is_array($raw)) {
            $parts = $raw;
        } elseif (is_scalar($raw)) {
            $parts = preg_split('/[\s,;]+/', (string) $raw);
        } else {
            return array();
        }
        $out = array();
        foreach ($parts as $p) {
            $k = self::sanitize_meta_key($p);
            if ($k !== '' && !in_array($k, $out, true)) {
                $out[] = $k;
            }
        }
        return array_slice($out, 0, 20);
    }

    /** Stored floor ("-10", 0, "") → int or null. 0 is EG, not "empty". */
    public static function to_floor($value) {
        if ($value === null || $value === '' || is_bool($value) || !is_numeric($value)) {
            return null;
        }
        return (int) round((float) $value);
    }

    /** Physical order: UG < GG < EG < OG < DG (GG is stored as -10). */
    public static function floor_sort_key($floor) {
        return ((int) $floor === self::FLOOR_GG) ? -0.5 : (float) $floor;
    }

    /** PHP port of floorIntToLabel() (packages/shared/src/floors.ts). */
    public static function floor_label($floor) {
        $f = self::to_floor($floor);
        if ($f === null) {
            return '-';
        }
        $map = array(-3 => '3. UG', -2 => '2. UG', -1 => '1. UG', self::FLOOR_GG => 'GG', 0 => 'EG',
            96 => 'OG', 97 => '1. DG', 98 => '2. DG', 99 => 'DG');
        if (isset($map[$f])) {
            return $map[$f];
        }
        return $f < 0 ? abs($f) . '. UG' : $f . '. OG';
    }

    /**
     * Numeric meta → int|float|null. For the sync's numeric fields 0 means
     * "not set" (formatNumber() turns null into 0), so $zero_is_empty.
     */
    public static function to_number($value, $zero_is_empty = true) {
        if ($value === null || $value === '' || is_bool($value) || is_array($value) || !is_numeric($value)) {
            return null;
        }
        $n = (float) $value;
        if (!is_finite($n) || ($zero_is_empty && $n == 0.0)) {
            return null;
        }
        return (floor($n) == $n && abs($n) < PHP_INT_MAX) ? (int) $n : $n;
    }

    private static function lower($s) {
        $s = (string) $s;
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower(strtr($s, array('Ü' => 'ü', 'Ö' => 'ö', 'Ä' => 'ä')));
    }

    /**
     * Directions of a unit as canonical keys in sun-path order.
     *
     * The backend stores ONE value in `orientation` (a key like "south", or
     * free text from an import), while multi-direction units ("SW", "Süd/
     * West") carry every direction as a feature key in the `features` JSON.
     * Both are read, so "south,west" matches the Süd AND the West button.
     */
    public static function parse_orientation($orientation, $features = null) {
        static $map = null;
        if ($map === null) {
            $map = array(
                'n' => array('north'), 'nord' => array('north'), 'norden' => array('north'), 'north' => array('north'),
                's' => array('south'), 'süd' => array('south'), 'sued' => array('south'), 'süden' => array('south'), 'sueden' => array('south'), 'south' => array('south'),
                'o' => array('east'), 'e' => array('east'), 'ost' => array('east'), 'osten' => array('east'), 'east' => array('east'),
                'w' => array('west'), 'west' => array('west'), 'westen' => array('west'),
                'no' => array('north', 'east'), 'ne' => array('north', 'east'), 'nordost' => array('north', 'east'), 'northeast' => array('north', 'east'),
                'nw' => array('north', 'west'), 'nordwest' => array('north', 'west'), 'northwest' => array('north', 'west'),
                'so' => array('south', 'east'), 'se' => array('south', 'east'), 'südost' => array('south', 'east'), 'suedost' => array('south', 'east'), 'southeast' => array('south', 'east'),
                'sw' => array('south', 'west'), 'südwest' => array('south', 'west'), 'suedwest' => array('south', 'west'), 'southwest' => array('south', 'west'),
            );
        }

        $found = array();
        if (is_scalar($orientation) && trim((string) $orientation) !== '') {
            $text = self::lower($orientation);
            $text = preg_replace('/(ausrichtung|seitig|seite)/u', ' ', $text);
            foreach (preg_split('/[^\p{L}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) as $token) {
                if ($token === 'und' || $token === 'and') {
                    continue;
                }
                foreach ($map[$token] ?? array() as $dir) {
                    $found[$dir] = true;
                }
            }
        }

        if (is_string($features) && $features !== '') {
            $features = json_decode($features, true);
        }
        if (is_array($features)) {
            foreach ($features as $f) {
                if (is_string($f) && in_array($f, self::ORIENTATION_ORDER, true)) {
                    $found[$f] = true;
                }
            }
        }

        return array_values(array_filter(self::ORIENTATION_ORDER, function ($d) use ($found) {
            return isset($found[$d]);
        }));
    }

    public static function orientation_label($key) {
        $labels = array('north' => 'Nord', 'south' => 'Süd', 'east' => 'Ost', 'west' => 'West');
        return $labels[$key] ?? (string) $key;
    }

    /** 2.5 → "2,5", 3 → "3" */
    public static function format_plain_number($n) {
        $n = (float) $n;
        if (floor($n) == $n) {
            return number_format($n, 0, ',', '');
        }
        return rtrim(rtrim(number_format($n, 2, ',', ''), '0'), ',');
    }

    // -----------------------------------------------------------------
    // Sensitivity / redaction
    // -----------------------------------------------------------------

    /**
     * Price/document-like key? Delegates to ImmoAdmin_Visibility (the REST
     * redaction list — a superset of the units-table's column rule), with a
     * local fail-closed fallback if that class is missing.
     */
    public static function is_sensitive_key($key) {
        if (class_exists('ImmoAdmin_Visibility') && method_exists('ImmoAdmin_Visibility', 'is_sensitive_meta_key')) {
            return ImmoAdmin_Visibility::is_sensitive_meta_key((string) $key);
        }
        return (bool) preg_match('/(price|preis|miete|cost|kosten|kaution|deposit|income|commission|provision|document|dokument|expose)|(?:^|_)(vat|ust|tax|fee|rent|pdf|file)/i', (string) $key);
    }

    public static function is_public_status($status) {
        return in_array(strtolower(trim((string) $status)), self::PUBLIC_STATUSES, true);
    }

    // -----------------------------------------------------------------
    // Row values (units-table → data attribute)
    // -----------------------------------------------------------------

    /**
     * The values one table row exposes to the client-side filter.
     *
     * $meta: key => scalar (single values). $hide_sensitive: true for a
     * reserved/sold/rented unit (outside the builder) — every price-like key
     * becomes null, exactly as if the unit had no price. The price never
     * reaches the DOM, so there is nothing to read out of the attribute.
     */
    public static function values_from_meta(array $meta, array $extra_keys = array(), $hide_sensitive = false) {
        $get = function ($k) use ($meta) {
            $v = $meta[$k] ?? null;
            if (is_array($v)) {
                $v = reset($v);
            }
            return $v;
        };

        $values = array(
            'building_name' => is_scalar($get('building_name')) && trim((string) $get('building_name')) !== '' ? trim((string) $get('building_name')) : null,
            'floor'         => self::to_floor($get('floor')),
            'floor_to'      => self::to_floor($get('floor_to')),
            'orientation'   => self::parse_orientation($get('orientation'), $get('features')),
        );
        foreach (self::zero_means_empty_keys() as $k) {
            $values[$k] = self::to_number($get($k), true);
        }

        foreach ($extra_keys as $k) {
            $k = self::sanitize_meta_key($k);
            if ($k === '' || array_key_exists($k, $values)) {
                continue;
            }
            $v = $get($k);
            if ($v === null || $v === '' || !is_scalar($v)) {
                $values[$k] = null;
            } elseif (is_numeric($v)) {
                $values[$k] = self::to_number($v, false);
            } else {
                $values[$k] = trim((string) $v);
            }
        }

        if ($hide_sensitive) {
            foreach (array_keys($values) as $k) {
                if (self::is_sensitive_key($k)) {
                    $values[$k] = null;
                }
            }
        }

        return $values;
    }

    /** Same, read from post meta of $post_id. */
    public static function values_for_post($post_id, array $extra_keys = array(), $hide_sensitive = false) {
        $meta = array();
        $keys = array_merge(self::standard_keys(), array('features'), $extra_keys);
        foreach (array_unique($keys) as $k) {
            $meta[$k] = get_post_meta((int) $post_id, $k, true);
        }
        return self::values_from_meta($meta, $extra_keys, $hide_sensitive);
    }

    // -----------------------------------------------------------------
    // Custom keys registered by filter elements during this request
    // -----------------------------------------------------------------

    private static $registered_keys = array();

    /** A filter element on "Eigenes Feld" announces its key so tables rendered later expose it. */
    public static function register_custom_key($group, $key) {
        $group = self::sanitize_group($group);
        $key   = self::sanitize_meta_key($key);
        if ($group === '' || $key === '' || in_array($key, self::standard_keys(), true)) {
            return;
        }
        self::$registered_keys[$group][$key] = true;
    }

    public static function registered_keys($group) {
        $group = self::sanitize_group($group);
        return array_keys(self::$registered_keys[$group] ?? array());
    }

    public static function reset_registered_keys() {
        self::$registered_keys = array();
    }

    // -----------------------------------------------------------------
    // Options for "Filter-Buttons"
    // -----------------------------------------------------------------

    /**
     * Distinct options of a field across $rows (list of meta maps).
     *
     * @return array list of ['value' => string, 'label' => string]
     */
    public static function button_options($field, array $rows, array $args = array()) {
        switch ($field) {
            case 'building_name':
                return self::text_options($rows, 'building_name');
            case 'floor':
                return self::floor_options($rows);
            case 'orientation':
                return self::orientation_options($rows);
            case 'room_count':
                return self::room_options($rows, (float) ($args['group_from'] ?? 0));
            case '__custom':
                $key = self::sanitize_meta_key($args['key'] ?? '');
                return $key === '' ? array() : self::text_options($rows, $key);
        }
        return array();
    }

    private static function text_options(array $rows, $key) {
        $vals = array();
        foreach ($rows as $row) {
            $v = $row[$key] ?? null;
            if (is_scalar($v) && !is_bool($v)) {
                $v = trim((string) $v);
                if ($v !== '') {
                    $vals[$v] = true;
                }
            }
        }
        $vals = array_keys($vals);
        usort($vals, 'strnatcasecmp');
        return array_map(function ($v) { return array('value' => (string) $v, 'label' => (string) $v); }, $vals);
    }

    /**
     * Single floors only: a maisonette "GG+EG" contributes GG and EG, so the
     * buttons stay one-floor-per-button and the maisonette matches both.
     * Labels come from the data (floor_label of single-floor units,
     * floor_to_label for upper floors), falling back to the standard label.
     */
    private static function floor_options(array $rows) {
        $labels = array();
        $fallback_labels = array();
        foreach ($rows as $row) {
            $f  = self::to_floor($row['floor'] ?? null);
            $ft = self::to_floor($row['floor_to'] ?? null);
            if ($f !== null) {
                $label = is_scalar($row['floor_label'] ?? null) ? trim((string) $row['floor_label']) : '';
                $single = ($ft === null || $ft === $f) && $label !== '' && strpos($label, '+') === false;
                if ($single) {
                    $labels[$f] = $labels[$f] ?? $label;
                } else {
                    $fallback_labels[$f] = $fallback_labels[$f] ?? self::floor_label($f);
                }
            }
            if ($ft !== null && $ft !== $f) {
                $label = is_scalar($row['floor_to_label'] ?? null) ? trim((string) $row['floor_to_label']) : '';
                $fallback_labels[$ft] = $fallback_labels[$ft] ?? ($label !== '' ? $label : self::floor_label($ft));
            }
        }
        $all = $labels + $fallback_labels;
        $floors = array_keys($all);
        usort($floors, function ($a, $b) {
            return self::floor_sort_key($a) <=> self::floor_sort_key($b);
        });
        return array_map(function ($f) use ($all) {
            return array('value' => (string) $f, 'label' => (string) $all[$f]);
        }, $floors);
    }

    private static function orientation_options(array $rows) {
        $found = array();
        foreach ($rows as $row) {
            foreach (self::parse_orientation($row['orientation'] ?? null, $row['features'] ?? null) as $d) {
                $found[$d] = true;
            }
        }
        $out = array();
        foreach (self::ORIENTATION_ORDER as $d) {
            if (isset($found[$d])) {
                $out[] = array('value' => $d, 'label' => self::orientation_label($d));
            }
        }
        return $out;
    }

    /** Distinct room counts; with $group_from = 4 every unit with ≥ 4 rooms lands on one "4+" button. */
    private static function room_options(array $rows, $group_from = 0) {
        $vals = array();
        $has_grouped = false;
        foreach ($rows as $row) {
            $n = self::to_number($row['room_count'] ?? null, true);
            if ($n === null || $n < 0) {
                continue;
            }
            if ($group_from > 0 && $n >= $group_from) {
                $has_grouped = true;
                continue;
            }
            $vals[(string) $n] = (float) $n;
        }
        asort($vals, SORT_NUMERIC);
        $out = array();
        foreach ($vals as $n) {
            $out[] = array('value' => self::format_value_key($n), 'label' => self::format_plain_number($n));
        }
        if ($has_grouped) {
            $g = self::format_value_key($group_from);
            $out[] = array('value' => $g . '+', 'label' => self::format_plain_number($group_from) . '+');
        }
        return $out;
    }

    /** Machine value for a number: 3 → "3", 2.5 → "2.5". */
    private static function format_value_key($n) {
        $n = (float) $n;
        return floor($n) == $n ? (string) (int) $n : rtrim(rtrim(sprintf('%.4F', $n), '0'), '.');
    }

    /**
     * Designer's own option list (repeater) replaces the automatic one: it
     * fixes order, labels and which options appear. Values may combine
     * several data values with "|" (e.g. "1|2|3" labelled "OG"). An empty
     * label falls back to the automatic label of that value, then the value.
     */
    public static function apply_custom_options(array $auto, $custom) {
        if (!is_array($custom) || empty($custom)) {
            return $auto;
        }
        $auto_labels = array();
        foreach ($auto as $o) {
            $auto_labels[$o['value']] = $o['label'];
        }
        $out = array();
        $seen = array();
        foreach ($custom as $row) {
            if (!is_array($row)) {
                continue;
            }
            $value = isset($row['value']) && is_scalar($row['value']) ? trim(sanitize_text_field((string) $row['value'])) : '';
            if ($value === '') {
                continue;
            }
            $parts = array_values(array_filter(array_map('trim', explode('|', $value)), 'strlen'));
            $value = implode('|', $parts);
            if ($value === '' || isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $label = isset($row['label']) && is_scalar($row['label']) ? trim(sanitize_text_field((string) $row['label'])) : '';
            if ($label === '') {
                $label = $auto_labels[$value] ?? $value;
            }
            $out[] = array('value' => $value, 'label' => $label);
        }
        return empty($out) ? $auto : $out;
    }

    // -----------------------------------------------------------------
    // Range ("Filter-Bereich")
    // -----------------------------------------------------------------

    /**
     * Min/max of a numeric key across $rows. For a price-like key only units
     * with a public status count: otherwise the slider's end value would
     * reveal the price of a sold unit.
     *
     * @return array ['min' => float|null, 'max' => float|null, 'count' => int]
     */
    public static function range_values(array $rows, $key, $zero_is_empty = true) {
        $sensitive = self::is_sensitive_key($key);
        $min = null;
        $max = null;
        $count = 0;
        foreach ($rows as $row) {
            if ($sensitive && !self::is_public_status($row['status'] ?? '')) {
                continue;
            }
            $n = self::to_number($row[$key] ?? null, $zero_is_empty);
            if ($n === null) {
                continue;
            }
            $count++;
            $min = $min === null ? $n : min($min, $n);
            $max = $max === null ? $n : max($max, $n);
        }
        return array('min' => $min === null ? null : (float) $min, 'max' => $max === null ? null : (float) $max, 'count' => $count);
    }

    /** A step that gives "nice" ends: span 84 → 1, span 480 000 → 10 000. */
    public static function nice_step($min, $max) {
        $span = abs((float) $max - (float) $min);
        if ($span <= 0) {
            return 1.0;
        }
        $exp = (int) floor(log10($span)) - 1;
        return (float) max(1, pow(10, $exp));
    }

    /**
     * Final slider config. Manual min/max/step (strings from the builder,
     * '' = automatic) win; automatic ends are rounded OUTWARD to the step so
     * every unit is inside the initial range.
     *
     * @return array ['min','max','step'] floats, or null if there is no data and no manual range
     */
    public static function range_config($values, $manual_min = '', $manual_max = '', $manual_step = '') {
        $data_min = $values['min'] ?? null;
        $data_max = $values['max'] ?? null;

        $step = (is_numeric($manual_step) && (float) $manual_step > 0)
            ? (float) $manual_step
            : self::nice_step($data_min ?? 0, $data_max ?? 0);

        $min = is_numeric($manual_min) ? (float) $manual_min : ($data_min === null ? null : floor($data_min / $step) * $step);
        $max = is_numeric($manual_max) ? (float) $manual_max : ($data_max === null ? null : ceil($data_max / $step) * $step);

        if ($min === null || $max === null) {
            return null;
        }
        if ($max < $min) {
            list($min, $max) = array($max, $min);
        }
        if ($max == $min) {
            $max = $min + $step;
        }
        return array('min' => (float) $min, 'max' => (float) $max, 'step' => (float) $step);
    }

    /**
     * Value label. Mirror of format() in filter-logic.js — keep both in sync
     * (tests/fixtures/format-cases.json runs against both).
     *
     * $fmt: mode 'thousands' (1.234.567) | 'k' (280k, 1,25 Mio.) | 'plain' (1234567),
     *       decimals (int, default 0), prefix, suffix.
     */
    public static function format_value($value, array $fmt = array()) {
        if (!is_numeric($value)) {
            return '';
        }
        $v        = (float) $value;
        $mode     = $fmt['mode'] ?? 'thousands';
        $decimals = max(0, min(4, (int) ($fmt['decimals'] ?? 0)));
        $prefix   = (string) ($fmt['prefix'] ?? '');
        $suffix   = (string) ($fmt['suffix'] ?? '');

        if ($mode === 'k' && abs($v) >= 1000000) {
            $text = self::strip_zeros(number_format($v / 1000000, 2, ',', '.')) . ' Mio.';
        } elseif ($mode === 'k' && abs($v) >= 1000) {
            $text = self::strip_zeros(number_format($v / 1000, 1, ',', '.')) . 'k';
        } elseif ($mode === 'plain') {
            $text = number_format($v, $decimals, ',', '');
        } else {
            $text = number_format($v, $decimals, ',', '.');
        }
        return $prefix . $text . $suffix;
    }

    private static function strip_zeros($s) {
        return strpos($s, ',') === false ? $s : rtrim(rtrim($s, '0'), ',');
    }

    /** Defaults per field kind: prices "280k", areas "70 m²". */
    public static function default_format($kind) {
        if ($kind === 'price') {
            return array('mode' => 'k', 'decimals' => 0, 'prefix' => '', 'suffix' => '');
        }
        if ($kind === 'area') {
            return array('mode' => 'thousands', 'decimals' => 0, 'prefix' => '', 'suffix' => ' m²');
        }
        return array('mode' => 'thousands', 'decimals' => 0, 'prefix' => '', 'suffix' => '');
    }

    // -----------------------------------------------------------------
    // Data source (DB, cached)
    // -----------------------------------------------------------------

    private static $rows_cache = array();

    /**
     * All published units as meta maps (only the keys filters need). One
     * query, cached per request and per sync run (the transient key carries
     * the last-sync timestamp, so a new sync shows new values immediately).
     */
    public static function fetch_rows(array $extra_keys = array()) {
        $keys = array_values(array_unique(array_merge(
            self::standard_keys(),
            array('status', 'floor_label', 'floor_to_label', 'features'),
            self::parse_key_list($extra_keys)
        )));
        sort($keys);
        $sig = md5(implode(',', $keys));
        if (isset(self::$rows_cache[$sig])) {
            return self::$rows_cache[$sig];
        }

        $cache_key = 'immoadmin_frows_' . md5((string) get_option('immoadmin_last_sync', '') . '|' . IMMOADMIN_VERSION . '|' . $sig);
        $cached = function_exists('get_transient') ? get_transient($cache_key) : false;
        if (is_array($cached)) {
            return self::$rows_cache[$sig] = $cached;
        }

        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
            return self::$rows_cache[$sig] = array();
        }
        $placeholders = implode(',', array_fill(0, count($keys), '%s'));
        $sql = $wpdb->prepare(
            "SELECT pm.post_id, pm.meta_key, pm.meta_value FROM {$wpdb->postmeta} pm"
            . " INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id"
            . " WHERE p.post_type = %s AND p.post_status = 'publish' AND pm.meta_key IN ({$placeholders})",
            array_merge(array('immoadmin_wohnung'), $keys)
        );
        $results = $wpdb->get_results($sql, ARRAY_A);

        $rows = array();
        foreach ((array) $results as $r) {
            $rows[(int) $r['post_id']][(string) $r['meta_key']] = $r['meta_value'];
        }
        $rows = array_values($rows);

        if (function_exists('set_transient')) {
            set_transient($cache_key, $rows, 12 * HOUR_IN_SECONDS);
        }
        return self::$rows_cache[$sig] = $rows;
    }

    /** Tests only. */
    public static function set_rows_for_tests($rows) {
        self::$rows_cache = array();
        if (is_array($rows)) {
            self::$rows_cache['__test'] = $rows;
        }
    }

    /** Rows for an element: test override if present, else the DB. */
    public static function rows(array $extra_keys = array()) {
        if (isset(self::$rows_cache['__test'])) {
            return self::$rows_cache['__test'];
        }
        return self::fetch_rows($extra_keys);
    }

    /**
     * Frontend assets shared by the filter elements and any units-table
     * with a filter group. Handles are deduplicated by WordPress.
     */
    public static function enqueue_assets() {
        if (!function_exists('wp_enqueue_script') || !defined('IMMOADMIN_PLUGIN_URL')) {
            return;
        }
        wp_enqueue_style('immoadmin-filters', IMMOADMIN_PLUGIN_URL . 'bricks/assets/css/filters.css', array(), IMMOADMIN_VERSION);
        wp_enqueue_script('immoadmin-filter-logic', IMMOADMIN_PLUGIN_URL . 'bricks/assets/js/filter-logic.js', array(), IMMOADMIN_VERSION, true);
        wp_enqueue_script('immoadmin-filters', IMMOADMIN_PLUGIN_URL . 'bricks/assets/js/filters.js', array('immoadmin-filter-logic'), IMMOADMIN_VERSION, true);
    }

    /** Builder context (any render the builder asks for). */
    public static function is_builder() {
        return (function_exists('bricks_is_builder') && bricks_is_builder())
            || (function_exists('bricks_is_builder_iframe') && bricks_is_builder_iframe())
            || (function_exists('bricks_is_builder_call') && bricks_is_builder_call());
    }
}
