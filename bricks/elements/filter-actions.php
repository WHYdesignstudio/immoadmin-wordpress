<?php
/**
 * Bricks Element: ImmoAdmin Filter-Aktionen (v2.14.0)
 *
 * "Suchen" + "Filter zurücksetzen" for the filters it is responsible for — the counterpart
 * of Bricks' native "Filter – Submit / Reset", both buttons in one element,
 * each separately stylable and hideable.
 *
 * Responsible for (v2.15.0): every filter that acts on at least one of
 * its tables (Ziel-Tabellen ∪ Filter-Gruppe; neither = all tables, i.e.
 * all filters on the page). Its "Filter anwenden" setting decides for those
 * filters: on "Suchen" click (default) or instantly on every change. A
 * filter no actions element is responsible for always filters instantly.
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

class ImmoAdmin_Filter_Actions extends ImmoAdmin_Filter_Element {

    public $name = 'immoadmin-filter-actions';
    public $icon = 'ti-search';

    public function get_label() {
        return esc_html__('ImmoAdmin Filter-Aktionen', 'immoadmin');
    }

    public function set_control_groups() {
        $this->control_groups['layout'] = ['title' => esc_html__('Layout', 'immoadmin')];
        $this->control_groups['submit'] = ['title' => esc_html__('Suchen-Button', 'immoadmin')];
        $this->control_groups['reset']  = ['title' => esc_html__('Zurücksetzen-Button', 'immoadmin')];
    }

    public function set_controls() {
        $this->controls = array_merge($this->controls, $this->targeting_controls(
            esc_html__('Leer = alle ImmoAdmin-Tabellen auf dieser Seite: „Suchen“ und „Zurücksetzen“ gelten dann für alle Filter der Seite. Sonst gelten sie für alle Filter, die auf mindestens eine der gewählten Tabellen wirken.', 'immoadmin')
        ));

        $this->controls['apply_on'] = [
            'label'       => esc_html__('Filter anwenden', 'immoadmin'),
            'type'        => 'select',
            'options'     => [
                'click'  => esc_html__('Beim Klick auf „Suchen“', 'immoadmin'),
                'change' => esc_html__('Sofort bei jeder Änderung', 'immoadmin'),
            ],
            'inline'      => true,
            'placeholder' => esc_html__('Beim Klick auf „Suchen“', 'immoadmin'),
            'description' => esc_html__('Gilt für alle Filter, für die dieses Element zuständig ist (siehe Ziel-Tabellen). Filter ohne zuständiges Aktionen-Element filtern immer sofort.', 'immoadmin'),
        ];

        // ---------- Suchen ----------
        $this->controls['hide_submit'] = [
            'group' => 'submit',
            'label' => esc_html__('„Suchen“ ausblenden', 'immoadmin'),
            'type'  => 'checkbox',
        ];
        $this->controls['submit_text'] = [
            'group'          => 'submit',
            'label'          => esc_html__('Text', 'immoadmin'),
            'type'           => 'text',
            'inline'         => true,
            'default'        => esc_html__('Suchen', 'immoadmin'),
            'hasDynamicData' => false,
            'required'       => ['hide_submit', '=', ''],
        ];
        $this->controls['submitIcon'] = [
            'group'    => 'submit',
            'label'    => esc_html__('Icon', 'immoadmin'),
            'type'     => 'icon',
            'default'  => ['library' => 'themify', 'icon' => 'ti-arrow-right'],
            'required' => ['hide_submit', '=', ''],
        ];
        $this->controls['submitIconPosition'] = [
            'group'       => 'submit',
            'label'       => esc_html__('Icon-Position', 'immoadmin'),
            'type'        => 'select',
            'options'     => ['left' => esc_html__('Links', 'immoadmin'), 'right' => esc_html__('Rechts', 'immoadmin')],
            'inline'      => true,
            'placeholder' => esc_html__('Rechts', 'immoadmin'),
            'required'    => ['submitIcon.icon', '!=', ''],
        ];
        $this->controls = array_merge($this->controls, $this->icon_controls('submit', '.immoadmin-filter-submit'));
        $this->controls = array_merge($this->controls, $this->button_controls(
            'submit', 'submit', '.immoadmin-filter-submit', esc_html__('Button', 'immoadmin'), null,
            ['Style' => 'primary']
        ));
        $this->controls['submitPendingSep'] = [
            'group' => 'submit', 'type' => 'separator',
            'label' => esc_html__('Button (ungespeicherte Änderungen)', 'immoadmin'),
            'description' => esc_html__('Solange Filter geändert, aber noch nicht gesucht wurden.', 'immoadmin'),
        ];
        $this->controls['submitPendingBackground'] = [
            'group' => 'submit', 'label' => esc_html__('Hintergrundfarbe', 'immoadmin'), 'type' => 'color',
            'css' => [['property' => 'background-color', 'selector' => '.immoadmin-filter-submit.has-pending']],
        ];
        $this->controls['submitPendingBorder'] = [
            'group' => 'submit', 'label' => esc_html__('Rahmen', 'immoadmin'), 'type' => 'border',
            'css' => [['property' => 'border', 'selector' => '.immoadmin-filter-submit.has-pending']],
        ];

        // ---------- Zurücksetzen ----------
        $this->controls['hide_reset'] = [
            'group' => 'reset',
            'label' => esc_html__('„Zurücksetzen“ ausblenden', 'immoadmin'),
            'type'  => 'checkbox',
        ];
        $this->controls['reset_text'] = [
            'group'          => 'reset',
            'label'          => esc_html__('Text', 'immoadmin'),
            'type'           => 'text',
            'inline'         => true,
            'default'        => esc_html__('Filter zurücksetzen', 'immoadmin'),
            'hasDynamicData' => false,
            'required'       => ['hide_reset', '=', ''],
        ];
        $this->controls['reset_hide_inactive'] = [
            'group'       => 'reset',
            'label'       => esc_html__('Ausblenden, wenn kein Filter aktiv', 'immoadmin'),
            'type'        => 'checkbox',
            'required'    => ['hide_reset', '=', ''],
            'description' => esc_html__('.immoadmin-no-active-filter wird gesetzt, solange nichts gefiltert ist.', 'immoadmin'),
        ];
        $this->controls['resetIcon'] = [
            'group'    => 'reset',
            'label'    => esc_html__('Icon', 'immoadmin'),
            'type'     => 'icon',
            'required' => ['hide_reset', '=', ''],
        ];
        $this->controls['resetIconPosition'] = [
            'group'       => 'reset',
            'label'       => esc_html__('Icon-Position', 'immoadmin'),
            'type'        => 'select',
            'options'     => ['left' => esc_html__('Links', 'immoadmin'), 'right' => esc_html__('Rechts', 'immoadmin')],
            'inline'      => true,
            'placeholder' => esc_html__('Rechts', 'immoadmin'),
            'required'    => ['resetIcon.icon', '!=', ''],
        ];
        $this->controls = array_merge($this->controls, $this->icon_controls('reset', '.immoadmin-filter-reset'));
        $this->controls = array_merge($this->controls, $this->button_controls(
            'reset', 'reset', '.immoadmin-filter-reset', esc_html__('Button', 'immoadmin'), null,
            ['Style' => 'primary', 'Outline' => true]
        ));

        // ---------- Layout ----------
        $this->controls['direction'] = [
            'group'  => 'layout',
            'label'  => esc_html__('Richtung', 'immoadmin'),
            'type'   => 'direction',
            'inline' => true,
            'css'    => [['property' => 'flex-direction']],
        ];
        $this->controls['justifyContent'] = [
            'group'  => 'layout',
            'label'  => esc_html__('Ausrichtung (Hauptachse)', 'immoadmin'),
            'type'   => 'justify-content',
            'inline' => true,
            'css'    => [['property' => 'justify-content']],
        ];
        $this->controls['alignItems'] = [
            'group'  => 'layout',
            'label'  => esc_html__('Ausrichtung (Querachse)', 'immoadmin'),
            'type'   => 'align-items',
            'inline' => true,
            'css'    => [['property' => 'align-items']],
        ];
        $this->controls['gap'] = [
            'group'       => 'layout',
            'label'       => esc_html__('Abstand', 'immoadmin'),
            'type'        => 'number',
            'units'       => true,
            'placeholder' => '1em',
            'css'         => [['property' => 'gap']],
        ];
    }

    private function icon_controls($p, $sel) {
        return [
            $p . 'IconSize' => [
                'group' => $p, 'label' => esc_html__('Icon-Größe', 'immoadmin'), 'type' => 'number', 'units' => true,
                'required' => [$p . 'Icon.icon', '!=', ''],
                'css' => [['property' => 'font-size', 'selector' => $sel . ' .icon']],
            ],
            $p . 'IconColor' => [
                'group' => $p, 'label' => esc_html__('Icon-Farbe', 'immoadmin'), 'type' => 'color',
                'required' => [$p . 'Icon.icon', '!=', ''],
                'css' => [
                    ['property' => 'color', 'selector' => $sel . ' .icon'],
                    ['property' => 'fill', 'selector' => $sel . ' .icon'],
                ],
            ],
            $p . 'IconGap' => [
                'group' => $p, 'label' => esc_html__('Abstand Icon – Text', 'immoadmin'), 'type' => 'number', 'units' => true,
                'placeholder' => '0.5em',
                'required' => [$p . 'Icon.icon', '!=', ''],
                'css' => [['property' => 'gap', 'selector' => $sel]],
            ],
        ];
    }

    private function render_button($p, $action, $default_text) {
        $s    = $this->settings;
        $text = isset($s[$p . '_text']) && is_string($s[$p . '_text']) && $s[$p . '_text'] !== '' ? $s[$p . '_text'] : $default_text;
        $icon = !empty($s[$p . 'Icon']['icon']) || !empty($s[$p . 'Icon']['svg']) ? self::render_icon($s[$p . 'Icon'], ['icon']) : '';
        $left = ($s[$p . 'IconPosition'] ?? 'right') === 'left';

        $classes = array_merge(['immoadmin-filter-' . $p], $this->button_preset_classes($p, true));
        $extra   = '';
        if ($p === 'reset' && !empty($s['reset_hide_inactive'])) {
            $extra .= ' data-hide-inactive="1"';
            if (!ImmoAdmin_Filter_Data::is_builder()) {
                // Nothing is filtered on page load — JS removes it on the first change.
                $classes[] = 'immoadmin-no-active-filter';
            }
        }

        $html  = '<button type="button" class="' . esc_attr(implode(' ', $classes)) . '" data-immoadmin-filter-action="' . esc_attr($action) . '"' . $extra . '>';
        $label = '<span class="text">' . esc_html($text) . '</span>';
        $html .= $left ? $icon . $label : $label . $icon;
        $html .= '</button>';
        return $html;
    }

    public function render() {
        $settings = $this->settings;
        $this->prepare_root('actions');

        $apply_on = ($settings['apply_on'] ?? 'click') === 'change' ? 'change' : 'click';
        $this->set_attribute('_root', 'data-apply-on', $apply_on);

        $show_submit = empty($settings['hide_submit']);
        $show_reset  = empty($settings['hide_reset']);
        if (!$show_submit && !$show_reset) {
            // Still rendered (empty) so the group keeps the chosen apply mode.
            $this->set_attribute('_root', 'class', 'immoadmin-filter-actions--empty');
        }

        echo "<div {$this->render_attributes('_root')}>";
        if ($show_submit) {
            echo $this->render_button('submit', 'submit', __('Suchen', 'immoadmin'));
        }
        if ($show_reset) {
            echo $this->render_button('reset', 'reset', __('Filter zurücksetzen', 'immoadmin'));
        }
        echo '</div>';
    }
}
