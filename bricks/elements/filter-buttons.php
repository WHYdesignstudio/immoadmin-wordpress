<?php
/**
 * Bricks Element: ImmoAdmin Filter-Buttons (v2.14.0)
 *
 * One row of pill buttons for one field (Haus, Geschoss, Ausrichtung,
 * Zimmer or a custom meta key). Acts on every units-table picked in
 * "Ziel-Tabellen" (empty = all) — client-side, no reload. Modeled on Bricks' native
 * "Filter – Checkbox" in button mode (same option markup classes:
 * .brx-option-active for the selected state).
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

class ImmoAdmin_Filter_Buttons extends ImmoAdmin_Filter_Element {

    public $name = 'immoadmin-filter-buttons';
    public $icon = 'ti-layout-menu-separated';

    public function get_label() {
        return esc_html__('ImmoAdmin Filter-Buttons', 'immoadmin');
    }

    public function set_control_groups() {
        $this->control_groups['options'] = ['title' => esc_html__('Optionen', 'immoadmin')];
        $this->control_groups['layout']  = ['title' => esc_html__('Layout', 'immoadmin')];
        $this->control_groups['label']   = ['title' => esc_html__('Label', 'immoadmin')];
        $this->control_groups['buttons'] = ['title' => esc_html__('Buttons', 'immoadmin')];
    }

    public function set_controls() {
        $fields = ImmoAdmin_Filter_Data::button_fields();

        $this->controls = array_merge($this->controls, $this->targeting_controls());

        $this->controls['field'] = [
            'label'       => esc_html__('Feld', 'immoadmin'),
            'type'        => 'select',
            'options'     => array_map(function ($f) { return $f['label']; }, $fields),
            'inline'      => true,
            'default'     => 'building_name',
            'clearable'   => false,
        ];

        $this->controls['field_custom'] = [
            'label'          => esc_html__('Meta-Key', 'immoadmin'),
            'type'           => 'text',
            'inline'         => true,
            'placeholder'    => 'z. B. object_type_label',
            'hasDynamicData' => false,
            'required'       => ['field', '=', '__custom'],
            'description'    => esc_html__('Steht dieser Filter UNTER den Tabellen, den Meta-Key zusätzlich bei der Tabelle unter „Zusätzliche Felder“ eintragen.', 'immoadmin'),
        ];

        $this->controls['label'] = [
            'label'          => esc_html__('Label', 'immoadmin'),
            'type'           => 'text',
            'inline'         => true,
            'placeholder'    => esc_html__('z. B. Haus', 'immoadmin'),
            'hasDynamicData' => false,
            'description'    => esc_html__('Leer lassen für keinen Label-Text.', 'immoadmin'),
        ];

        $this->controls['selection'] = [
            'label'       => esc_html__('Auswahl', 'immoadmin'),
            'type'        => 'select',
            'options'     => [
                'multiple' => esc_html__('Mehrfachauswahl', 'immoadmin'),
                'single'   => esc_html__('Einfachauswahl', 'immoadmin'),
            ],
            'inline'      => true,
            'placeholder' => esc_html__('Mehrfachauswahl', 'immoadmin'),
            'description' => esc_html__('Mehrere Buttons eines Feldes = ODER. Mehrere Filter-Elemente = UND.', 'immoadmin'),
        ];

        $this->controls['rooms_group_from'] = [
            'label'       => esc_html__('Zusammenfassen ab', 'immoadmin'),
            'type'        => 'number',
            'min'         => 1,
            'inline'      => true,
            'placeholder' => esc_html__('aus', 'immoadmin'),
            'required'    => ['field', '=', 'room_count'],
            'description' => esc_html__('z. B. 4 → ein Button „4+“ für alle Wohnungen ab 4 Zimmern.', 'immoadmin'),
        ];

        // ---------- Optionen ----------
        $this->controls['options_info'] = [
            'group'   => 'options',
            'type'    => 'info',
            'content' => $this->options_hint(),
        ];

        $this->controls['custom_options'] = [
            'group'         => 'options',
            'label'         => esc_html__('Eigene Optionen', 'immoadmin'),
            'type'          => 'repeater',
            'titleProperty' => 'label',
            'placeholder'   => esc_html__('Option', 'immoadmin'),
            'description'   => esc_html__('Leer = automatisch aus den Wohnungsdaten. Sobald hier Optionen stehen, werden genau diese in dieser Reihenfolge angezeigt.', 'immoadmin'),
            'fields'        => [
                'value' => [
                    'label'          => esc_html__('Wert', 'immoadmin'),
                    'type'           => 'text',
                    'hasDynamicData' => false,
                    'description'    => esc_html__('Mehrere Werte mit | trennen, z. B. 1|2|3 für alle Obergeschosse.', 'immoadmin'),
                ],
                'label' => [
                    'label'          => esc_html__('Beschriftung', 'immoadmin'),
                    'type'           => 'text',
                    'hasDynamicData' => false,
                ],
            ],
        ];

        $this->controls['builder_preview_active'] = [
            'group'       => 'options',
            'label'       => esc_html__('Vorschau: erste Option aktiv (nur Builder)', 'immoadmin'),
            'type'        => 'checkbox',
            'description' => esc_html__('Zum Gestalten des Aktiv-Zustands. Wirkt nicht im Frontend.', 'immoadmin'),
        ];

        // ---------- Layout ----------
        $this->controls['direction'] = [
            'group'  => 'layout',
            'label'  => esc_html__('Richtung (Label / Buttons)', 'immoadmin'),
            'type'   => 'direction',
            'inline' => true,
            'css'    => [['property' => 'flex-direction']],
        ];
        $this->controls['alignItems'] = [
            'group'  => 'layout',
            'label'  => esc_html__('Ausrichtung', 'immoadmin'),
            'type'   => 'align-items',
            'inline' => true,
            'css'    => [['property' => 'align-items']],
        ];
        $this->controls['gap'] = [
            'group'       => 'layout',
            'label'       => esc_html__('Abstand Label – Buttons', 'immoadmin'),
            'type'        => 'number',
            'units'       => true,
            'placeholder' => '1em',
            'css'         => [['property' => 'gap']],
        ];
        $this->controls['optionsGap'] = [
            'group'       => 'layout',
            'label'       => esc_html__('Abstand zwischen Buttons', 'immoadmin'),
            'type'        => 'number',
            'units'       => true,
            'placeholder' => '0.5em',
            'css'         => [['property' => 'gap', 'selector' => '.immoadmin-filter-options']],
        ];
        $this->controls['optionsJustify'] = [
            'group'  => 'layout',
            'label'  => esc_html__('Buttons ausrichten', 'immoadmin'),
            'type'   => 'justify-content',
            'inline' => true,
            'css'    => [['property' => 'justify-content', 'selector' => '.immoadmin-filter-options']],
        ];

        // ---------- Label ----------
        $this->controls['labelTypography'] = [
            'group' => 'label',
            'label' => esc_html__('Typografie', 'immoadmin'),
            'type'  => 'typography',
            'css'   => [['property' => 'font', 'selector' => '.immoadmin-filter-label']],
        ];
        $this->controls['labelWidth'] = [
            'group'       => 'label',
            'label'       => esc_html__('Breite', 'immoadmin'),
            'type'        => 'number',
            'units'       => true,
            'placeholder' => 'auto',
            'description' => esc_html__('Gleiche Breite bei allen Filter-Zeilen = Buttons stehen bündig untereinander.', 'immoadmin'),
            'css'         => [
                ['property' => 'width', 'selector' => '.immoadmin-filter-label'],
                ['property' => 'flex-shrink', 'selector' => '.immoadmin-filter-label', 'value' => '0'],
            ],
        ];

        // ---------- Buttons ----------
        $this->controls = array_merge($this->controls, $this->button_controls(
            'option',
            'buttons',
            '.immoadmin-filter-option',
            esc_html__('Button', 'immoadmin'),
            '.immoadmin-filter-option.brx-option-active'
        ));
    }

    /**
     * Builder-only hint listing the values found in the synced units, so
     * "Eigene Optionen" can be filled with the right values. No DB access on
     * the frontend (Bricks builds controls on every request).
     */
    private function options_hint() {
        $base = esc_html__('Optionen entstehen automatisch aus den synchronisierten Wohnungen.', 'immoadmin');
        if (!ImmoAdmin_Filter_Data::is_builder()) {
            return $base;
        }
        $rows = ImmoAdmin_Filter_Data::rows();
        if (empty($rows)) {
            return $base . ' ' . esc_html__('Noch keine Daten – nach dem ersten Sync erscheinen hier die Werte.', 'immoadmin');
        }
        $lines = [];
        foreach (['building_name' => 'Haus', 'floor' => 'Geschoss', 'orientation' => 'Ausrichtung', 'room_count' => 'Zimmer'] as $field => $label) {
            $opts = ImmoAdmin_Filter_Data::button_options($field, $rows);
            $lines[] = $label . ': ' . implode(', ', array_map(function ($o) {
                return $o['value'] === $o['label'] ? $o['label'] : $o['label'] . ' = ' . $o['value'];
            }, $opts));
        }
        return $base . ' ' . esc_html__('Werte für eigene Optionen:', 'immoadmin') . ' ' . esc_html(implode(' · ', $lines));
    }

    /** Field key, data key and match type from the settings. */
    public static function resolve_field($settings) {
        $fields = ImmoAdmin_Filter_Data::button_fields();
        $field  = isset($settings['field']) && is_string($settings['field']) && isset($fields[$settings['field']])
            ? $settings['field']
            : 'building_name';
        $key = $field === '__custom' ? ImmoAdmin_Filter_Data::sanitize_meta_key($settings['field_custom'] ?? '') : $field;
        return ['field' => $field, 'key' => $key, 'match' => $fields[$field]['match']];
    }

    /** Final option list for these settings and rows. */
    public static function options_for($settings, array $rows) {
        $f = self::resolve_field($settings);
        if ($f['key'] === '') {
            return [];
        }
        $auto = ImmoAdmin_Filter_Data::button_options($f['field'], $rows, [
            'group_from' => isset($settings['rooms_group_from']) && is_numeric($settings['rooms_group_from']) ? (float) $settings['rooms_group_from'] : 0,
            'key'        => $f['key'],
        ]);
        return ImmoAdmin_Filter_Data::apply_custom_options($auto, $settings['custom_options'] ?? []);
    }

    public function render() {
        $settings = $this->settings;
        $group = $this->prepare_root('buttons');

        $f = self::resolve_field($settings);
        if ($f['key'] === '') {
            return $this->render_element_placeholder(['title' => esc_html__('Bitte einen Meta-Key eintragen.', 'immoadmin')]);
        }
        if ($f['field'] === '__custom') {
            ImmoAdmin_Filter_Data::register_custom_key($group, $f['key']);
        }
        $this->announce($f['field'] === '__custom' ? $f['key'] : '');

        $rows    = ImmoAdmin_Filter_Data::rows($f['field'] === '__custom' ? [$f['key']] : []);
        $options = self::options_for($settings, $rows);
        if (empty($options)) {
            return $this->render_element_placeholder(['title' => esc_html__('Keine Werte gefunden – nach dem ersten Sync erscheinen hier die Optionen.', 'immoadmin')]);
        }

        $multiple = ($settings['selection'] ?? 'multiple') !== 'single';
        $this->set_attribute('_root', 'data-immoadmin-filter-config', wp_json_encode([
            'key'      => $f['key'],
            'match'    => $f['match'],
            'multiple' => $multiple,
        ]));

        $label    = isset($settings['label']) && is_string($settings['label']) ? trim($settings['label']) : '';
        $label_id = 'iaf-label-' . $this->id;
        $fields   = ImmoAdmin_Filter_Data::button_fields();
        $aria     = $label !== ''
            ? ' aria-labelledby="' . esc_attr($label_id) . '"'
            : ' aria-label="' . esc_attr($f['field'] === '__custom' ? $f['key'] : wp_strip_all_tags($fields[$f['field']]['label'])) . '"';

        $preview_first = !empty($settings['builder_preview_active']) && ImmoAdmin_Filter_Data::is_builder();
        $btn_classes   = array_merge(['immoadmin-filter-option'], $this->button_preset_classes('option'));

        echo "<div {$this->render_attributes('_root')}>";
        if ($label !== '') {
            echo '<span class="immoadmin-filter-label" id="' . esc_attr($label_id) . '">' . esc_html($label) . '</span>';
        }
        echo '<ul class="immoadmin-filter-options" role="group"' . $aria . '>';
        foreach ($options as $i => $o) {
            $active  = $preview_first && $i === 0;
            $classes = $btn_classes;
            if ($active) {
                $classes[] = 'brx-option-active';
            }
            echo '<li class="immoadmin-filter-item' . ($active ? ' brx-option-active' : '') . '">';
            echo '<button type="button" class="' . esc_attr(implode(' ', $classes)) . '"'
                . ' aria-pressed="' . ($active ? 'true' : 'false') . '"'
                . ' data-value="' . esc_attr($o['value']) . '">'
                . esc_html($o['label'])
                . '</button>';
            echo '</li>';
        }
        echo '</ul>';
        echo '</div>';
    }
}
