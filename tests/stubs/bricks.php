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

            public static function render_icon($icon, $attributes = []) {
                $classes = is_array($attributes) ? $attributes : [];
                $classes[] = isset($icon['icon']) ? $icon['icon'] : '';
                return '<i class="' . implode(' ', $classes) . '"></i>';
            }
        }
    }
}
