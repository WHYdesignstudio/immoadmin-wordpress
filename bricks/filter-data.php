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

    /** Tests only: forget everything filters announced during this "request". */
    public static function reset_registered_keys() {
        self::$registered_keys = array();
        self::$runtime_filters = array();
        self::$page_index      = null;
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
    // =================================================================
    // Targeting (v2.15.0): which tables does a filter act on?
    // =================================================================
    //
    // A filter (and a Filter-Aktionen element) acts on
    //     (tables picked in "Ziel-Tabellen")  ∪  (tables with its Filter-Gruppe)
    // and, when neither yields anything — nothing picked and no group, or a
    // group no table on the page carries — on ALL ImmoAdmin tables of the
    // page ("all mode"). The JS twin is filter-logic.js appliesTo(); both are
    // tested against tests/fixtures/targeting-cases.json.

    const TABLE_ELEMENT = 'immoadmin-units-table';

    /** Element names of the filter widgets (actions included). */
    public static function filter_element_names() {
        return array('immoadmin-filter-buttons', 'immoadmin-filter-range', 'immoadmin-filter-actions');
    }

    /** Bricks element ids are short [a-z0-9]; allow a little more, never quotes/spaces. */
    public static function sanitize_element_id($raw) {
        if (!is_scalar($raw)) {
            return '';
        }
        $id = trim((string) $raw);
        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) ? $id : '';
    }

    /** Stored "Ziel-Tabellen" (Bricks multi-select = array; tolerate strings) → unique ids. */
    public static function sanitize_targets($raw) {
        if (is_string($raw)) {
            $raw = preg_split('/[\s,]+/', $raw);
        }
        if (!is_array($raw)) {
            return array();
        }
        $out = array();
        foreach ($raw as $v) {
            $id = self::sanitize_element_id($v);
            if ($id !== '' && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        return array_slice($out, 0, 50);
    }

    /**
     * Table id as rendered vs. picked target. Inside a component Bricks
     * renders the source id with the instance id appended ("abc123-x9y8z7"),
     * so picking the table in the component targets every instance of it.
     */
    public static function id_matches($table_id, $target) {
        $table_id = (string) $table_id;
        $target   = (string) $target;
        if ($table_id === '' || $target === '') {
            return false;
        }
        return $table_id === $target || strpos($table_id, $target . '-') === 0;
    }

    /** Nothing picked and no group a table on the page carries → all tables. */
    public static function is_all_mode(array $targets, $group, array $groups_present) {
        $group = (string) $group;
        return empty($targets) && ($group === '' || empty($groups_present[$group]));
    }

    /**
     * Does a filter/actions spec act on this table?
     *
     * @param array  $targets        sanitized target ids
     * @param string $group          sanitized Filter-Gruppe of the filter ('' = none)
     * @param string $table_id       rendered element id of the table
     * @param string $table_group    sanitized Filter-Gruppe of the table ('' = none)
     * @param array  $groups_present [group => true] of all tables on the page
     */
    public static function applies_to_table(array $targets, $group, $table_id, $table_group, array $groups_present) {
        foreach ($targets as $t) {
            if (self::id_matches($table_id, $t)) {
                return true;
            }
        }
        if ((string) $group !== '' && (string) $group === (string) $table_group) {
            return true;
        }
        return self::is_all_mode($targets, $group, $groups_present);
    }

    /**
     * Readable option label for a table in the "Ziel-Tabellen" dropdown:
     * Bricks custom label (or "Units Table") · selected Gebäude · #id.
     * The JS twin (builder-targets.js) builds the same string.
     */
    public static function table_label(array $element, $suffix = '') {
        $label = isset($element['label']) && is_string($element['label']) ? trim(wp_strip_all_tags($element['label'])) : '';
        if ($label === '') {
            $label = 'Units Table';
        }
        $settings  = isset($element['settings']) && is_array($element['settings']) ? $element['settings'] : array();
        $buildings = array();
        foreach ((array) ($settings['immoadmin_buildings'] ?? array()) as $b) {
            if (is_scalar($b) && trim((string) $b) !== '') {
                $buildings[] = trim(wp_strip_all_tags((string) $b));
            }
        }
        if (!empty($buildings)) {
            $label .= ' · ' . implode(', ', $buildings);
        }
        $label .= ' #' . (isset($element['id']) ? (string) $element['id'] : '');
        if ($suffix !== '') {
            $label .= ' (' . $suffix . ')';
        }
        return $label;
    }

    /**
     * Custom meta key a filter element needs on the rows ('' = standard
     * field). Same resolution as the elements' resolve_field().
     */
    public static function custom_key_from_settings($name, array $settings) {
        if ($name === 'immoadmin-filter-actions' || ($settings['field'] ?? '') !== '__custom') {
            return '';
        }
        $key = self::sanitize_meta_key($settings['field_custom'] ?? '');
        return in_array($key, self::standard_keys(), true) ? '' : $key;
    }

    /**
     * Walk Bricks element lists and collect ImmoAdmin tables and filters.
     * Follows "Template" elements and components through $resolve:
     *   $resolve('template', $template_id) → element list
     *   $resolve('component', $cid)        → element list of the component
     * Pure apart from $resolve — tested with a fake resolver.
     *
     * @param array    $lists   list of element lists (header, content, footer …)
     * @param callable $resolve
     * @return array ['tables' => [id => [id, group, label, external]], 'filters' => [id => [id, name, targets, group, key]]]
     */
    public static function index_elements(array $lists, $resolve = null) {
        $index = array('tables' => array(), 'filters' => array());
        $seen  = array();
        $walk  = function ($elements, $depth, $suffix) use (&$walk, &$index, &$seen, $resolve) {
            if (!is_array($elements) || $depth > 4) {
                return;
            }
            foreach ($elements as $el) {
                if (!is_array($el) || empty($el['name']) || !is_string($el['name'])) {
                    continue;
                }
                $name     = $el['name'];
                $id       = self::sanitize_element_id($el['id'] ?? '');
                $settings = isset($el['settings']) && is_array($el['settings']) ? $el['settings'] : array();

                if ($name === self::TABLE_ELEMENT && $id !== '' && !isset($index['tables'][$id])) {
                    $index['tables'][$id] = array(
                        'id'    => $id,
                        'group' => self::sanitize_group($settings['immoadmin_filter_group'] ?? ''),
                        'label' => self::table_label($el, $suffix),
                        'external' => $suffix !== '',
                    );
                } elseif (in_array($name, self::filter_element_names(), true) && $id !== '' && !isset($index['filters'][$id])) {
                    $index['filters'][$id] = array(
                        'id'      => $id,
                        'name'    => $name,
                        'targets' => self::sanitize_targets($settings['filter_targets'] ?? array()),
                        'group'   => self::sanitize_group($settings['filter_group'] ?? ''),
                        'key'     => self::custom_key_from_settings($name, $settings),
                    );
                }

                if (!is_callable($resolve)) {
                    continue;
                }
                if ($name === 'template' && !empty($settings['template']) && is_numeric($settings['template'])) {
                    $tid = 'template:' . (int) $settings['template'];
                    if (!isset($seen[$tid])) {
                        $seen[$tid] = true;
                        $walk(call_user_func($resolve, 'template', (int) $settings['template']), $depth + 1, 'Template');
                    }
                }
                if (!empty($el['cid']) && is_scalar($el['cid'])) {
                    $cid = 'component:' . $el['cid'];
                    if (!isset($seen[$cid])) {
                        $seen[$cid] = true;
                        $walk(call_user_func($resolve, 'component', (string) $el['cid']), $depth + 1, 'Komponente');
                    }
                }
            }
        };
        foreach ($lists as $list) {
            $walk($list, 0, '');
        }
        return $index;
    }

    /** [group => true] of the indexed tables. */
    public static function groups_present(array $tables) {
        $out = array();
        foreach ($tables as $t) {
            if (($t['group'] ?? '') !== '') {
                $out[$t['group']] = true;
            }
        }
        return $out;
    }

    /**
     * Does any filter (buttons / range — actions do not filter) act on this
     * table, and which custom keys do those filters need?
     *
     * @return array ['active' => bool, 'keys' => string[]]
     */
    public static function table_participation(array $index, $table_id, $table_group) {
        $present = self::groups_present($index['tables'] ?? array());
        $active  = false;
        $keys    = array();
        foreach ($index['filters'] ?? array() as $f) {
            if (($f['name'] ?? '') === 'immoadmin-filter-actions') {
                continue;
            }
            if (self::applies_to_table($f['targets'] ?? array(), $f['group'] ?? '', $table_id, $table_group, $present)) {
                $active = true;
                if (($f['key'] ?? '') !== '' && !in_array($f['key'], $keys, true)) {
                    $keys[] = $f['key'];
                }
            }
        }
        return array('active' => $active, 'keys' => $keys);
    }

    // ---------------------------------------------------------- page index

    private static $page_index = null;
    private static $runtime_filters = array();

    /** Filters announce themselves while rendering (covers what the scan cannot see). */
    public static function register_filter($id, $name, array $targets, $group, $key = '') {
        $id = self::sanitize_element_id($id);
        if ($id === '') {
            return;
        }
        self::$runtime_filters[$id] = array(
            'id' => $id, 'name' => (string) $name, 'targets' => self::sanitize_targets($targets),
            'group' => self::sanitize_group($group), 'key' => self::sanitize_meta_key($key),
        );
    }

    /** Tests only. */
    public static function set_page_index_for_tests($index) {
        self::$page_index      = $index;
        self::$runtime_filters = array();
    }

    /**
     * Index of the page being rendered: the scan of its Bricks data (once
     * per request) plus filters rendered so far. Empty outside Bricks.
     */
    public static function page_index() {
        if (self::$page_index === null) {
            self::$page_index = self::scan_current_page();
        }
        $index = self::$page_index;
        foreach (self::$runtime_filters as $id => $f) {
            if (!isset($index['filters'][$id])) {
                $index['filters'][$id] = $f;
            }
        }
        return $index;
    }

    /** Resolver for index_elements(): Bricks templates + components. */
    public static function bricks_resolver($type, $id) {
        if ($type === 'template' && defined('BRICKS_DB_PAGE_CONTENT') && function_exists('get_post_meta')) {
            $data = get_post_meta((int) $id, BRICKS_DB_PAGE_CONTENT, true);
            return is_array($data) ? $data : array();
        }
        if ($type === 'component' && class_exists('\\Bricks\\Helpers') && method_exists('\\Bricks\\Helpers', 'get_component_by_cid')) {
            $component = \Bricks\Helpers::get_component_by_cid($id);
            return is_array($component) && isset($component['elements']) && is_array($component['elements']) ? $component['elements'] : array();
        }
        return array();
    }

    /**
     * Element lists of a post as Bricks renders it: header / content /
     * footer (active templates included) and active popup templates.
     *
     * @param int|null $post_id null = the current frontend request
     */
    public static function bricks_element_lists($post_id = null) {
        $lists = array();
        if (!class_exists('\\Bricks\\Database')) {
            return $lists;
        }
        try {
            if ($post_id === null) {
                foreach (array('header', 'content', 'footer') as $area) {
                    $data = \Bricks\Database::get_template_data($area);
                    if (is_array($data)) {
                        $lists[] = $data;
                    }
                }
            } else {
                foreach (array('header', 'content', 'footer') as $area) {
                    $data = \Bricks\Database::get_data((int) $post_id, $area);
                    if (is_array($data)) {
                        $lists[] = $data;
                    }
                }
                foreach (array('header', 'footer') as $area) {
                    $tid = \Bricks\Database::$active_templates[$area] ?? 0;
                    if ($tid && (int) $tid !== (int) $post_id && defined('BRICKS_DB_PAGE_' . strtoupper($area))) {
                        $data = get_post_meta((int) $tid, constant('BRICKS_DB_PAGE_' . strtoupper($area)), true);
                        if (is_array($data)) {
                            $lists[] = $data;
                        }
                    }
                }
            }
            $popups = \Bricks\Database::$active_templates['popup'] ?? array();
            if (is_array($popups) && defined('BRICKS_DB_PAGE_CONTENT')) {
                foreach (array_slice($popups, 0, 20) as $pid) {
                    $data = get_post_meta((int) $pid, BRICKS_DB_PAGE_CONTENT, true);
                    if (is_array($data)) {
                        $lists[] = $data;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Never let the scan break a page render: worst case the table
            // stays unfiltered, exactly as before v2.15.0.
            return $lists;
        }
        return $lists;
    }

    private static function scan_current_page() {
        $empty = array('tables' => array(), 'filters' => array());
        if (self::is_builder()) {
            return $empty; // filters never act inside the builder
        }
        try {
            return self::index_elements(self::bricks_element_lists(null), array(__CLASS__, 'bricks_resolver'));
        } catch (\Throwable $e) {
            return $empty;
        }
    }

    /**
     * Options of the "Ziel-Tabellen" control: [id => label] of the tables on
     * the post being edited (incl. templates / components it uses). Builder
     * only — the frontend never needs them. builder-targets.js refreshes
     * them live from the unsaved builder state.
     *
     * @return array ['options' => [id => label], 'external' => [id, …]]  external = found via template/component
     */
    public static function builder_target_options() {
        $out = array('options' => array(), 'external' => array());
        if (!function_exists('bricks_is_builder_main') || !bricks_is_builder_main() || !function_exists('get_the_ID')) {
            return $out;
        }
        try {
            $post_id = (int) get_the_ID();
            if ($post_id <= 0) {
                return $out;
            }
            $index = self::index_elements(self::bricks_element_lists($post_id), array(__CLASS__, 'bricks_resolver'));
        } catch (\Throwable $e) {
            return $out;
        }
        return self::target_options_from_index($index);
    }

    /** Pure part of builder_target_options(). */
    public static function target_options_from_index(array $index) {
        $out = array('options' => array(), 'external' => array());
        foreach ($index['tables'] ?? array() as $id => $t) {
            // Escaped like Bricks' own query-list labels (options render as HTML).
            $out['options'][$id] = esc_html($t['label']);
            if (!empty($t['external'])) {
                $out['external'][] = (string) $id;
            }
        }
        return $out;
    }
}
