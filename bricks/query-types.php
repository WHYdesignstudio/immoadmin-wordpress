<?php
/**
 * Bricks custom query types for a unit's media
 *
 *   "ImmoAdmin Grundrisse"  (immoadmin_floor_plans) → floor_plan_1 … floor_plan_N
 *   "ImmoAdmin Bilder"      (immoadmin_images)      → image_1 … image_N
 *   "ImmoAdmin Dokumente"   (immoadmin_documents)   → document_N_url / document_N_title
 *
 * The sync stores media as numbered URL meta (files live in
 * wp-content/immoadmin/media), NOT as attachments — so Bricks' own
 * "Posts → attachment" loop or an image gallery field can't iterate them.
 * These query types turn the numbered meta of ONE unit into loop items.
 *
 * Inside the loop, the current item is read with the dynamic tags
 *   {immoadmin_media_url}    URL of the current plan / image / document
 *   {immoadmin_media_title}  document title (plans/images: "Grundriss 2" …)
 *   {immoadmin_media_index}  1-based number as stored (floor_plan_<N>)
 * An Image element takes {immoadmin_media_url} as "Dynamic data" image —
 * Bricks' image context accepts a plain URL, no attachment needed.
 *
 * Which unit? The innermost looping Bricks query whose loop object is a
 * post — inside the Units Table that is the row's unit — falling back to
 * the global post (single unit template). Non-unit posts yield no items.
 *
 * Hooks (all documented Bricks extension points, verified against Bricks
 * 2.3.9 source): bricks/setup/control_options (queryTypes),
 * bricks/query/run, bricks/dynamic_tags_list,
 * bricks/dynamic_data/render_tag, bricks/dynamic_data/render_content,
 * bricks/frontend/render_data.
 *
 * Kept free of Bricks class dependencies so tests/run-tests.php can
 * exercise the item logic without Bricks installed.
 *
 * @package ImmoAdmin\Bricks
 */

if (!defined('ABSPATH')) {
    exit;
}

class ImmoAdmin_Bricks_Query_Types {

    const TYPE_FLOOR_PLANS = 'immoadmin_floor_plans';
    const TYPE_IMAGES      = 'immoadmin_images';
    const TYPE_DOCUMENTS   = 'immoadmin_documents';

    /** Dynamic tag names (without braces) */
    const TAG_URL   = 'immoadmin_media_url';
    const TAG_TITLE = 'immoadmin_media_title';
    const TAG_INDEX = 'immoadmin_media_index';

    private static $registered = false;

    /**
     * Hook into Bricks. Called from immoadmin.php once Bricks is present and
     * the integration is enabled.
     */
    public static function register() {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        add_filter('bricks/setup/control_options', [__CLASS__, 'add_query_types']);
        add_filter('bricks/query/run', [__CLASS__, 'run_query'], 10, 2);

        add_filter('bricks/dynamic_tags_list', [__CLASS__, 'add_tags_to_builder']);
        // Priority 20: Bricks' own providers run at 10 and hand unknown tags
        // back unchanged ("{immoadmin_media_url}"), which we then resolve.
        add_filter('bricks/dynamic_data/render_tag', [__CLASS__, 'render_tag'], 20, 3);
        add_filter('bricks/dynamic_data/render_content', [__CLASS__, 'render_content'], 20, 3);
        add_filter('bricks/frontend/render_data', [__CLASS__, 'render_content'], 20, 2);
    }

    /**
     * Query type => builder label.
     */
    public static function types() {
        return [
            self::TYPE_FLOOR_PLANS => 'ImmoAdmin Grundrisse',
            self::TYPE_IMAGES      => 'ImmoAdmin Bilder',
            self::TYPE_DOCUMENTS   => 'ImmoAdmin Dokumente',
        ];
    }

    public static function add_query_types($control_options) {
        if (!is_array($control_options)) {
            return $control_options;
        }
        if (!isset($control_options['queryTypes']) || !is_array($control_options['queryTypes'])) {
            $control_options['queryTypes'] = [];
        }
        foreach (self::types() as $type => $label) {
            $control_options['queryTypes'][$type] = $label;
        }
        return $control_options;
    }

    /**
     * bricks/query/run — return the loop items for our types, pass every
     * other query type through untouched.
     */
    public static function run_query($results, $query_obj) {
        $type = is_object($query_obj) && isset($query_obj->object_type) ? $query_obj->object_type : '';
        if (!isset(self::types()[$type])) {
            return $results;
        }

        return self::items_for_post(self::resolve_unit_id(), $type);
    }

    /**
     * Items of one unit for one query type. [] for anything that isn't a
     * unit, and — for documents — whenever the Visibility rules hide them
     * from the current visitor (reserved / sold / rented): the Exposé must
     * not leak through a loop placed outside the Units Table either.
     */
    public static function items_for_post($post_id, $type) {
        $post_id = (int) $post_id;
        if ($post_id <= 0 || !isset(self::types()[$type])) {
            return [];
        }

        $post_type = function_exists('get_post_type') ? get_post_type($post_id) : '';
        if (!in_array($post_type, ['immoadmin_wohnung', 'immoadmin_unit'], true)) {
            return [];
        }

        if ($type === self::TYPE_DOCUMENTS) {
            // Fail closed: without the Visibility class (partial plugin
            // update) we can't tell, so documents stay hidden.
            if (!class_exists('ImmoAdmin_Visibility')
                || ImmoAdmin_Visibility::hides_sensitive_data_for($post_id)) {
                return [];
            }
        }

        $meta = get_post_meta($post_id);

        return self::collect_media(is_array($meta) ? $meta : [], $type, $post_id);
    }

    /**
     * Pure: numbered media meta → ordered loop items.
     *
     * Reads the numbered keys themselves instead of trusting
     * floor_plans_count, so gaps (floor_plan_1, floor_plan_3) and a stale or
     * missing count can't produce empty slides or drop a plan. Empty values
     * are skipped. Accepts both get_post_meta($id) shape (key => [value])
     * and a flat key => value map.
     *
     * @return array<int, array{type:string,index:int,url:string,title:string,post_id:int}>
     */
    public static function collect_media(array $meta, $type, $post_id = 0) {
        switch ($type) {
            case self::TYPE_FLOOR_PLANS:
                $pattern = '/^floor_plan_(\d+)$/';
                $label   = 'Grundriss';
                break;
            case self::TYPE_IMAGES:
                $pattern = '/^image_(\d+)$/';
                $label   = 'Bild';
                break;
            case self::TYPE_DOCUMENTS:
                $pattern = '/^document_(\d+)_url$/';
                $label   = 'Dokument';
                break;
            default:
                return [];
        }

        $items = [];
        foreach ($meta as $key => $value) {
            if (!is_string($key) || !preg_match($pattern, $key, $m)) {
                continue;
            }
            $url = self::scalar_meta($value);
            if ($url === '') {
                continue;
            }
            $index = (int) $m[1];
            if ($index <= 0) {
                continue;
            }

            $title = '';
            if ($type === self::TYPE_DOCUMENTS) {
                $title = self::scalar_meta($meta["document_{$index}_title"] ?? '');
            }
            if ($title === '') {
                $title = $label . ' ' . $index;
            }

            $items[$index] = [
                'type'    => $type,
                'index'   => $index,
                'url'     => $url,
                'title'   => $title,
                'post_id' => (int) $post_id,
            ];
        }

        ksort($items, SORT_NUMERIC);

        return array_values($items);
    }

    private static function scalar_meta($value) {
        if (is_array($value)) {
            $value = reset($value);
        }
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * The unit whose media we list: innermost looping Bricks query that is
     * iterating posts (the Units Table row), else the global post.
     */
    public static function resolve_unit_id() {
        global $bricks_loop_query;

        if (is_array($bricks_loop_query) && !empty($bricks_loop_query)) {
            foreach (array_reverse($bricks_loop_query, true) as $query) {
                if (!is_object($query) || empty($query->is_looping)) {
                    continue;
                }
                if (isset($query->loop_object) && $query->loop_object instanceof WP_Post) {
                    return (int) $query->loop_object->ID;
                }
            }
        }

        return function_exists('get_the_ID') ? (int) get_the_ID() : 0;
    }

    /**
     * Loop item of the innermost looping query of one of OUR types, or null
     * when the tag is used outside such a loop.
     */
    public static function current_item() {
        global $bricks_loop_query;

        if (!is_array($bricks_loop_query) || empty($bricks_loop_query)) {
            return null;
        }

        foreach (array_reverse($bricks_loop_query, true) as $query) {
            if (!is_object($query) || empty($query->is_looping)) {
                continue;
            }
            $type = isset($query->object_type) ? $query->object_type : '';
            if (isset(self::types()[$type])) {
                return (isset($query->loop_object) && is_array($query->loop_object)) ? $query->loop_object : null;
            }
        }

        return null;
    }

    /**
     * Value of one of our tags for a loop item ('' if not ours / no item).
     */
    public static function tag_value($tag, $item) {
        if (!is_array($item)) {
            return '';
        }
        switch ($tag) {
            case self::TAG_URL:
                return isset($item['url']) ? (string) $item['url'] : '';
            case self::TAG_TITLE:
                return isset($item['title']) ? (string) $item['title'] : '';
            case self::TAG_INDEX:
                return isset($item['index']) ? (string) (int) $item['index'] : '';
        }
        return '';
    }

    public static function tag_names() {
        return [self::TAG_URL, self::TAG_TITLE, self::TAG_INDEX];
    }

    public static function add_tags_to_builder($tags) {
        if (!is_array($tags)) {
            $tags = [];
        }
        $labels = [
            self::TAG_URL   => 'Medien-URL (Grundriss/Bild/Dokument im Loop)',
            self::TAG_TITLE => 'Medien-Titel (im Loop)',
            self::TAG_INDEX => 'Medien-Nummer (im Loop)',
        ];
        foreach ($labels as $name => $label) {
            $tags[] = [
                'name'  => '{' . $name . '}',
                'label' => $label,
                'group' => 'ImmoAdmin',
            ];
        }
        return $tags;
    }

    /**
     * bricks/dynamic_data/render_tag — single tag, e.g. the Image element's
     * "Dynamic data" source. Image context expects an array of IDs/URLs.
     */
    public static function render_tag($tag, $post = null, $context = 'text') {
        if (!is_string($tag)) {
            return $tag;
        }
        $clean = trim($tag);
        if (substr($clean, 0, 1) === '{' && substr($clean, -1) === '}') {
            $clean = substr($clean, 1, -1);
        }
        // Ignore Bricks filter args (":plain" etc.) — values are plain strings.
        $name = strtok($clean, ':');
        if (!in_array($name, self::tag_names(), true)) {
            return $tag;
        }

        $value = self::tag_value($name, self::current_item());

        if ($context === 'image') {
            return $value !== '' ? [$value] : [];
        }
        if ($context === 'link' || $name === self::TAG_URL) {
            return function_exists('esc_url') ? esc_url($value) : $value;
        }
        return function_exists('esc_html') ? esc_html($value) : $value;
    }

    /**
     * bricks/dynamic_data/render_content + bricks/frontend/render_data —
     * tags embedded in text ("Plan {immoadmin_media_index}", link URLs …).
     */
    public static function render_content($content, $post = null, $context = 'text') {
        if (!is_string($content) || strpos($content, '{immoadmin_media_') === false) {
            return $content;
        }

        $item = self::current_item();

        return preg_replace_callback(
            '/\{(immoadmin_media_(?:url|title|index))(?::[^}]*)?\}/',
            function ($m) use ($item, $context) {
                $value = self::tag_value($m[1], $item);
                if ($m[1] === self::TAG_URL || $context === 'link') {
                    return function_exists('esc_url') ? esc_url($value) : $value;
                }
                return function_exists('esc_html') ? esc_html($value) : $value;
            },
            $content
        );
    }
}
