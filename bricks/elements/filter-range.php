<?php
/**
 * Bricks Element: ImmoAdmin Filter-Bereich (v2.14.0)
 *
 * Dual-handle range slider (Wohnfläche, Preis, …) acting on every
 * units-table with the same "Filter-Gruppe". Markup mirrors Bricks' native
 * "Filter – Range" in slider mode (.double-slider-wrap / .slider-base /
 * .slider-track / input.min / input.max / .value-wrap) so the structure is
 * familiar; two native <input type="range"> = keyboard + screen reader
 * support out of the box.
 *
 * Prices: bounds only come from units whose price is public, and reserved /
 * sold units (price redacted) count as "no value" — by default they stay
 * visible ("Einheiten ohne Wert trotzdem anzeigen").
 *
 * @package ImmoAdmin\Bricks
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('\\Bricks\\Element')) {
    return;
}

require_once __DIR__ . '/filter-base.php';

class ImmoAdmin_Filter_Range extends ImmoAdmin_Filter_Element {

    public $name = 'immoadmin-filter-range';
    public $icon = 'ti-arrows-horizontal';

    public function get_label() {
        return esc_html__('ImmoAdmin Filter-Bereich', 'immoadmin');
    }

    public function set_control_groups() {
        $this->control_groups['format'] = ['title' => esc_html__('Werte-Format', 'immoadmin')];
        $this->control_groups['label']  = ['title' => esc_html__('Label', 'immoadmin')];
        $this->control_groups['slider'] = ['title' => esc_html__('Slider', 'immoadmin')];
        $this->control_groups['values'] = ['title' => esc_html__('Werte-Anzeige', 'immoadmin')];
    }

    public function set_controls() {
        $fields = ImmoAdmin_Filter_Data::range_fields();

        $this->controls['filter_group'] = $this->group_control();

        $this->controls['field'] = [
            'label'     => esc_html__('Feld', 'immoadmin'),
            'type'      => 'select',
            'options'   => array_map(function ($f) { return $f['label']; }, $fields),
            'inline'    => true,
            'default'   => 'living_area',
            'clearable' => false,
        ];

        $this->controls['field_custom'] = [
            'label'          => esc_html__('Meta-Key', 'immoadmin'),
            'type'           => 'text',
            'inline'         => true,
            'placeholder'    => 'z. B. balcony_area',
            'hasDynamicData' => false,
            'required'       => ['field', '=', '__custom'],
            'description'    => esc_html__('Steht dieser Filter UNTER den Tabellen, den Meta-Key zusätzlich bei der Tabelle unter „Zusätzliche Felder“ eintragen.', 'immoadmin'),
        ];

        $this->controls['label'] = [
            'label'          => esc_html__('Label', 'immoadmin'),
            'type'           => 'text',
            'inline'         => true,
            'placeholder'    => esc_html__('z. B. Wohnfläche', 'immoadmin'),
            'hasDynamicData' => false,
        ];

        $this->controls['empty_handling'] = [
            'label'       => esc_html__('Einheiten ohne Wert', 'immoadmin'),
            'type'        => 'select',
            'options'     => [
                'show' => esc_html__('Trotzdem anzeigen', 'immoadmin'),
                'hide' => esc_html__('Ausblenden, sobald gefiltert wird', 'immoadmin'),
            ],
            'inline'      => true,
            'placeholder' => esc_html__('Automatisch', 'immoadmin'),
            'description' => esc_html__('Automatisch: bei Preisen anzeigen (reservierte/verkaufte Einheiten haben keinen sichtbaren Preis und sollen nicht verschwinden), sonst ausblenden.', 'immoadmin'),
        ];

        $this->controls['rangeSep'] = [
            'type'  => 'separator',
            'label' => esc_html__('Bereich', 'immoadmin'),
        ];

        $this->controls['min'] = [
            'label'       => esc_html__('Min', 'immoadmin'),
            'type'        => 'number',
            'inline'      => true,
            'placeholder' => esc_html__('automatisch', 'immoadmin'),
        ];
        $this->controls['max'] = [
            'label'       => esc_html__('Max', 'immoadmin'),
            'type'        => 'number',
            'inline'      => true,
            'placeholder' => esc_html__('automatisch', 'immoadmin'),
        ];
        $this->controls['step'] = [
            'label'       => esc_html__('Schrittweite', 'immoadmin'),
            'type'        => 'number',
            'inline'      => true,
            'placeholder' => esc_html__('automatisch', 'immoadmin'),
            'description' => esc_html__('Automatisch: aus den Daten gerundet (z. B. 1 m² oder 10.000 €).', 'immoadmin'),
        ];

        // ---------- Format ----------
        $this->controls['value_format'] = [
            'group'       => 'format',
            'label'       => esc_html__('Format', 'immoadmin'),
            'type'        => 'select',
            'options'     => [
                'thousands' => esc_html__('Tausenderpunkt (439.800)', 'immoadmin'),
                'k'         => esc_html__('Kurz (280k, 1,2 Mio.)', 'immoadmin'),
                'plain'     => esc_html__('Ohne Trennzeichen (439800)', 'immoadmin'),
            ],
            'inline'      => true,
            'placeholder' => esc_html__('Automatisch', 'immoadmin'),
            'description' => esc_html__('Automatisch: Preise kurz (280k), sonst mit Tausenderpunkt.', 'immoadmin'),
        ];
        $this->controls['decimals'] = [
            'group'       => 'format',
            'label'       => esc_html__('Nachkommastellen', 'immoadmin'),
            'type'        => 'number',
            'min'         => 0,
            'max'         => 4,
            'inline'      => true,
            'placeholder' => '0',
        ];
        $this->controls['prefix'] = [
            'group'          => 'format',
            'label'          => esc_html__('Präfix', 'immoadmin'),
            'type'           => 'text',
            'inline'         => true,
            'placeholder'    => 'z. B. € ',
            'hasDynamicData' => false,
        ];
        $this->controls['suffix'] = [
            'group'          => 'format',
            'label'          => esc_html__('Suffix', 'immoadmin'),
            'type'           => 'text',
            'inline'         => true,
            'placeholder'    => esc_html__('automatisch ( m² bei Flächen)', 'immoadmin'),
            'hasDynamicData' => false,
        ];
        $this->controls['labelMin'] = [
            'group'          => 'format',
            'label'          => esc_html__('Text vor Min-Wert', 'immoadmin'),
            'type'           => 'text',
            'inline'         => true,
            'placeholder'    => esc_html__('z. B. von', 'immoadmin'),
            'hasDynamicData' => false,
        ];
        $this->controls['labelMax'] = [
            'group'          => 'format',
            'label'          => esc_html__('Text vor Max-Wert', 'immoadmin'),
            'type'           => 'text',
            'inline'         => true,
            'placeholder'    => esc_html__('z. B. bis', 'immoadmin'),
            'hasDynamicData' => false,
        ];

        // ---------- Label ----------
        $this->controls['labelTypography'] = [
            'group' => 'label',
            'label' => esc_html__('Typografie', 'immoadmin'),
            'type'  => 'typography',
            'css'   => [['property' => 'font', 'selector' => '.immoadmin-filter-label']],
        ];
        $this->controls['gap'] = [
            'group'       => 'label',
            'label'       => esc_html__('Abstand Label – Slider', 'immoadmin'),
            'type'        => 'number',
            'units'       => true,
            'placeholder' => '0.5em',
            'css'         => [['property' => 'gap']],
        ];

        // ---------- Slider (CSS custom properties, used by filters.css) ----------
        $this->controls['sliderSpacing'] = [
            'group'       => 'slider',
            'label'       => esc_html__('Abstand oben', 'immoadmin'),
            'type'        => 'number',
            'units'       => true,
            'placeholder' => '0',
            'css'         => [['property' => 'padding-top', 'selector' => '.double-slider-wrap']],
        ];
        $this->controls['sliderBarHeight'] = [
            'group'       => 'slider',
            'label'       => esc_html__('Balken: Höhe', 'immoadmin'),
            'type'        => 'number',
            'units'       => true,
            'placeholder' => '4px',
            'css'         => [['property' => '--iaf-bar-height']],
        ];
        $this->controls['sliderBarColor'] = [
            'group' => 'slider',
            'label' => esc_html__('Balken: Farbe', 'immoadmin'),
            'type'  => 'color',
            'css'   => [['property' => '--iaf-bar-color']],
        ];
        $this->controls['sliderBarColorActive'] = [
            'group' => 'slider',
            'label' => esc_html__('Balken: Farbe (gewählter Bereich)', 'immoadmin'),
            'type'  => 'color',
            'css'   => [['property' => '--iaf-bar-active-color']],
        ];
        $this->controls['sliderBarRadius'] = [
            'group'       => 'slider',
            'label'       => esc_html__('Balken: Rundung', 'immoadmin'),
            'type'        => 'number',
            'units'       => true,
            'placeholder' => '999px',
            'css'         => [['property' => '--iaf-bar-radius']],
        ];
        $this->controls['sliderThumbSize'] = [
            'group'       => 'slider',
            'label'       => esc_html__('Griff: Größe', 'immoadmin'),
            'type'        => 'number',
            'units'       => true,
            'placeholder' => '18px',
            'css'         => [['property' => '--iaf-thumb-size']],
        ];
        $this->controls['sliderThumbBackgroundColor'] = [
            'group' => 'slider',
            'label' => esc_html__('Griff: Hintergrundfarbe', 'immoadmin'),
            'type'  => 'color',
            'css'   => [['property' => '--iaf-thumb-bg']],
        ];
        $this->controls['sliderThumbBorder'] = [
            'group' => 'slider',
            'label' => esc_html__('Griff: Rahmen', 'immoadmin'),
            'type'  => 'border',
            'css'   => [
                ['property' => 'border', 'selector' => '.double-slider-wrap input[type="range"]::-webkit-slider-thumb'],
                ['property' => 'border', 'selector' => '.double-slider-wrap input[type="range"]::-moz-range-thumb'],
            ],
        ];
        $this->controls['sliderThumbBoxShadow'] = [
            'group' => 'slider',
            'label' => esc_html__('Griff: Schatten', 'immoadmin'),
            'type'  => 'box-shadow',
            'css'   => [
                ['property' => 'box-shadow', 'selector' => '.double-slider-wrap input[type="range"]::-webkit-slider-thumb'],
                ['property' => 'box-shadow', 'selector' => '.double-slider-wrap input[type="range"]::-moz-range-thumb'],
            ],
        ];

        // ---------- Werte-Anzeige ----------
        $this->controls['valueTypography'] = [
            'group' => 'values',
            'label' => esc_html__('Typografie (Werte)', 'immoadmin'),
            'type'  => 'typography',
            'css'   => [['property' => 'font', 'selector' => '.value-wrap .value']],
        ];
        $this->controls['valueLabelTypography'] = [
            'group'    => 'values',
            'label'    => esc_html__('Typografie (von/bis-Text)', 'immoadmin'),
            'type'     => 'typography',
            'css'      => [['property' => 'font', 'selector' => '.value-wrap .label']],
        ];
        $this->controls['valueSpacing'] = [
            'group'       => 'values',
            'label'       => esc_html__('Abstand zum Slider', 'immoadmin'),
            'type'        => 'number',
            'units'       => true,
            'placeholder' => '0.5em',
            'css'         => [['property' => 'margin-top', 'selector' => '.value-wrap']],
        ];
        $this->controls['valueGap'] = [
            'group'       => 'values',
            'label'       => esc_html__('Abstand von/bis-Text – Wert', 'immoadmin'),
            'type'        => 'number',
            'units'       => true,
            'placeholder' => '0.3em',
            'css'         => [['property' => 'gap', 'selector' => '.value-wrap > span']],
        ];
    }

    /** Field key, data key, kind. */
    public static function resolve_field($settings) {
        $fields = ImmoAdmin_Filter_Data::range_fields();
        $field  = isset($settings['field']) && is_string($settings['field']) && isset($fields[$settings['field']])
            ? $settings['field']
            : 'living_area';
        $key = $field === '__custom' ? ImmoAdmin_Filter_Data::sanitize_meta_key($settings['field_custom'] ?? '') : $field;
        return ['field' => $field, 'key' => $key, 'kind' => $fields[$field]['kind']];
    }

    /**
     * Everything the slider needs, from settings + rows. Pure — tested.
     *
     * @return array|null ['key','min','max','step','format','includeEmpty'] or null (no data)
     */
    public static function config_for($settings, array $rows) {
        $f = self::resolve_field($settings);
        if ($f['key'] === '') {
            return null;
        }
        $values = ImmoAdmin_Filter_Data::range_values($rows, $f['key'], $f['field'] !== '__custom');
        $range  = ImmoAdmin_Filter_Data::range_config(
            $values,
            $settings['min'] ?? '',
            $settings['max'] ?? '',
            $settings['step'] ?? ''
        );
        if ($range === null) {
            return null;
        }

        $fmt = ImmoAdmin_Filter_Data::default_format($f['kind']);
        if (!empty($settings['value_format']) && in_array($settings['value_format'], ['thousands', 'k', 'plain'], true)) {
            $fmt['mode'] = $settings['value_format'];
        }
        if (isset($settings['decimals']) && is_numeric($settings['decimals'])) {
            $fmt['decimals'] = max(0, min(4, (int) $settings['decimals']));
        }
        if (isset($settings['prefix']) && is_string($settings['prefix']) && $settings['prefix'] !== '') {
            $fmt['prefix'] = $settings['prefix'];
        }
        if (isset($settings['suffix']) && is_string($settings['suffix']) && $settings['suffix'] !== '') {
            $fmt['suffix'] = $settings['suffix'];
        }

        $empty = $settings['empty_handling'] ?? '';
        if ($empty === 'show') {
            $include_empty = true;
        } elseif ($empty === 'hide') {
            $include_empty = false;
        } else {
            $include_empty = $f['kind'] === 'price' || ImmoAdmin_Filter_Data::is_sensitive_key($f['key']);
        }

        return [
            'key'          => $f['key'],
            'min'          => $range['min'],
            'max'          => $range['max'],
            'step'         => $range['step'],
            'format'       => $fmt,
            'includeEmpty' => $include_empty,
        ];
    }

    private static function num_attr($n) {
        $n = (float) $n;
        return floor($n) == $n ? (string) (int) $n : rtrim(rtrim(sprintf('%.6F', $n), '0'), '.');
    }

    public function render() {
        $settings = $this->settings;
        $group = $this->prepare_root('range');
        if ($group === false) {
            return;
        }

        $f = self::resolve_field($settings);
        if ($f['key'] === '') {
            return $this->render_element_placeholder(['title' => esc_html__('Bitte einen Meta-Key eintragen.', 'immoadmin')]);
        }
        if ($f['field'] === '__custom') {
            ImmoAdmin_Filter_Data::register_custom_key($group, $f['key']);
        }

        $rows   = ImmoAdmin_Filter_Data::rows($f['field'] === '__custom' ? [$f['key']] : []);
        $config = self::config_for($settings, $rows);
        if ($config === null) {
            return $this->render_element_placeholder(['title' => esc_html__('Keine Werte gefunden – nach dem ersten Sync erscheint hier der Bereich.', 'immoadmin')]);
        }

        $this->set_attribute('_root', 'data-immoadmin-filter-config', wp_json_encode($config));

        $fields    = ImmoAdmin_Filter_Data::range_fields();
        $label     = isset($settings['label']) && is_string($settings['label']) ? trim($settings['label']) : '';
        $name      = $label !== '' ? $label : ($f['field'] === '__custom' ? $f['key'] : wp_strip_all_tags($fields[$f['field']]['label']));
        $label_id  = 'iaf-label-' . $this->id;
        $label_min = isset($settings['labelMin']) && is_string($settings['labelMin']) ? trim($settings['labelMin']) : '';
        $label_max = isset($settings['labelMax']) && is_string($settings['labelMax']) ? trim($settings['labelMax']) : '';
        $min_text  = ImmoAdmin_Filter_Data::format_value($config['min'], $config['format']);
        $max_text  = ImmoAdmin_Filter_Data::format_value($config['max'], $config['format']);

        $common = ' min="' . esc_attr(self::num_attr($config['min'])) . '"'
            . ' max="' . esc_attr(self::num_attr($config['max'])) . '"'
            . ' step="' . esc_attr(self::num_attr($config['step'])) . '"';

        echo "<div {$this->render_attributes('_root')}>";
        if ($label !== '') {
            echo '<span class="immoadmin-filter-label" id="' . esc_attr($label_id) . '">' . esc_html($label) . '</span>';
        }
        echo '<div class="double-slider-wrap" role="group"'
            . ($label !== '' ? ' aria-labelledby="' . esc_attr($label_id) . '"' : ' aria-label="' . esc_attr($name) . '"') . '>';
        echo '<div class="slider-wrap" style="--iaf-lo: 0; --iaf-hi: 1">';
        echo '<div class="slider-base"></div>';
        echo '<div class="slider-track"></div>';
        echo '<input type="range" class="min"' . $common
            . ' value="' . esc_attr(self::num_attr($config['min'])) . '"'
            . ' aria-label="' . esc_attr($name . ' ' . __('Minimum', 'immoadmin')) . '"'
            . ' aria-valuetext="' . esc_attr($min_text) . '">';
        echo '<input type="range" class="max"' . $common
            . ' value="' . esc_attr(self::num_attr($config['max'])) . '"'
            . ' aria-label="' . esc_attr($name . ' ' . __('Maximum', 'immoadmin')) . '"'
            . ' aria-valuetext="' . esc_attr($max_text) . '">';
        echo '</div>';
        echo '<div class="value-wrap" aria-hidden="true">';
        echo '<span class="lower">' . ($label_min !== '' ? '<span class="label">' . esc_html($label_min) . '</span>' : '')
            . '<span class="value">' . esc_html($min_text) . '</span></span>';
        echo '<span class="upper">' . ($label_max !== '' ? '<span class="label">' . esc_html($label_max) . '</span>' : '')
            . '<span class="value">' . esc_html($max_text) . '</span></span>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }
}
