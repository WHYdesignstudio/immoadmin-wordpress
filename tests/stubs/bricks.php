<?php
/**
 * Minimal Bricks stand-ins for tests/run-tests.php — just enough surface for
 * bricks/elements/units-table.php to load and for its static helpers to run.
 * Signatures mirror Bricks 2.3.9 (includes/elements/base.php).
 */

namespace Bricks {

    if (!class_exists('Bricks\\Element')) {
        abstract class Element {
            public $element;
            public $id;
            public $settings = [];
            public $controls = [];
            public $control_groups = [];
            public $nestable_item;
            public $nestable_children;

            public function __construct($element = null) {
                $this->element  = $element;
                $this->id       = !empty($element['id']) ? (string) $element['id'] : 'abc123';
                $this->settings = !empty($element['settings']) ? $element['settings'] : [];
                // Like Bricks: nestable blueprints are read in the constructor.
                $this->nestable_item     = $this->get_nestable_item();
                $this->nestable_children = $this->get_nestable_children();
            }

            public function get_nestable_item() { return []; }
            public function get_nestable_children() { return []; }

            public function get_loop_builder_controls($group = '') {
                return [
                    'hasLoop' => ['tab' => 'content', 'label' => 'Use query loop', 'type' => 'checkbox'],
                    'query'   => ['tab' => 'content', 'label' => 'Query', 'type' => 'query'],
                ];
            }

            public $attributes = [];
            public $control_options = [
                'buttonSizes' => ['sm' => 'Small', 'md' => 'Medium', 'lg' => 'Large', 'xl' => 'Extra large'],
                'styles'      => ['primary' => 'Primary', 'secondary' => 'Secondary', 'light' => 'Light', 'dark' => 'Dark'],
            ];
            public $is_frontend = true;

            // Same semantics as Bricks 2.3.9 base.php set_attribute().
            public function set_attribute($key, $attribute, $value = null) {
                if (is_array($value)) {
                    foreach ($value as $val) { $this->attributes[$key][$attribute][] = $val; }
                    return;
                }
                if (empty($value) && !is_numeric($value)) {
                    $this->attributes[$key][$attribute] = '';
                    return;
                }
                if (isset($this->attributes[$key][$attribute]) && !is_array($this->attributes[$key][$attribute])) {
                    $this->attributes[$key][$attribute] = [$this->attributes[$key][$attribute], $value];
                    return;
                }
                $this->attributes[$key][$attribute][] = $value;
            }

            // Same output shape as Bricks 2.3.9 render_attributes() (no custom attributes).
            public function render_attributes($key, $add_custom_attributes = false) {
                if (!isset($this->attributes[$key])) { return; }
                $out = [];
                foreach ($this->attributes[$key] as $name => $value) {
                    if (empty($value) && !is_numeric($value)) { $out[] = $name; continue; }
                    if (is_array($value)) {
                        $value = join(' ', array_filter($value, function ($v) { return !empty($v) || is_numeric($v); }));
                    }
                    $out[] = $name . '="' . esc_attr($value) . '"';
                }
                return join(' ', $out);
            }

            public function render_dynamic_data($content = '') {
                return function_exists('bricks_render_dynamic_data') ? bricks_render_dynamic_data($content) : $content;
            }

            public function render_query_loop_trail($query) { return ''; }

            public function render_element_placeholder($data = [], $type = 'info') {
                if ($this->is_frontend) { return; }
                echo '<div class="bricks-element-placeholder">' . ($data['title'] ?? '') . '</div>';
            }

            public static function render_icon($icon, $attributes = []) {
                $classes = is_array($attributes) ? $attributes : [];
                $classes[] = isset($icon['icon']) ? $icon['icon'] : '';
                return '<i class="' . implode(' ', $classes) . '"></i>';
            }
        }
    }

    // Loop stand-in: iterates $GLOBALS['__query_posts'] like Bricks\Query::render().
    if (!class_exists('Bricks\\Query')) {
        class Query {
            public $element;
            public function __construct($element = []) { $this->element = $element; }
            public function render($callback, $args = []) {
                $html = '';
                $prev = $GLOBALS['__current_post'] ?? 0;
                foreach ($GLOBALS['__query_posts'] ?? [] as $pid) {
                    $GLOBALS['__current_post'] = $pid;
                    $html .= call_user_func_array($callback, array_values($args));
                }
                $GLOBALS['__current_post'] = $prev;
                return $html;
            }
            public function destroy() {}
        }
    }
}
