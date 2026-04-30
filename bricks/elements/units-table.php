<?php
/**
 * Bricks Element: ImmoAdmin Units Table
 *
 * Renders a filterable, sortable, optional-accordion table of immoadmin_wohnung
 * posts inside the Bricks Builder. Integrates with the standard Bricks
 * Query / Filter / Pagination contract via render_query_loop_trail().
 *
 * @package ImmoAdmin\Bricks
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('\\Bricks\\Element')) {
    return;
}

class ImmoAdmin_Units_Table extends \Bricks\Element {

    public $category = 'immoadmin';
    public $name     = 'immoadmin-units-table';
    public $icon     = 'ti-layout-list-thumb';
    public $scripts  = ['bricksUnitsTableInit'];

    // Always true so the accordion slot can hold child elements; the slot is
    // rendered conditionally in render() based on the `mode` setting.
    public $nestable = true;

    /**
     * Builder label
     */
    public function get_label() {
        return esc_html__('ImmoAdmin Units Table', 'immoadmin');
    }

    /**
     * Search keywords for the "Add element" panel
     */
    public function get_keywords() {
        return ['immoadmin', 'units', 'table', 'wohnungen', 'flatfinder'];
    }

    /**
     * Enqueue element assets — invoked by Bricks only when the element renders.
     */
    public function enqueue_scripts() {
        wp_enqueue_style(
            'immoadmin-units-table',
            IMMOADMIN_PLUGIN_URL . 'bricks/assets/css/units-table.css',
            [],
            IMMOADMIN_VERSION
        );

        wp_enqueue_script(
            'immoadmin-units-table',
            IMMOADMIN_PLUGIN_URL . 'bricks/assets/js/units-table.js',
            [],
            IMMOADMIN_VERSION,
            true
        );
    }

    /**
     * Builder control groups
     */
    public function set_control_groups() {
        $this->control_groups['query'] = [
            'title' => esc_html__('Query', 'immoadmin'),
            'tab'   => 'content',
        ];

        $this->control_groups['columns'] = [
            'title' => esc_html__('Spalten', 'immoadmin'),
            'tab'   => 'content',
        ];

        $this->control_groups['behavior'] = [
            'title' => esc_html__('Verhalten', 'immoadmin'),
            'tab'   => 'content',
        ];

        $this->control_groups['status_colors'] = [
            'title' => esc_html__('Status-Farben', 'immoadmin'),
            'tab'   => 'content',
        ];

        $this->control_groups['table_style'] = [
            'title' => esc_html__('Tabelle Stil', 'immoadmin'),
            'tab'   => 'style',
        ];
    }

    /**
     * Builder controls
     */
    public function set_controls() {
        // ---------- Query ----------
        // Reuse Bricks' standard hasLoop + query controls so our element
        // appears in the Filter/Pagination "Target query" dropdowns.
        $loop_controls = self::get_loop_builder_controls();

        if (isset($loop_controls['hasLoop'])) {
            $loop_controls['hasLoop']['group'] = 'query';
            $loop_controls['hasLoop']['default'] = true;
        }
        if (isset($loop_controls['query'])) {
            $loop_controls['query']['group'] = 'query';
            $loop_controls['query']['default'] = [
                'objectType'     => 'post',
                'post_type'      => ['immoadmin_wohnung'],
                'posts_per_page' => -1,
                'orderby'        => 'meta_value_num',
                'meta_key'       => 'sort_key',
                'order'          => 'ASC',
            ];
        }

        $this->controls = array_merge($this->controls, $loop_controls);

        // ---------- Columns ----------
        $this->controls['columnsInfo'] = [
            'tab'     => 'content',
            'group'   => 'columns',
            'type'    => 'info',
            'content' => $this->favorites_hint_html(),
        ];

        $this->controls['columns'] = [
            'tab'           => 'content',
            'group'         => 'columns',
            'label'         => esc_html__('Spalten', 'immoadmin'),
            'type'          => 'repeater',
            'titleProperty' => 'header',
            'placeholder'   => esc_html__('Spalte', 'immoadmin'),
            'default'       => [
                [
                    'header'         => esc_html__('Top', 'immoadmin'),
                    'value'          => '{cf_door_number}',
                    'type'           => 'text',
                    'sortable'       => true,
                    'sort_meta_key'  => 'door_number',
                    'mobile_visible' => true,
                    'align'          => 'left',
                ],
            ],
            'fields' => [
                'header' => [
                    'label' => esc_html__('Spaltenüberschrift', 'immoadmin'),
                    'type'  => 'text',
                ],
                'value' => [
                    'label'          => esc_html__('Wert (Dynamic Data)', 'immoadmin'),
                    'type'           => 'text',
                    'hasDynamicData' => true,
                    'placeholder'    => '{cf_living_area_formatted}',
                ],
                'type' => [
                    'label'     => esc_html__('Spaltentyp', 'immoadmin'),
                    'type'      => 'select',
                    'options'   => [
                        'text'          => esc_html__('Text', 'immoadmin'),
                        'link'          => esc_html__('Link', 'immoadmin'),
                        'image'         => esc_html__('Bild', 'immoadmin'),
                        'status_badge'  => esc_html__('Status-Badge (Pille mit Text)', 'immoadmin'),
                        'status_dot'    => esc_html__('Status-Punkt (nur Farbe)', 'immoadmin'),
                        'html'          => esc_html__('HTML', 'immoadmin'),
                    ],
                    'default'   => 'text',
                    'clearable' => false,
                    'inline'    => true,
                ],
                'link_text' => [
                    'label'          => esc_html__('Link-Text', 'immoadmin'),
                    'type'           => 'text',
                    'hasDynamicData' => true,
                    'required'       => ['type', '=', 'link'],
                ],
                'link_target' => [
                    'label'    => esc_html__('Link-Ziel', 'immoadmin'),
                    'type'     => 'select',
                    'options'  => [
                        '_self'  => '_self',
                        '_blank' => '_blank',
                    ],
                    'default'  => '_self',
                    'inline'   => true,
                    'required' => ['type', '=', 'link'],
                ],
                'sortable' => [
                    'label'   => esc_html__('Sortierbar', 'immoadmin'),
                    'type'    => 'checkbox',
                    'default' => true,
                    'inline'  => true,
                    'small'   => true,
                ],
                'sort_meta_key' => [
                    'label'    => esc_html__('Sortier-Meta-Key', 'immoadmin'),
                    'type'     => 'text',
                    'info'     => esc_html__('Leer lassen, um Meta-Key automatisch aus dem Wert abzuleiten.', 'immoadmin'),
                    'required' => ['sortable', '!=', ''],
                ],
                'mobile_visible' => [
                    'label'   => esc_html__('Auf Mobil sichtbar', 'immoadmin'),
                    'type'    => 'checkbox',
                    'default' => true,
                    'inline'  => true,
                    'small'   => true,
                ],
                'compact' => [
                    'label'   => esc_html__('Kompakt (auto-Breite)', 'immoadmin'),
                    'type'    => 'checkbox',
                    'info'    => esc_html__('Spalte nimmt nur so viel Breite wie der Inhalt — gut für Status-Punkte oder Icon-Spalten.', 'immoadmin'),
                    'inline'  => true,
                    'small'   => true,
                ],
                'align' => [
                    'label'   => esc_html__('Ausrichtung', 'immoadmin'),
                    'type'    => 'select',
                    'options' => [
                        'left'   => esc_html__('Links', 'immoadmin'),
                        'center' => esc_html__('Zentriert', 'immoadmin'),
                        'right'  => esc_html__('Rechts', 'immoadmin'),
                    ],
                    'default' => 'left',
                    'inline'  => true,
                ],
            ],
        ];

        // ---------- Behavior ----------
        $this->controls['mode'] = [
            'tab'       => 'content',
            'group'     => 'behavior',
            'label'     => esc_html__('Modus', 'immoadmin'),
            'type'      => 'select',
            'options'   => [
                'table'     => esc_html__('Tabelle pur', 'immoadmin'),
                'accordion' => esc_html__('Mit Akkordion', 'immoadmin'),
            ],
            'default'   => 'accordion',
            'clearable' => false,
            'inline'    => true,
        ];

        $this->controls['default_sort_key'] = [
            'tab'         => 'content',
            'group'       => 'behavior',
            'label'       => esc_html__('Standard-Sortierfeld', 'immoadmin'),
            'type'        => 'text',
            'default'     => 'sort_key',
            'placeholder' => 'sort_key',
        ];

        $this->controls['default_sort_order'] = [
            'tab'       => 'content',
            'group'     => 'behavior',
            'label'     => esc_html__('Standard-Sortierrichtung', 'immoadmin'),
            'type'      => 'select',
            'options'   => [
                'ASC'  => 'ASC',
                'DESC' => 'DESC',
            ],
            'default'   => 'ASC',
            'inline'    => true,
            'clearable' => false,
        ];

        $this->controls['inline_sort_enabled'] = [
            'tab'     => 'content',
            'group'   => 'behavior',
            'label'   => esc_html__('Spalten-Klick-Sortierung', 'immoadmin'),
            'type'    => 'checkbox',
            'default' => true,
        ];

        $this->controls['status_handling'] = [
            'tab'       => 'content',
            'group'     => 'behavior',
            'label'     => esc_html__('Verkaufte Wohnungen', 'immoadmin'),
            'type'      => 'select',
            'options'   => [
                'show' => esc_html__('Anzeigen', 'immoadmin'),
                'dim'  => esc_html__('Abblenden', 'immoadmin'),
                'hide' => esc_html__('Ausblenden', 'immoadmin'),
            ],
            'default'   => 'show',
            'inline'    => true,
            'clearable' => false,
        ];

        $this->controls['horizontal_scroll'] = [
            'tab'     => 'content',
            'group'   => 'behavior',
            'label'   => esc_html__('Horizontal scrollen', 'immoadmin'),
            'type'    => 'checkbox',
            'default' => false,
        ];

        $this->controls['empty_message'] = [
            'tab'         => 'content',
            'group'       => 'behavior',
            'label'       => esc_html__('Leer-Meldung', 'immoadmin'),
            'type'        => 'text',
            'default'     => esc_html__('Keine Wohnungen gefunden.', 'immoadmin'),
            'placeholder' => esc_html__('Keine Wohnungen gefunden.', 'immoadmin'),
        ];

        $this->controls['url_state_enabled'] = [
            'tab'     => 'content',
            'group'   => 'behavior',
            'label'   => esc_html__('URL-State (?unit=ID)', 'immoadmin'),
            'type'    => 'checkbox',
            'default' => true,
            'desc'    => esc_html__('Öffnet beim Aufruf das passende Akkordion und scrollt dorthin.', 'immoadmin'),
        ];

        // ---------- Status colors ----------
        $this->controls['color_available'] = [
            'tab'   => 'content',
            'group' => 'status_colors',
            'label' => esc_html__('Verfügbar', 'immoadmin'),
            'type'  => 'color',
            'default' => ['hex' => '#10b981'],
            'css'   => [
                ['property' => '--iat-color-available'],
            ],
        ];

        $this->controls['color_reserved'] = [
            'tab'   => 'content',
            'group' => 'status_colors',
            'label' => esc_html__('Reserviert', 'immoadmin'),
            'type'  => 'color',
            'default' => ['hex' => '#f59e0b'],
            'css'   => [
                ['property' => '--iat-color-reserved'],
            ],
        ];

        $this->controls['color_sold'] = [
            'tab'   => 'content',
            'group' => 'status_colors',
            'label' => esc_html__('Verkauft', 'immoadmin'),
            'type'  => 'color',
            'default' => ['hex' => '#ef4444'],
            'css'   => [
                ['property' => '--iat-color-sold'],
            ],
        ];

        $this->controls['dot_size'] = [
            'tab'         => 'content',
            'group'       => 'status_colors',
            'label'       => esc_html__('Punkt-Größe', 'immoadmin'),
            'type'        => 'number',
            'units'       => true,
            'min'         => 1,
            'placeholder' => '0.75em',
            'info'        => esc_html__('Nur für Status-Punkt. Beispiel: 8px, 0.5em, 1rem.', 'immoadmin'),
            'css'         => [
                [
                    'property' => '--iat-dot-size',
                    'selector' => '',
                ],
            ],
        ];

        // ---------- Style ----------
        $this->controls['header_typography'] = [
            'tab'   => 'style',
            'group' => 'table_style',
            'label' => esc_html__('Kopfzeilen-Typografie', 'immoadmin'),
            'type'  => 'typography',
            'css'   => [
                ['property' => 'font', 'selector' => '.immoadmin-table-cell-header'],
            ],
        ];

        $this->controls['header_background'] = [
            'tab'   => 'style',
            'group' => 'table_style',
            'label' => esc_html__('Kopfzeilen-Hintergrund', 'immoadmin'),
            'type'  => 'color',
            'css'   => [
                ['property' => 'background-color', 'selector' => '.immoadmin-table-header'],
            ],
        ];

        $this->controls['row_typography'] = [
            'tab'   => 'style',
            'group' => 'table_style',
            'label' => esc_html__('Zeilen-Typografie', 'immoadmin'),
            'type'  => 'typography',
            'css'   => [
                ['property' => 'font', 'selector' => '.immoadmin-table-row'],
            ],
        ];

        $this->controls['row_background'] = [
            'tab'   => 'style',
            'group' => 'table_style',
            'label' => esc_html__('Zeilen-Hintergrund', 'immoadmin'),
            'type'  => 'color',
            'css'   => [
                ['property' => 'background-color', 'selector' => '.immoadmin-table-row'],
            ],
        ];

        $this->controls['row_alternate_background'] = [
            'tab'   => 'style',
            'group' => 'table_style',
            'label' => esc_html__('Zebra-Streifen', 'immoadmin'),
            'type'  => 'color',
            'css'   => [
                ['property' => 'background-color', 'selector' => '.immoadmin-table-row:nth-child(even)'],
            ],
        ];

        $this->controls['row_border'] = [
            'tab'   => 'style',
            'group' => 'table_style',
            'label' => esc_html__('Zeilen-Border', 'immoadmin'),
            'type'  => 'border',
            'css'   => [
                ['property' => 'border', 'selector' => '.immoadmin-table-row'],
            ],
        ];

        $this->controls['row_padding'] = [
            'tab'   => 'style',
            'group' => 'table_style',
            'label' => esc_html__('Zellen-Padding', 'immoadmin'),
            'type'  => 'spacing',
            'css'   => [
                ['property' => 'padding', 'selector' => '.immoadmin-table-cell, .immoadmin-table-cell-header'],
            ],
        ];

        $this->controls['accordion_body_background'] = [
            'tab'   => 'style',
            'group' => 'table_style',
            'label' => esc_html__('Akkordion-Body-Hintergrund', 'immoadmin'),
            'type'  => 'color',
            'css'   => [
                ['property' => 'background-color', 'selector' => '.immoadmin-accordion-panel'],
            ],
        ];
    }

    /**
     * Quick-add favorites copy/paste hint.
     */
    private function favorites_hint_html() {
        $rows = [
            'Haus'             => '{cf_building_name}',
            'Top'              => '{cf_door_number}',
            'Geschoss'         => '{cf_floor_label}',
            'Zimmer'           => '{cf_room_count}',
            'Wohnfläche'       => '{cf_living_area_formatted}',
            'Nutzfläche'       => '{cf_usable_area_formatted}',
            'Balkon'           => '{cf_balcony_area_formatted}',
            'Terrasse'         => '{cf_terrace_area_formatted}',
            'Loggia'           => '{cf_loggia_area_formatted}',
            'Garten'           => '{cf_garden_area_formatted}',
            'Dachterrasse'     => '{cf_roof_terrace_area_formatted}',
            'Freifläche gesamt'=> '{cf_outdoor_area_total_formatted}',
            'Ausrichtung'      => '{cf_orientation}',
            'Kaufpreis'        => '{cf_purchase_price_formatted}',
            'Exposé'           => '{cf_document_1_url}',
            'Status'           => '{cf_status}',
        ];

        $html = '<strong>' . esc_html__('Häufig genutzte Werte', 'immoadmin') . '</strong><br>';
        $html .= '<ul style="margin:6px 0 0 0;padding-left:16px;font-size:11px;line-height:1.6;">';
        foreach ($rows as $label => $tag) {
            $html .= '<li>' . esc_html($label) . ': <code>' . esc_html($tag) . '</code></li>';
        }
        $html .= '</ul>';

        return $html;
    }

    /**
     * Default nestable item — one block with the accordion-content-wrapper hook.
     * Used when the editor adds a new item via the _children repeater.
     */
    public function get_nestable_item() {
        return [
            'name'     => 'block',
            'label'    => esc_html__('Detail', 'immoadmin'),
            'settings' => [],
            'children' => [
                [
                    'name'     => 'text',
                    'settings' => [
                        'text' => esc_html__('Detail-Inhalt hier ablegen.', 'immoadmin'),
                    ],
                ],
            ],
        ];
    }

    /**
     * Children spawned once when the user drops the element on the canvas.
     */
    public function get_nestable_children() {
        return [$this->get_nestable_item()];
    }

    // -----------------------------------------------------------------
    // RENDER
    // -----------------------------------------------------------------

    /**
     * Element render — outputs the table shell, runs the Bricks query,
     * emits the loop trail and conditionally renders accordion children.
     */
    public function render() {
        $settings = $this->settings;

        $columns = isset($settings['columns']) && is_array($settings['columns'])
            ? $settings['columns']
            : [];

        $mode             = !empty($settings['mode']) ? $settings['mode'] : 'accordion';
        $status_handling  = !empty($settings['status_handling']) ? $settings['status_handling'] : 'show';
        $horizontal_scroll = !empty($settings['horizontal_scroll']);
        $url_state         = !empty($settings['url_state_enabled']);
        $inline_sort       = !empty($settings['inline_sort_enabled']);

        // Build _root attributes.
        $this->set_attribute('_root', 'data-element', 'immoadmin-units-table');
        $this->set_attribute('_root', 'data-mode', $mode);
        $this->set_attribute('_root', 'data-status-handling', $status_handling);
        if ($url_state) {
            $this->set_attribute('_root', 'data-url-state', '1');
        }
        if ($inline_sort) {
            $this->set_attribute('_root', 'data-inline-sort', '1');
        }
        // Build per-column grid track sizes. Compact columns get min-content
        // (e.g. status dot), all others share the remaining 1fr equally.
        $tracks = [];
        foreach ($columns as $col) {
            $tracks[] = !empty($col['compact']) ? 'max-content' : 'minmax(min-content, 1fr)';
        }
        $grid_template = !empty($tracks) ? implode(' ', $tracks) : 'minmax(min-content, 1fr)';
        $this->set_attribute('_root', 'style', '--iat-grid-cols: ' . $grid_template . '; --iat-cols: ' . max(1, count($columns)));
        // Surface the query element id to JS so it can refetch via Bricks filter system.
        $this->set_attribute('_root', 'data-bricks-query-id', $this->id);

        // In Bricks builder iframe: flag so CSS can force the first row's
        // accordion panel open (designer can't style what they can't see).
        if (function_exists('bricks_is_builder_iframe') && bricks_is_builder_iframe()) {
            $this->set_attribute('_root', 'data-builder', '1');
        }

        echo "<div {$this->render_attributes('_root')}>";

        if ($horizontal_scroll) {
            echo '<div class="immoadmin-table-scroll">';
        }

        echo '<div class="immoadmin-table" role="table">';

        // --- Header row ---
        echo '<div class="immoadmin-table-header" role="row">';
        if (empty($columns)) {
            echo '<div class="immoadmin-table-cell-header" role="columnheader">' .
                esc_html__('Bitte mindestens eine Spalte konfigurieren.', 'immoadmin') . '</div>';
        } else {
            foreach ($columns as $idx => $col) {
                $header        = isset($col['header']) ? (string) $col['header'] : '';
                $align         = !empty($col['align']) ? (string) $col['align'] : 'left';
                $sortable      = !empty($col['sortable']) && $inline_sort;
                $sort_key      = !empty($col['sort_meta_key'])
                    ? (string) $col['sort_meta_key']
                    : self::guess_meta_key_from_dd($col['value'] ?? '');
                $mobile_visible = !empty($col['mobile_visible']) ? '1' : '0';

                $attrs  = ' role="columnheader"';
                $attrs .= ' data-align="' . esc_attr($align) . '"';
                $attrs .= ' data-mobile-visible="' . esc_attr($mobile_visible) . '"';
                $attrs .= ' data-col-index="' . esc_attr((string) $idx) . '"';
                if ($sortable) {
                    $attrs .= ' data-sortable="1"';
                    $attrs .= ' data-sort-key="' . esc_attr($sort_key) . '"';
                    $attrs .= ' tabindex="0"';
                }

                echo '<div class="immoadmin-table-cell-header"' . $attrs . '>';
                echo '<span class="immoadmin-table-cell-header__label">' . esc_html($header) . '</span>';
                if ($sortable) {
                    echo '<span class="immoadmin-sort-icon" aria-hidden="true"></span>';
                }
                echo '</div>';
            }
        }
        echo '</div>';

        // --- Body via Bricks Query ---
        // Use $this->element (the element array Bricks passed to __construct)
        // so our query carries the same id/name/cid/instanceId Filter +
        // Pagination elements look up via Helpers::get_element_data().
        $element = is_array($this->element) ? $this->element : [
            'id'       => $this->id,
            'name'     => $this->name,
            'settings' => $this->settings,
        ];

        // Tag this render so the loop callback can pull instance config without
        // relying on $this (the callback runs as a static method).
        $GLOBALS['immoadmin_units_table_render_context'] = [
            'columns'          => $columns,
            'mode'             => $mode,
            'status_handling'  => $status_handling,
            'element_id'       => $this->id,
            'element_instance' => $this,
        ];

        $query_obj = new \Bricks\Query($element);

        // Mirror the Container loop pattern: mark element as looped, drop
        // _conditions for per-row evaluation, pass `element` to the callback.
        $element['looped'] = true;
        unset($element['settings']['_conditions']);

        $body_html = $query_obj->render([__CLASS__, 'render_row'], compact('element'));

        if (trim((string) $body_html) === '') {
            $empty_msg = !empty($settings['empty_message'])
                ? $settings['empty_message']
                : esc_html__('Keine Wohnungen gefunden.', 'immoadmin');
            echo '<div class="immoadmin-table-empty" role="row"><div class="immoadmin-table-cell" role="cell">' .
                esc_html($empty_msg) . '</div></div>';
        } else {
            echo $body_html; // Already escaped per cell inside render_row().
        }

        echo '</div>'; // .immoadmin-table

        if ($horizontal_scroll) {
            echo '</div>'; // .immoadmin-table-scroll
        }

        echo '</div>'; // _root

        // Required for Filter / Pagination integration. Must run BEFORE destroy().
        $this->render_query_loop_trail($query_obj);

        $query_obj->destroy();
        unset($query_obj);
        unset($GLOBALS['immoadmin_units_table_render_context']);
    }

    /**
     * Per-iteration callback invoked by Bricks\Query::render().
     *
     * Bricks passes through whatever args we hand to $query->render() — we
     * mirror the Container pattern and forward the element array.
     *
     * @param array $element The element wrapper (with `looped => true`).
     *
     * @return string HTML for one row.
     */
    public static function render_row($element) {
        $ctx = isset($GLOBALS['immoadmin_units_table_render_context'])
            ? $GLOBALS['immoadmin_units_table_render_context']
            : [];

        $columns          = $ctx['columns'] ?? [];
        $mode             = $ctx['mode'] ?? 'accordion';
        $status_handling  = $ctx['status_handling'] ?? 'show';
        $element_id       = $ctx['element_id'] ?? '';
        $element_instance = $ctx['element_instance'] ?? null;

        $post_id = get_the_ID();
        $status  = (string) get_post_meta($post_id, 'status', true);
        $is_sold = ($status === 'sold');

        if ($is_sold && $status_handling === 'hide') {
            return '';
        }

        $row_classes = ['immoadmin-table-row'];
        // Loop-id class so external Pagination JS can target our rows.
        if ($element_id) {
            $row_classes[] = 'brxe-' . sanitize_html_class($element_id);
        }
        if ($status) {
            $row_classes[] = 'is-' . sanitize_html_class($status);
        }
        if ($is_sold && $status_handling === 'dim') {
            $row_classes[] = 'is-dimmed';
        }

        $title_id   = 'immoadmin-row-' . $element_id . '-' . (int) $post_id;
        $content_id = 'immoadmin-panel-' . $element_id . '-' . (int) $post_id;

        $is_accordion = ($mode === 'accordion');

        $output = '';

        if ($is_accordion) {
            // Wrap the row in an accordion-item so the row + content are siblings
            // styled by .accordion-title-wrapper / .accordion-content-wrapper.
            $output .= '<div class="accordion-item ' . esc_attr(implode(' ', array_map('sanitize_html_class', ['immoadmin-table-rowgroup'])))
                . '" role="rowgroup"'
                . ' data-unit-id="' . esc_attr((string) $post_id) . '"'
                . ' data-status="' . esc_attr($status) . '"'
                . '>';

            $row_classes[] = 'accordion-title-wrapper';
            $row_attr  = ' role="row"';
            $row_attr .= ' id="' . esc_attr($title_id) . '"';
            $row_attr .= ' aria-controls="' . esc_attr($content_id) . '"';
            $row_attr .= ' aria-expanded="false"';
            $row_attr .= ' tabindex="0"';
        } else {
            $row_attr  = ' role="row"';
            $row_attr .= ' data-unit-id="' . esc_attr((string) $post_id) . '"';
            $row_attr .= ' data-status="' . esc_attr($status) . '"';
        }

        $output .= '<div class="' . esc_attr(implode(' ', $row_classes)) . '"' . $row_attr . '>';

        foreach ($columns as $idx => $col) {
            $output .= self::render_cell($col, $idx);
        }

        $output .= '</div>'; // .immoadmin-table-row

        if ($is_accordion) {
            // accordion-content-wrapper hosts the children slot. Bricks renders
            // child elements inline (each child has its own <div> etc.). We do
            // NOT wrap the call ourselves — the slot's own children carry the
            // styling class via _hidden._cssClasses (see get_nestable_item()).
            // For loop-iteration rendering, Frontend::render_children must be
            // called via the element wrapper.
            $output .= '<div class="immoadmin-accordion-panel" role="region"'
                . ' id="' . esc_attr($content_id) . '"'
                . ' aria-labelledby="' . esc_attr($title_id) . '"'
                . '>';
            $output .= self::render_accordion_children($element_instance);
            $output .= '</div>';

            $output .= '</div>'; // .accordion-item
        }

        return $output;
    }

    /**
     * Render accordion-body children for the current loop iteration.
     * Falls back to a placeholder string in builder context.
     */
    private static function render_accordion_children($element_instance) {
        // Frontend::render_children expects the Element OBJECT (not array) — it
        // accesses ->element and ->is_frontend on it. Passing the loop's
        // element array triggers PHP warnings.
        if (!$element_instance || !class_exists('\\Bricks\\Frontend')) {
            return '';
        }

        return \Bricks\Frontend::render_children($element_instance, 'div');
    }

    /**
     * Render a single cell.
     */
    private static function render_cell($col, $idx) {
        $type   = !empty($col['type']) ? $col['type'] : 'text';
        $value  = isset($col['value']) ? (string) $col['value'] : '';
        $align  = !empty($col['align']) ? $col['align'] : 'left';
        $mobile = !empty($col['mobile_visible']) ? '1' : '0';

        // Resolve dynamic data once. The global $post is set by Bricks during
        // the loop, so passing 0 lets the resolver find the right post.
        $resolved = bricks_render_dynamic_data($value);

        $sort_value = is_string($resolved) ? $resolved : '';

        $cell_attrs  = ' role="cell"';
        $cell_attrs .= ' data-align="' . esc_attr($align) . '"';
        $cell_attrs .= ' data-mobile-visible="' . esc_attr($mobile) . '"';
        $cell_attrs .= ' data-col-index="' . esc_attr((string) $idx) . '"';
        $cell_attrs .= ' data-sort-value="' . esc_attr($sort_value) . '"';

        $inner = '';

        switch ($type) {
            case 'link':
                $href = esc_url($resolved);
                $text = isset($col['link_text']) && $col['link_text'] !== ''
                    ? bricks_render_dynamic_data((string) $col['link_text'])
                    : $resolved;
                $target = !empty($col['link_target']) ? $col['link_target'] : '_self';
                $rel    = ($target === '_blank') ? ' rel="noopener noreferrer"' : '';
                if ($href !== '') {
                    $inner = '<a class="immoadmin-table-link" href="' . $href .
                        '" target="' . esc_attr($target) . '"' . $rel . '>' .
                        esc_html($text) . '</a>';
                }
                break;

            case 'image':
                $src = esc_url($resolved);
                if ($src !== '') {
                    $inner = '<img class="immoadmin-table-image" src="' . $src .
                        '" alt="" loading="lazy" />';
                }
                break;

            case 'status_badge':
                $status_class = sanitize_html_class($resolved);
                $inner = '<span class="immoadmin-status-badge is-' . esc_attr($status_class) .
                    '">' . esc_html($resolved) . '</span>';
                break;

            case 'status_dot':
                $status_class = sanitize_html_class($resolved);
                $inner = '<span class="immoadmin-status-dot is-' . esc_attr($status_class) .
                    '" aria-label="' . esc_attr($resolved) . '" title="' . esc_attr($resolved) . '"></span>';
                break;

            case 'html':
                $inner = wp_kses_post($resolved);
                break;

            case 'text':
            default:
                $inner = esc_html($resolved);
                break;
        }

        return '<div class="immoadmin-table-cell"' . $cell_attrs . '>' . $inner . '</div>';
    }

    /**
     * Best-effort guess of the meta_key used for sorting from a dynamic data
     * string like "{cf_living_area_formatted}". Strips any "_formatted" suffix
     * because the numeric meta lives under the unformatted key.
     */
    private static function guess_meta_key_from_dd($dd) {
        if (!is_string($dd) || $dd === '') {
            return '';
        }
        if (preg_match('/\{cf_([a-z0-9_]+)\}/i', $dd, $m)) {
            $key = $m[1];
            // Sorting works on the numeric base field, not the formatted string.
            $key = preg_replace('/_formatted$/', '', $key);
            return $key;
        }
        return '';
    }
}
