<?php
/**
 * Shared base of the ImmoAdmin filter elements (v2.14.0).
 *
 * Abstract, never registered itself. Structure and control naming follow
 * Bricks' own Filter elements (includes/elements/filter-base.php) so the
 * panel feels familiar: the connecting setting sits on top (Bricks: "Target
 * query" — here: "Filter-Gruppe"), style controls live in groups below and
 * map to CSS via the native `css` arrays.
 *
 * @package ImmoAdmin\Bricks
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('\\Bricks\\Element')) {
    return;
}

if (!class_exists('ImmoAdmin_Filter_Data')) {
    $immoadmin_fd = dirname(__DIR__) . '/filter-data.php';
    if (file_exists($immoadmin_fd)) {
        require_once $immoadmin_fd;
    }
}

if (!class_exists('ImmoAdmin_Filter_Element')) {

    abstract class ImmoAdmin_Filter_Element extends \Bricks\Element {

        public $category = 'immoadmin';
        public $scripts  = ['immoadminFiltersInit'];

        public function get_keywords() {
            return ['immoadmin', 'filter', 'wohnungen', 'flatfinder', 'suche'];
        }

        public function enqueue_scripts() {
            ImmoAdmin_Filter_Data::enqueue_assets();
        }

        /** "Filter-Gruppe" — the native "Target query", but one name for many tables. */
        protected function group_control() {
            return [
                'label'          => esc_html__('Filter-Gruppe', 'immoadmin'),
                'type'           => 'text',
                'inline'         => true,
                'default'        => 'wohnungen',
                'placeholder'    => 'wohnungen',
                'hasDynamicData' => false,
                'description'    => esc_html__('Gleicher Name wie bei „Filter-Gruppe“ der Wohnungstabellen. Alle Filter und Tabellen mit diesem Namen wirken zusammen.', 'immoadmin'),
            ];
        }

        protected function get_group() {
            return ImmoAdmin_Filter_Data::sanitize_group($this->settings['filter_group'] ?? '');
        }

        /**
         * Root attributes every filter element carries. Returns false (and
         * shows a builder placeholder) when no group is set.
         */
        protected function prepare_root($type) {
            $group = $this->get_group();
            if ($group === '') {
                $this->render_element_placeholder([
                    'title' => esc_html__('Bitte eine Filter-Gruppe eintragen (gleicher Name wie bei den Wohnungstabellen).', 'immoadmin'),
                ]);
                return false;
            }
            $this->set_attribute('_root', 'data-immoadmin-filter', $type);
            $this->set_attribute('_root', 'data-immoadmin-filter-group', $group);
            if (ImmoAdmin_Filter_Data::is_builder()) {
                $this->set_attribute('_root', 'data-builder', '1');
            }
            return $group;
        }

        /** Native Bricks button classes (Size / Style / Outline / Circle presets). */
        protected function button_preset_classes($prefix, $always = false) {
            $s = $this->settings;
            $classes = [];
            $size  = $s[$prefix . 'Size'] ?? '';
            $style = $s[$prefix . 'Style'] ?? '';
            if ($always || $size !== '' || $style !== '') {
                $classes[] = 'bricks-button';
            }
            if (is_string($size) && $size !== '') {
                $classes[] = sanitize_html_class($size);
            }
            if (is_string($style) && $style !== '') {
                if (!empty($s[$prefix . 'Outline'])) {
                    $classes[] = 'outline';
                    $classes[] = 'bricks-color-' . sanitize_html_class($style);
                } else {
                    $classes[] = 'bricks-background-' . sanitize_html_class($style);
                }
            }
            if (!empty($s[$prefix . 'Circle'])) {
                $classes[] = 'circle';
            }
            return $classes;
        }

        /**
         * Control set for one kind of button with Normal / Hover / Aktiv
         * states. Each state is a separator + background, typography and
         * border control mapped to CSS — like Bricks' "Button" /
         * "Button (Active)" sections of the native Filter – Checkbox.
         *
         * @param string      $p          control key prefix
         * @param string      $group      control group
         * @param string      $sel        CSS selector of the button
         * @param string      $title      section title
         * @param string|null $active_sel selector of the selected state (null = no Aktiv section)
         * @param array       $preset_defaults defaults for Size/Style/Outline (new elements only)
         */
        protected function button_controls($p, $group, $sel, $title, $active_sel = null, array $preset_defaults = []) {
            $opts = $this->control_options ?? [];
            $c = [];

            $c[$p . 'Sep'] = ['group' => $group, 'type' => 'separator', 'label' => $title];

            $c[$p . 'Size'] = [
                'group' => $group, 'label' => esc_html__('Größe', 'immoadmin'), 'type' => 'select',
                'options' => $opts['buttonSizes'] ?? [], 'inline' => true, 'placeholder' => esc_html__('Standard', 'immoadmin'),
            ];
            $c[$p . 'Style'] = [
                'group' => $group, 'label' => esc_html__('Stil', 'immoadmin'), 'type' => 'select',
                'options' => $opts['styles'] ?? [], 'inline' => true, 'placeholder' => esc_html__('Keiner', 'immoadmin'),
            ];
            $c[$p . 'Outline'] = [
                'group' => $group, 'label' => esc_html__('Outline', 'immoadmin'), 'type' => 'checkbox',
                'required' => [$p . 'Style', '!=', ''],
            ];
            $c[$p . 'Circle'] = ['group' => $group, 'label' => esc_html__('Rund (Circle)', 'immoadmin'), 'type' => 'checkbox'];
            foreach ($preset_defaults as $k => $v) {
                if (isset($c[$p . $k])) {
                    $c[$p . $k]['default'] = $v;
                }
            }

            $c[$p . 'Typography'] = [
                'group' => $group, 'label' => esc_html__('Typografie', 'immoadmin'), 'type' => 'typography',
                'css' => [['property' => 'font', 'selector' => $sel]],
            ];
            $c[$p . 'Background'] = [
                'group' => $group, 'label' => esc_html__('Hintergrundfarbe', 'immoadmin'), 'type' => 'color',
                'css' => [['property' => 'background-color', 'selector' => $sel]],
            ];
            $c[$p . 'Border'] = [
                'group' => $group, 'label' => esc_html__('Rahmen', 'immoadmin'), 'type' => 'border',
                'css' => [['property' => 'border', 'selector' => $sel]],
            ];
            $c[$p . 'Padding'] = [
                'group' => $group, 'label' => esc_html__('Innenabstand', 'immoadmin'), 'type' => 'spacing',
                'css' => [['property' => 'padding', 'selector' => $sel]],
            ];
            $c[$p . 'MinWidth'] = [
                'group' => $group, 'label' => esc_html__('Mindestbreite', 'immoadmin'), 'type' => 'number', 'units' => true,
                'css' => [['property' => 'min-width', 'selector' => $sel]],
            ];

            // Hover
            $hover = $sel . ':hover';
            $c[$p . 'HoverSep'] = ['group' => $group, 'type' => 'separator', 'label' => $title . ' (' . esc_html__('Hover', 'immoadmin') . ')'];
            $c[$p . 'HoverBackground'] = [
                'group' => $group, 'label' => esc_html__('Hintergrundfarbe', 'immoadmin'), 'type' => 'color',
                'css' => [['property' => 'background-color', 'selector' => $hover]],
            ];
            $c[$p . 'HoverTypography'] = [
                'group' => $group, 'label' => esc_html__('Typografie', 'immoadmin'), 'type' => 'typography',
                'css' => [['property' => 'font', 'selector' => $hover]],
            ];
            $c[$p . 'HoverBorder'] = [
                'group' => $group, 'label' => esc_html__('Rahmen', 'immoadmin'), 'type' => 'border',
                'css' => [['property' => 'border', 'selector' => $hover]],
            ];

            // Aktiv (selected) — after Hover so a selected button keeps its look on hover
            if ($active_sel) {
                $c[$p . 'ActiveSep'] = ['group' => $group, 'type' => 'separator', 'label' => $title . ' (' . esc_html__('Aktiv', 'immoadmin') . ')'];
                $c[$p . 'ActiveBackground'] = [
                    'group' => $group, 'label' => esc_html__('Hintergrundfarbe', 'immoadmin'), 'type' => 'color',
                    'css' => [['property' => 'background-color', 'selector' => $active_sel]],
                ];
                $c[$p . 'ActiveTypography'] = [
                    'group' => $group, 'label' => esc_html__('Typografie', 'immoadmin'), 'type' => 'typography',
                    'css' => [['property' => 'font', 'selector' => $active_sel]],
                ];
                $c[$p . 'ActiveBorder'] = [
                    'group' => $group, 'label' => esc_html__('Rahmen', 'immoadmin'), 'type' => 'border',
                    'css' => [['property' => 'border', 'selector' => $active_sel]],
                ];
            }

            return $c;
        }

        /** Builder-only data hint (values found in the synced units). */
        protected static function data_hint_html($text) {
            return '<span style="white-space:normal">' . esc_html($text) . '</span>';
        }
    }
}
