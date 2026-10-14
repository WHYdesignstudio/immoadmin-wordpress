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

        // Filter widgets (v2.14.0): only for tables that joined a group.
        if (self::filter_group_from_settings($this->settings) !== '' && class_exists('ImmoAdmin_Filter_Data')) {
            ImmoAdmin_Filter_Data::enqueue_assets();
        }
    }

    /**
     * "Filter-Gruppe" of a table ('' = not connected to any filter — the
     * default, and the state of every table placed before v2.14.0).
     */
    public static function filter_group_from_settings($settings) {
        if (!is_array($settings) || empty($settings['immoadmin_filter_group']) || !class_exists('ImmoAdmin_Filter_Data')) {
            return '';
        }
        return ImmoAdmin_Filter_Data::sanitize_group($settings['immoadmin_filter_group']);
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

        $this->control_groups['filter'] = [
            'title' => esc_html__('Filter (ImmoAdmin)', 'immoadmin'),
            'tab'   => 'content',
        ];

        $this->control_groups['table_style'] = [
            'title' => esc_html__('Tabellen-Stil', 'immoadmin'),
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

        // Building filter. No 'default' on purpose: an absent setting means
        // "all buildings", so every widget placed before v2.13.0 keeps its
        // exact query. Applied in apply_building_query_vars().
        //
        // Stores building NAMES, not building_id: names are what the designer
        // recognises in the dropdown. Trade-off: if a building is renamed in
        // ImmoAdmin, the old name stays selected and matches nothing — pick
        // the new name here after the next sync.
        $this->controls['immoadmin_buildings'] = [
            'tab'         => 'content',
            'group'       => 'query',
            'label'       => esc_html__('Gebäude', 'immoadmin'),
            'type'        => 'select',
            'options'     => self::building_name_options(),
            'multiple'    => true,
            'searchable'  => true,
            'clearable'   => true,
            'placeholder' => esc_html__('Alle Gebäude', 'immoadmin'),
            'desc'        => esc_html__('Nur Wohnungen dieser Gebäude anzeigen. Leer = alle. Die Liste füllt sich nach dem ersten Sync. Wird ein Gebäude umbenannt, hier neu auswählen.', 'immoadmin'),
        ];

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
            // Preset for NEWLY dropped widgets only — see default_columns()
            // for why this cannot reach widgets that already exist.
            'default'       => self::default_columns(),
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
                        'icon'          => esc_html__('Icon (optional als Link)', 'immoadmin'),
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
                'icon' => [
                    'label'    => esc_html__('Icon', 'immoadmin'),
                    'type'     => 'icon',
                    'default'  => ['library' => 'themify', 'icon' => 'ti-link'],
                    'required' => ['type', '=', 'icon'],
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
                    // Only the two column types that render a link (v2.15.4;
                    // was "!= text", which also showed it for image/badge/…).
                    'required' => ['type', '=', ['link', 'icon']],
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
                // The three flags below are opt-in (no field default): an
                // existing column that never stored them renders exactly as
                // before. The preset columns switch them on.
                'sort_raw_meta' => [
                    'label'    => esc_html__('Nach Zahlenwert sortieren', 'immoadmin'),
                    'type'     => 'checkbox',
                    'info'     => esc_html__('Klick-Sortierung nach dem Rohwert des Sortier-Meta-Keys statt nach dem angezeigten Text — sonst landet z. B. „1.200.000“ vor „439.800“.', 'immoadmin'),
                    'inline'   => true,
                    'small'    => true,
                    'required' => ['sortable', '!=', ''],
                ],
                'status_color_from_meta' => [
                    'label'    => esc_html__('Farbe aus Status-Feld', 'immoadmin'),
                    'type'     => 'checkbox',
                    'info'     => esc_html__('Färbt Badge/Punkt nach dem Status der Wohnung (verfügbar/reserviert/…), auch wenn der Wert z. B. {cf_status_label} anzeigt.', 'immoadmin'),
                    'inline'   => true,
                    'small'    => true,
                    'required' => ['type', '=', ['status_badge', 'status_dot']],
                ],
                'accordion_toggle' => [
                    'label'    => esc_html__('Akkordion-Pfeil (dreht beim Öffnen)', 'immoadmin'),
                    'type'     => 'checkbox',
                    'info'     => esc_html__('Icon dreht sich um 180°, wenn die Zeile offen ist, und erscheint nur in Zeilen, die sich öffnen lassen.', 'immoadmin'),
                    'inline'   => true,
                    'small'    => true,
                    'required' => ['type', '=', 'icon'],
                ],
                'fallback' => [
                    'label'          => esc_html__('Fallback bei leerem Wert', 'immoadmin'),
                    'type'           => 'text',
                    'default'        => '—',
                    'placeholder'    => '—',
                    'hasDynamicData' => true,
                    'info'           => esc_html__('Wird gezeigt wenn die Wohnung den Wert nicht hat (z.B. kein PDF hochgeladen). Leer lassen für leere Zelle.', 'immoadmin'),
                ],
                'mobile_visible' => [
                    'label'   => esc_html__('Auf Mobil sichtbar', 'immoadmin'),
                    'type'    => 'checkbox',
                    'default' => true,
                    'info'    => esc_html__('Ausgeschaltet = Spalte unter 768 px ausblenden. Gilt zusammen mit „Ausblenden auf“.', 'immoadmin'),
                    'inline'  => true,
                    'small'   => true,
                ],
                // v2.15.3. No default on purpose: an empty value keeps the
                // exact mobile_visible behaviour (and markup) from before.
                // Both apply together (union) — see render().
                'hide_on_breakpoints' => [
                    'label'       => esc_html__('Ausblenden auf', 'immoadmin'),
                    'type'        => 'select',
                    'options'     => self::breakpoint_options(self::site_breakpoints()),
                    'multiple'    => true,
                    'clearable'   => true,
                    'placeholder' => esc_html__('Nie ausblenden', 'immoadmin'),
                    'info'        => esc_html__('Breakpoints aus den Bricks-Einstellungen. Jeder gilt nur für seinen Bereich: „Tablet“ blendet am Handy nicht aus, dafür auch die Handy-Breakpoints wählen. Gilt zusammen mit „Auf Mobil sichtbar“.', 'immoadmin'),
                ],
                'compact' => [
                    'label'   => esc_html__('Kompakt (auto-Breite)', 'immoadmin'),
                    'type'    => 'checkbox',
                    'info'    => esc_html__('Spalte nimmt nur so viel Breite wie der Inhalt (plus Innenabstand) — gut für Status-Punkte oder Icon-Spalten. Bei „Horizontal scrollen“ sind alle Spalten so breit wie ihr Inhalt, dort ändert Kompakt nichts.', 'immoadmin'),
                    'inline'  => true,
                    'small'   => true,
                ],
                // v2.15.4 — both optional, no default: an empty value adds
                // nothing to the markup or CSS. Validated in
                // sanitize_css_length() / sanitize_css_padding().
                'min_width' => [
                    'label'       => esc_html__('Mindestbreite', 'immoadmin'),
                    'type'        => 'text',
                    'placeholder' => 'z. B. 80px',
                    'inline'      => true,
                    'info'        => esc_html__('Zahl mit Einheit (px, em, rem, ch, vw), z. B. 80px. Die Spalte wird nie schmäler, auch nicht als „Kompakt“.', 'immoadmin'),
                ],
                'cell_padding' => [
                    'label'       => esc_html__('Innenabstand', 'immoadmin'),
                    'type'        => 'text',
                    'placeholder' => 'z. B. 8px 16px',
                    'inline'      => true,
                    'info'        => esc_html__('Nur für diese Spalte (Kopf + Zellen), überschreibt „Zell-Padding“. 1–4 Werte wie bei CSS padding, z. B. „8px 16px“.', 'immoadmin'),
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
            'tab'       => 'content',
            'group'     => 'behavior',
            'label'     => esc_html__('Sortierung', 'immoadmin'),
            'type'      => 'select',
            'options'   => self::sort_field_options(),
            'default'   => 'sort_key',
            'clearable' => false,
            'desc'      => esc_html__('Bestimmt die Reihenfolge der Zeilen beim Laden der Seite.', 'immoadmin'),
        ];

        $this->controls['default_sort_key_custom'] = [
            'tab'         => 'content',
            'group'       => 'behavior',
            'label'       => esc_html__('Eigenes Feld (Meta-Key)', 'immoadmin'),
            'type'        => 'text',
            'placeholder' => 'z. B. balcony_area',
            'info'        => esc_html__('Wohnungen ohne diesen Wert stehen am Ende der Liste.', 'immoadmin'),
            'required'    => ['default_sort_key', '=', '__custom'],
        ];

        $this->controls['default_sort_key_custom_numeric'] = [
            'tab'      => 'content',
            'group'    => 'behavior',
            'label'    => esc_html__('Eigenes Feld ist eine Zahl', 'immoadmin'),
            'type'     => 'checkbox',
            'default'  => true,
            'required' => ['default_sort_key', '=', '__custom'],
        ];

        $this->controls['default_sort_order'] = [
            'tab'       => 'content',
            'group'     => 'behavior',
            'label'     => esc_html__('Richtung', 'immoadmin'),
            'type'      => 'select',
            'options'   => [
                'ASC'  => esc_html__('Aufsteigend (A→Z, 1→9)', 'immoadmin'),
                'DESC' => esc_html__('Absteigend (Z→A, 9→1)', 'immoadmin'),
            ],
            'default'   => 'ASC',
            'inline'    => true,
            'clearable' => false,
        ];

        // Deliberately a select, not a checkbox: Bricks omits an untouched
        // checkbox from the saved settings entirely, so "never toggled" and
        // "explicitly unchecked" are indistinguishable. A non-clearable select
        // always round-trips the user's actual choice.
        $this->controls['default_sort_tiebreak'] = [
            'tab'       => 'content',
            'group'     => 'behavior',
            'label'     => esc_html__('Zweite Sortierung', 'immoadmin'),
            'type'      => 'select',
            'options'   => [
                'sort_key' => esc_html__('Stiege & Tür', 'immoadmin'),
                ''         => esc_html__('Keine', 'immoadmin'),
            ],
            'default'   => 'sort_key',
            'clearable' => false,
            'inline'    => true,
            'desc'      => esc_html__('Bei gleichem Wert (z. B. selbes Haus) entscheidet die Türnummer.', 'immoadmin'),
            'required'  => ['default_sort_key', '!=', 'sort_key'],
        ];

        $this->controls['inline_sort_enabled'] = [
            'tab'     => 'content',
            'group'   => 'behavior',
            'label'   => esc_html__('Spalten-Klick-Sortierung', 'immoadmin'),
            'type'    => 'checkbox',
            'default' => true,
        ];

        $this->controls['accordion_single_open'] = [
            'tab'     => 'content',
            'group'   => 'behavior',
            'label'   => esc_html__('Nur eine Zeile gleichzeitig öffnen', 'immoadmin'),
            'type'    => 'checkbox',
            'default' => true,
            'desc'    => esc_html__('Öffnet eine neue Zeile → schließt automatisch die vorherige.', 'immoadmin'),
        ];

        $this->controls['status_handling'] = [
            'tab'       => 'content',
            'group'     => 'behavior',
            'label'     => esc_html__('Verkaufte / vermietete Wohnungen', 'immoadmin'),
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

        $this->controls['scroll_hint_enabled'] = [
            'tab'      => 'content',
            'group'    => 'behavior',
            'label'    => esc_html__('Scroll-Hinweis anzeigen', 'immoadmin'),
            'type'     => 'checkbox',
            'default'  => true,
            'desc'     => esc_html__('Animierter Wisch-Hinweis, wenn die Tabelle horizontal scrollbar ist. Verschwindet beim ersten Scroll.', 'immoadmin'),
            'required' => ['horizontal_scroll', '!=', ''],
        ];

        $this->controls['scroll_hint_label'] = [
            'tab'         => 'content',
            'group'       => 'behavior',
            'label'       => esc_html__('Scroll-Hinweis Text (Touch)', 'immoadmin'),
            'type'        => 'text',
            'default'     => esc_html__('Wischen', 'immoadmin'),
            'placeholder' => esc_html__('Wischen', 'immoadmin'),
            'info'        => esc_html__('Wird auf Touch-Geräten angezeigt.', 'immoadmin'),
            'required'    => [
                ['horizontal_scroll', '!=', ''],
                ['scroll_hint_enabled', '!=', ''],
            ],
        ];

        $this->controls['scroll_hint_label_desktop'] = [
            'tab'         => 'content',
            'group'       => 'behavior',
            'label'       => esc_html__('Scroll-Hinweis Text (Desktop)', 'immoadmin'),
            'type'        => 'text',
            'default'     => esc_html__('Scrollen', 'immoadmin'),
            'placeholder' => esc_html__('Scrollen', 'immoadmin'),
            'info'        => esc_html__('Wird auf Desktop mit Maus angezeigt (hover & fine pointer).', 'immoadmin'),
            'required'    => [
                ['horizontal_scroll', '!=', ''],
                ['scroll_hint_enabled', '!=', ''],
            ],
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
            'label'   => esc_html__('URL-State aktiv', 'immoadmin'),
            'type'    => 'checkbox',
            'default' => true,
            'desc'    => esc_html__('Öffnet beim Aufruf das passende Akkordion und scrollt dorthin.', 'immoadmin'),
        ];

        $this->controls['url_state_key'] = [
            'tab'         => 'content',
            'group'       => 'behavior',
            'label'       => esc_html__('URL-Parameter Name (vor dem =)', 'immoadmin'),
            'type'        => 'text',
            'default'     => 'unit',
            'placeholder' => 'unit',
            'info'        => esc_html__('Statischer Schlüssel — z.B. "top" → URL wird ?top=… . Nur a-z, 0-9, _ erlaubt. KEINE Dynamic Data hier.', 'immoadmin'),
            'required'    => ['url_state_enabled', '!=', ''],
        ];

        $this->controls['url_state_value'] = [
            'tab'            => 'content',
            'group'          => 'behavior',
            'label'          => esc_html__('URL-Parameter Wert (nach dem =)', 'immoadmin'),
            'type'           => 'text',
            'default'        => '{post_id}',
            'placeholder'    => '{post_id}',
            'hasDynamicData' => true,
            'info'           => esc_html__('Pro Wohnung dynamisch. Beispiele: {cf_door_number} (nur Top-Nr) oder {cf_building_name}-Top-{cf_door_number} (eindeutig über mehrere Häuser) oder {post_id}.', 'immoadmin'),
            'required'       => ['url_state_enabled', '!=', ''],
        ];

        // ---------- Filter (v2.14.0) ----------
        // Every control here is opt-in: no defaults, and nothing is emitted
        // unless a filter acts on the table (Filter-Gruppe, or since v2.15.0
        // a filter on the page targeting it / all tables), so a page without
        // ImmoAdmin filters renders byte-identical to v2.13.0 (tested).
        // "Filter-Gruppe" control removed in v2.15.2 (filters pick tables via
        // "Ziel-Tabellen"); a stored immoadmin_filter_group is still honoured
        // by filter_group_from_settings() so legacy pages keep working.

        $this->controls['immoadmin_filter_empty'] = [
            'tab'         => 'content',
            'group'       => 'filter',
            'label'       => esc_html__('Keine Treffer', 'immoadmin'),
            'type'        => 'select',
            'options'     => [
                'hide'    => esc_html__('Tabelle ausblenden', 'immoadmin'),
                'message' => esc_html__('Meldung anzeigen', 'immoadmin'),
            ],
            'inline'      => true,
            'placeholder' => esc_html__('Tabelle ausblenden', 'immoadmin'),
        ];

        $this->controls['immoadmin_filter_empty_text'] = [
            'tab'            => 'content',
            'group'          => 'filter',
            'label'          => esc_html__('Meldung', 'immoadmin'),
            'type'           => 'text',
            'placeholder'    => esc_html__('Keine passenden Wohnungen', 'immoadmin'),
            'hasDynamicData' => false,
            'required'       => ['immoadmin_filter_empty', '=', 'message'],
        ];

        $this->controls['immoadmin_filter_hide_with'] = [
            'tab'         => 'content',
            'group'       => 'filter',
            'label'       => esc_html__('Beim Ausblenden mit ausblenden', 'immoadmin'),
            'type'        => 'select',
            'options'     => [
                'parent'    => esc_html__('Übergeordnetes Element', 'immoadmin'),
                'container' => esc_html__('Nächster Container', 'immoadmin'),
                'section'   => esc_html__('Nächste Section', 'immoadmin'),
                'custom'    => esc_html__('Eigener Selektor …', 'immoadmin'),
            ],
            'inline'      => true,
            'placeholder' => esc_html__('Nur die Tabelle', 'immoadmin'),
            'description' => esc_html__('Damit die Überschrift („Presto … 10 Wohnungen“) mit verschwindet: Überschrift und Tabelle in einen gemeinsamen Block/Container legen und diesen hier wählen.', 'immoadmin'),
        ];

        $this->controls['immoadmin_filter_hide_selector'] = [
            'tab'            => 'content',
            'group'          => 'filter',
            'label'          => esc_html__('Selektor (nächstes passendes Eltern-Element)', 'immoadmin'),
            'type'           => 'text',
            'placeholder'    => '.haus-wrapper',
            'hasDynamicData' => false,
            'required'       => ['immoadmin_filter_hide_with', '=', 'custom'],
        ];

        $this->controls['immoadmin_filter_extra_keys'] = [
            'tab'            => 'content',
            'group'          => 'filter',
            'label'          => esc_html__('Zusätzliche Felder (Meta-Keys)', 'immoadmin'),
            'type'           => 'text',
            'placeholder'    => 'z. B. balcony_area, object_type_label',
            'hasDynamicData' => false,
            'description'    => esc_html__('Nur selten nötig: für Filter mit „Eigenes Feld“, die die Tabelle nicht automatisch findet (z. B. in Popups). Mit Komma trennen.', 'immoadmin'),
        ];

        $this->controls['immoadmin_filter_info'] = [
            'tab'      => 'content',
            'group'    => 'filter',
            'type'     => 'info',
            'content'  => esc_html__('Anzahl anzeigen: einem Element im selben Wrapper (z. B. der Überschrift) das Attribut data-immoadmin-count geben, Wert z. B. „{count} Wohnungen“ (optional data-immoadmin-count-one=„1 Wohnung“). Es zeigt die Zahl der sichtbaren Wohnungen. Außerhalb des Wrappers: data-immoadmin-count-for=„<Tabellen-ID>“ (mehrere mit Leerzeichen trennen, Summe).', 'immoadmin'),
        ];

        // ---------- Status colors ----------
        $this->controls['sep_status'] = [
            'tab'   => 'style',
            'group' => 'table_style',
            'type'  => 'separator',
            'label' => esc_html__('Status (Farben & Punkt)', 'immoadmin'),
        ];

        $this->controls['color_available'] = [
            'tab'   => 'style',
            'group' => 'table_style',
            'label' => esc_html__('Verfügbar', 'immoadmin'),
            'type'  => 'color',
            'default' => ['hex' => '#10b981'],
            'css'   => [
                ['property' => '--iat-color-available'],
            ],
        ];

        $this->controls['color_reserved'] = [
            'tab'   => 'style',
            'group' => 'table_style',
            'label' => esc_html__('Reserviert', 'immoadmin'),
            'type'  => 'color',
            'default' => ['hex' => '#f59e0b'],
            'css'   => [
                ['property' => '--iat-color-reserved'],
            ],
        ];

        $this->controls['color_sold'] = [
            'tab'   => 'style',
            'group' => 'table_style',
            'label' => esc_html__('Verkauft', 'immoadmin'),
            'type'  => 'color',
            'default' => ['hex' => '#ef4444'],
            'css'   => [
                ['property' => '--iat-color-sold'],
            ],
        ];

        $this->controls['color_rented'] = [
            'tab'   => 'style',
            'group' => 'table_style',
            'label' => esc_html__('Vermietet', 'immoadmin'),
            'type'  => 'color',
            'default' => ['hex' => '#ef4444'],
            'css'   => [
                ['property' => '--iat-color-rented'],
            ],
        ];

        // Badge text colours (v2.15.3). No defaults: the CSS falls back to
        // #fff, so badges on existing pages keep their white text.
        // "Vermietet" falls back to "Verkauft", like the background does.
        $text_colors = [
            'available' => esc_html__('Verfügbar – Textfarbe', 'immoadmin'),
            'reserved'  => esc_html__('Reserviert – Textfarbe', 'immoadmin'),
            'sold'      => esc_html__('Verkauft – Textfarbe', 'immoadmin'),
            'rented'    => esc_html__('Vermietet – Textfarbe', 'immoadmin'),
        ];
        foreach ($text_colors as $status => $label) {
            $this->controls['text_color_' . $status] = [
                'tab'   => 'style',
                'group' => 'table_style',
                'label' => $label,
                'type'  => 'color',
                'css'   => [
                    ['property' => '--iat-text-' . $status],
                ],
            ];
        }

        $this->controls['dot_size'] = [
            'tab'         => 'style',
            'group'       => 'table_style',
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

        // ========== Style: Status-Badge (v2.15.3) ==========
        $this->controls['sep_badge'] = [
            'tab'   => 'style',
            'group' => 'table_style',
            'type'  => 'separator',
            'label' => esc_html__('Status-Badge', 'immoadmin'),
        ];

        // Colour excluded: the text colour is set per status above, and one
        // typography colour would silently override all four.
        $this->controls['badge_typography'] = [
            'tab'     => 'style',
            'group'   => 'table_style',
            'label'   => esc_html__('Typografie', 'immoadmin'),
            'type'    => 'typography',
            'exclude' => ['color'],
            'css'     => [
                ['property' => 'font', 'selector' => '.immoadmin-status-badge'],
            ],
        ];

        $this->controls['badge_padding'] = [
            'tab'   => 'style',
            'group' => 'table_style',
            'label' => esc_html__('Padding', 'immoadmin'),
            'type'  => 'spacing',
            'css'   => [
                ['property' => 'padding', 'selector' => '.immoadmin-status-badge'],
            ],
        ];

        $this->controls['badge_border_radius'] = [
            'tab'         => 'style',
            'group'       => 'table_style',
            'label'       => esc_html__('Border-Radius', 'immoadmin'),
            'type'        => 'number',
            'units'       => true,
            'placeholder' => '4px',
            'css'         => [
                ['property' => 'border-radius', 'selector' => '.immoadmin-status-badge'],
            ],
        ];

        // ========== Style: Leere Zellen (v2.15.3) ==========
        $this->controls['sep_empty'] = [
            'tab'   => 'style',
            'group' => 'table_style',
            'type'  => 'separator',
            'label' => esc_html__('Leere Zellen (Platzhalter „—“)', 'immoadmin'),
        ];

        // The default placeholder is grey at opacity 0.6. A picked colour
        // also resets the opacity (Bricks' fixed 'value' — only emitted when
        // the colour is set), so the dash shows exactly the chosen colour;
        // for a faded look pick a colour with transparency.
        $this->controls['empty_color'] = [
            'tab'   => 'style',
            'group' => 'table_style',
            'label' => esc_html__('Farbe', 'immoadmin'),
            'type'  => 'color',
            'css'   => [
                ['property' => 'color', 'selector' => '.immoadmin-cell-empty'],
                ['property' => 'opacity', 'selector' => '.immoadmin-cell-empty', 'value' => '1'],
            ],
        ];

        // Colour excluded so it can't bypass the opacity reset above.
        $this->controls['empty_typography'] = [
            'tab'     => 'style',
            'group'   => 'table_style',
            'label'   => esc_html__('Typografie', 'immoadmin'),
            'type'    => 'typography',
            'exclude' => ['color'],
            'css'     => [
                ['property' => 'font', 'selector' => '.immoadmin-cell-empty'],
            ],
        ];

        // ========== Style: Kopfzeile ==========
        $this->controls['sep_header'] = [
            'tab'   => 'style',
            'group' => 'table_style',
            'type'  => 'separator',
            'label' => esc_html__('Kopfzeile', 'immoadmin'),
        ];

        $this->controls['header_typography'] = [
            'tab'   => 'style',
            'group' => 'table_style',
            'label' => esc_html__('Typografie', 'immoadmin'),
            'type'  => 'typography',
            'css'   => [
                ['property' => 'font', 'selector' => '.immoadmin-table-cell-header'],
            ],
        ];

        $this->controls['header_background'] = [
            'tab'   => 'style',
            'group' => 'table_style',
            'label' => esc_html__('Hintergrund', 'immoadmin'),
            'type'  => 'color',
            'css'   => [
                ['property' => 'background-color', 'selector' => '.immoadmin-table-cell-header'],
            ],
        ];

        $this->controls['header_padding'] = [
            'tab'   => 'style',
            'group' => 'table_style',
            'label' => esc_html__('Padding', 'immoadmin'),
            'type'  => 'spacing',
            'css'   => [
                ['property' => 'padding', 'selector' => '.immoadmin-table-cell-header'],
            ],
        ];

        $this->controls['header_border'] = [
            'tab'   => 'style',
            'group' => 'table_style',
            'label' => esc_html__('Border', 'immoadmin'),
            'type'  => 'border',
            'css'   => [
                ['property' => 'border', 'selector' => '.immoadmin-table-cell-header'],
            ],
        ];

        // ========== Style: Zeilen ==========
        $this->controls['sep_rows'] = [
            'tab'   => 'style',
            'group' => 'table_style',
            'type'  => 'separator',
            'label' => esc_html__('Zeilen', 'immoadmin'),
        ];

        $this->controls['row_typography'] = [
            'tab'   => 'style',
            'group' => 'table_style',
            'label' => esc_html__('Typografie', 'immoadmin'),
            'type'  => 'typography',
            'css'   => [
                ['property' => 'font', 'selector' => '.immoadmin-table-cell'],
            ],
        ];

        $this->controls['row_background'] = [
            'tab'   => 'style',
            'group' => 'table_style',
            'label' => esc_html__('Hintergrund', 'immoadmin'),
            'type'  => 'color',
            'css'   => [
                ['property' => 'background-color', 'selector' => '.immoadmin-table-row .immoadmin-table-cell'],
            ],
        ];

        // Zebra (v2.15.4): units-table.js marks every other VISIBLE row with
        // .is-even (1st, 3rd, … unit = the phase nth-of-type gave saved
        // pages), after init, sort, filter and load-more. Covers bare rows
        // (reserved/sold/rented, "Tabelle pur") too — nth-of-type counted
        // the header and hidden rows and only styled .accordion-item.
        $this->controls['row_alternate_background'] = [
            'tab'   => 'style',
            'group' => 'table_style',
            'label' => esc_html__('Zebra-Streifen (gerade Zeilen)', 'immoadmin'),
            'type'  => 'color',
            'css'   => [
                ['property' => 'background-color', 'selector' => '.immoadmin-table-row.is-even > .immoadmin-table-cell'],
            ],
        ];

        // Every row kind (accordion row, bare row, table mode); hovering the
        // open panel still lights its row, as before. Both parts weigh the
        // same as the old selector (3 classes after .brxe-{id}), so "Aktive
        // Zeile (offen)" below still wins over hover and hover over zebra.
        $this->controls['row_hover_background'] = [
            'tab'   => 'style',
            'group' => 'table_style',
            'label' => esc_html__('Hover-Hintergrund', 'immoadmin'),
            'type'  => 'color',
            'css'   => [
                ['property' => 'background-color', 'selector' => '.immoadmin-table-row:hover > .immoadmin-table-cell, .accordion-item:hover > :where(.immoadmin-table-row) > .immoadmin-table-cell'],
            ],
        ];

        $this->controls['row_open_background'] = [
            'tab'   => 'style',
            'group' => 'table_style',
            'label' => esc_html__('Aktive Zeile (offen)', 'immoadmin'),
            'type'  => 'color',
            'css'   => [
                ['property' => 'background-color', 'selector' => '.accordion-title-wrapper.brx-open .immoadmin-table-cell'],
            ],
        ];

        // Cells AND header cells (v2.15.4), so header and columns line up.
        // The header part is wrapped in :where() → one class lower than the
        // Kopfzeile "Padding" control (.immoadmin-table-cell-header), which
        // therefore still wins wherever it is set.
        $this->controls['row_padding'] = [
            'tab'   => 'style',
            'group' => 'table_style',
            'label' => esc_html__('Zell-Padding', 'immoadmin'),
            'type'  => 'spacing',
            'css'   => [
                ['property' => 'padding', 'selector' => '.immoadmin-table-cell, :where(.immoadmin-table-cell-header)'],
            ],
        ];

        $this->controls['row_min_height'] = [
            'tab'   => 'style',
            'group' => 'table_style',
            'label' => esc_html__('Mindesthöhe', 'immoadmin'),
            'type'  => 'number',
            'units' => true,
            'placeholder' => '0',
            'css'   => [
                ['property' => 'min-height', 'selector' => '.immoadmin-table-cell'],
            ],
        ];

        // Bricks' border control ignores 'property' and writes all four sides
        // unless 'directions' limits it (Assets::generate_inline_css 2.3.9:
        // foreach over the VALUES of 'directions'; builder preview: Object.keys
        // of it) — hence key = value, which both read as "bottom".
        $this->controls['row_border'] = [
            'tab'        => 'style',
            'group'      => 'table_style',
            'label'      => esc_html__('Trennlinie zwischen Zeilen', 'immoadmin'),
            'type'       => 'border',
            'directions' => ['bottom' => 'bottom'],
            'css'        => [
                ['property' => 'border-bottom', 'selector' => '.immoadmin-table-cell'],
            ],
        ];

        // Akkordion-Body styling intentionally removed — users style the
        // child Block (or any element they drop into the slot) with native
        // Bricks settings. Removed: sep_accordion, accordion_body_background,
        // accordion_body_padding, accordion_body_border, accordion_max_height,
        // accordion_transition. CSS still respects --iat-accordion-max-height
        // and --iat-accordion-transition vars if set elsewhere; defaults
        // (4000px / 0.25s) cover virtually all cases.

        // ========== Style: Tabelle allgemein ==========
        $this->controls['sep_table'] = [
            'tab'   => 'style',
            'group' => 'table_style',
            'type'  => 'separator',
            'label' => esc_html__('Tabelle gesamt', 'immoadmin'),
        ];

        // v2.15.4: no grid column-gap any more (it tore row lines, zebra and
        // hover into pieces). Same key, so saved values keep working: the
        // value goes to --iat-col-gap and the CSS adds half of it as space on
        // both sides of every cell, on top of "Zell-Padding".
        $this->controls['table_column_gap'] = [
            'tab'   => 'style',
            'group' => 'table_style',
            'label' => esc_html__('Spalten-Abstand', 'immoadmin'),
            'type'  => 'number',
            'units' => true,
            'placeholder' => '0',
            'info'  => esc_html__('Zusätzlicher Abstand links und rechts in jeder Zelle (je die Hälfte). Linien und Zeilen-Hintergründe laufen durch.', 'immoadmin'),
            'css'   => [
                ['property' => '--iat-col-gap', 'selector' => ''],
            ],
        ];

        $this->controls['table_border'] = [
            'tab'   => 'style',
            'group' => 'table_style',
            'label' => esc_html__('Tabellen-Border', 'immoadmin'),
            'type'  => 'border',
            'css'   => [
                ['property' => 'border', 'selector' => '.immoadmin-table'],
            ],
        ];

        $this->controls['table_border_radius'] = [
            'tab'   => 'style',
            'group' => 'table_style',
            'label' => esc_html__('Border-Radius', 'immoadmin'),
            'type'  => 'number',
            'units' => true,
            'placeholder' => '0',
            'css'   => [
                ['property' => 'border-radius', 'selector' => '.immoadmin-table'],
                // clip, not hidden (v2.15.4): rounds the corners without
                // making the grid a scroll container — with "hidden" the grid
                // became the scroller in horizontal-scroll mode and the sticky
                // detail panel broke. (Safari < 16 ignores clip: square-ish
                // cell corners there, nothing worse.)
                ['property' => 'overflow',      'selector' => '.immoadmin-table', 'value' => 'clip'],
            ],
        ];

        $this->controls['table_shadow'] = [
            'tab'   => 'style',
            'group' => 'table_style',
            'label' => esc_html__('Schatten', 'immoadmin'),
            'type'  => 'box-shadow',
            'css'   => [
                ['property' => 'box-shadow', 'selector' => '.immoadmin-table'],
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
            'Pool'             => '{cf_pool_area_formatted}',
            'Dachterrasse'     => '{cf_roof_terrace_area_formatted}',
            'Freifläche gesamt'=> '{cf_outdoor_area_total_formatted}',
            'Ausrichtung'      => '{cf_orientation}',
            'Kellerabteil'     => '{cf_basement_label}',
            'Kaufpreis'        => '{cf_purchase_price_formatted}',
            'Mietentgelt pro Monat' => '{cf_gross_rent_without_heating_formatted}',
            'Heizkosten'       => '{cf_heating_costs_formatted}',
            'Heizkosten brutto'=> '{cf_heating_costs_gross_formatted}',
            'Mindesteinkommen' => '{cf_minimum_income_formatted}',
            'Befristung'       => '{cf_rental_term_years}',
            'Erstbezug ab'     => '{cf_first_occupancy_date_formatted}',
            'klimaaktiv'       => '{cf_klimaaktiv_rating}',
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
     * Default nestable item — the accordion detail layout (see default_detail()).
     */
    public function get_nestable_item() {
        return self::default_detail();
    }

    /**
     * Children spawned once when the user drops the element on the canvas.
     *
     * Bricks inserts nestableChildren ONLY in its add-element routine
     * (builder main.min.js: `getElementConfig(name).nestableChildren` is read
     * right after a fresh element is created, unless `nestableChildrenSkip`).
     * Already placed widgets store their children as real elements in the
     * page data and are never re-seeded, so changing this preset cannot
     * touch an existing page.
     */
    public function get_nestable_children() {
        return [self::default_detail()];
    }

    // -----------------------------------------------------------------
    // PRESETS (new widgets only)
    // -----------------------------------------------------------------

    /**
     * Column preset for a freshly dropped widget.
     *
     * Why this can't leak into existing widgets (verified against Bricks
     * 2.3.9 source):
     *  - Control defaults are copied into an element's settings when it is
     *    ADDED (builder: `for (a in controls) if (controls[a].hasOwnProperty
     *    ("default")) settings[a] = controls[a].default` on drag, and the
     *    same only for EMPTY settings on click-add). From then on the page
     *    stores the actual `columns` array.
     *  - On the frontend Bricks\Element::__construct() takes settings as
     *    stored — there is no merge with control defaults — and render()
     *    reads $settings['columns'] only.
     * The one place the preset shows up for an old widget is the repeater's
     * "+ add column" button, which clones default[0] (the TOP column) as a
     * new item — a user action, never a silent change.
     *
     * Values use the backend's *_formatted meta ("79,9 m²", "439.800"),
     * sorting uses the numeric base key. Empty cells fall back to "—" via
     * the existing per-column fallback.
     */
    public static function default_columns() {
        $col = function ($header, $value, $sort_key, $mobile, $align, array $extra = []) {
            return array_merge([
                'header'         => $header,
                'value'          => $value,
                'type'           => 'text',
                'sortable'       => true,
                'sort_meta_key'  => $sort_key,
                'fallback'       => '—',
                'mobile_visible' => $mobile,
                'align'          => $align,
            ], $extra);
        };
        $numeric = ['sort_raw_meta' => true];

        return [
            $col(esc_html__('TOP', 'immoadmin'), '{cf_door_number}', 'door_number', true, 'left'),
            // floor: render_cell() maps floor_label/floor to the numeric
            // `floor` meta, so GG < UG < EG < 1. OG < DG.
            $col(esc_html__('Stockwerk', 'immoadmin'), '{cf_floor_label}', 'floor', false, 'left'),
            $col(esc_html__('Zimmer', 'immoadmin'), '{cf_room_count}', 'room_count', true, 'center', $numeric),
            $col(esc_html__('Wohnfläche', 'immoadmin'), '{cf_living_area_formatted}', 'living_area', true, 'right', $numeric),
            $col(esc_html__('Garten', 'immoadmin'), '{cf_garden_area_formatted}', 'garden_area', false, 'right', $numeric),
            $col(esc_html__('Balkon', 'immoadmin'), '{cf_balcony_area_formatted}', 'balcony_area', false, 'right', $numeric),
            $col(esc_html__('Terrasse', 'immoadmin'), '{cf_terrace_area_formatted}', 'terrace_area', false, 'right', $numeric),
            $col(esc_html__('Loggia', 'immoadmin'), '{cf_loggia_area_formatted}', 'loggia_area', false, 'right', $numeric),
            // Redacted for reserved/sold/rented by render_cell() (price key).
            $col(esc_html__('Kaufpreis', 'immoadmin'), '{cf_purchase_price_formatted}', 'purchase_price', true, 'right', $numeric),
            $col(esc_html__('Status', 'immoadmin'), '{cf_status_label}', 'status', false, 'center', [
                'type'                   => 'status_badge',
                'status_color_from_meta' => true,
                'compact'                => true,
            ]),
            // Accordion arrow: static icon, no header, no sort.
            [
                'header'           => '',
                'value'            => '',
                'type'             => 'icon',
                'icon'             => ['library' => 'themify', 'icon' => 'ti-angle-down'],
                'sortable'         => false,
                'fallback'         => '',
                'mobile_visible'   => true,
                'compact'          => true,
                'align'            => 'right',
                'accordion_toggle' => true,
            ],
        ];
    }

    /**
     * Accordion detail preset — native Bricks elements only, so the designer
     * can restyle or delete each piece:
     *
     *   Detail (block, row, wraps)
     *   ├─ Infos (block)
     *   │  ├─ heading      "Top {cf_door_number}"
     *   │  ├─ text-basic   Wohnfläche / Terrasse / Garten / Pool / Preis —
     *   │  │               one element per line, each hidden by a native
     *   │  │               "Dynamic data is not empty" condition
     *   │  └─ Buttons (block, row)
     *   │     ├─ button  "zum Plan"        → {cf_floor_plan_1}, new tab
     *   │     └─ button  "Jetzt anfragen"  → #anfrage
     *   └─ Grundrisse (block)
     *      ├─ slider-nested "Grundriss-Slider"   only if {cf_floor_plan_2} set
     *      │  └─ block (Query loop: ImmoAdmin Grundrisse)
     *      │     └─ image {immoadmin_media_url}
     *      └─ image {cf_floor_plan_1}            only if plan 1 set AND plan 2
     *                                            empty (v2.15.4: never both)
     *
     * Wrapping uses flex-basis instead of breakpoint keys, so it works with
     * custom / mobile-first breakpoints too. Every element carries explicit
     * settings: Bricks only applies an element's own control defaults to a
     * nested child whose settings are empty, so nothing here depends on them.
     *
     * Units with no floor plan: slider + single image + "zum Plan" are
     * dropped by their conditions (conditions are always "true" inside the
     * builder, so the designer still sees everything there).
     *
     * Condition format = Bricks 2.3.9 Conditions::check(): a list of sets
     * (OR between sets), each a list of conditions (AND inside a set);
     * 'dynamic_data' compares with 'empty' / 'empty_not' (since 1.10).
     */
    public static function default_detail() {
        $cond = function ($id, $tag, $compare) {
            return [
                'id'           => $id,
                'key'          => 'dynamic_data',
                'dynamic_data' => $tag,
                'compare'      => $compare,
            ];
        };
        $not_empty = function ($id, $tag) use ($cond) {
            return [[$cond($id, $tag, 'empty_not')]];
        };

        $line = function ($label, $tag, $id, $prefix = '') use ($not_empty) {
            return [
                'name'     => 'text-basic',
                'label'    => $label,
                'settings' => [
                    'text'        => $label . ': ' . $prefix . $tag,
                    '_conditions' => $not_empty($id, $tag),
                ],
            ];
        };

        // Several plans → slider; exactly one → single image (one set, AND).
        $slider_condition = $not_empty('iaplan', '{cf_floor_plan_2}');
        $single_condition = [[
            $cond('iaplm1', '{cf_floor_plan_1}', 'empty_not'),
            $cond('iaplm2', '{cf_floor_plan_2}', 'empty'),
        ]];

        return [
            'name'     => 'block',
            'label'    => esc_html__('Detail', 'immoadmin'),
            'settings' => [
                '_direction'  => 'row',
                '_flexWrap'   => 'wrap',
                '_alignItems' => 'flex-start',
                '_columnGap'  => '40px',
                '_rowGap'     => '24px',
                '_padding'    => ['top' => '24px', 'right' => '24px', 'bottom' => '24px', 'left' => '24px'],
            ],
            'children' => [
                [
                    'name'     => 'block',
                    'label'    => esc_html__('Infos', 'immoadmin'),
                    'settings' => [
                        '_flexBasis' => '280px',
                        '_flexGrow'  => '1',
                        '_rowGap'    => '8px',
                    ],
                    'children' => [
                        [
                            'name'     => 'heading',
                            'label'    => esc_html__('Titel', 'immoadmin'),
                            'settings' => [
                                'text' => 'Top {cf_door_number}',
                                'tag'  => 'h3',
                            ],
                        ],
                        $line(esc_html__('Wohnfläche', 'immoadmin'), '{cf_living_area_formatted}', 'ialiv1'),
                        $line(esc_html__('Terrasse', 'immoadmin'), '{cf_terrace_area_formatted}', 'iater1'),
                        $line(esc_html__('Garten', 'immoadmin'), '{cf_garden_area_formatted}', 'iagar1'),
                        $line(esc_html__('Pool', 'immoadmin'), '{cf_pool_area_formatted}', 'iapoo1'),
                        $line(esc_html__('Preis', 'immoadmin'), '{cf_purchase_price_formatted}', 'iapri1', '€ '),
                        [
                            'name'     => 'block',
                            'label'    => esc_html__('Buttons', 'immoadmin'),
                            'settings' => [
                                '_direction'  => 'row',
                                '_flexWrap'   => 'wrap',
                                '_alignItems' => 'center',
                                '_columnGap'  => '12px',
                                '_rowGap'     => '12px',
                                '_margin'     => ['top' => '16px'],
                            ],
                            'children' => [
                                [
                                    'name'     => 'button',
                                    'label'    => esc_html__('zum Plan', 'immoadmin'),
                                    'settings' => [
                                        'text'        => esc_html__('zum Plan', 'immoadmin'),
                                        'style'       => 'primary',
                                        'outline'     => true,
                                        'link'        => [
                                            'type'   => 'external',
                                            'url'    => '{cf_floor_plan_1}',
                                            'newTab' => true,
                                        ],
                                        '_conditions' => $not_empty('iaplb1', '{cf_floor_plan_1}'),
                                    ],
                                ],
                                [
                                    'name'     => 'button',
                                    'label'    => esc_html__('Jetzt anfragen', 'immoadmin'),
                                    'settings' => [
                                        'text'  => esc_html__('Jetzt anfragen', 'immoadmin'),
                                        'style' => 'primary',
                                        'link'  => [
                                            'type' => 'external',
                                            'url'  => '#anfrage',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'name'     => 'block',
                    'label'    => esc_html__('Grundrisse', 'immoadmin'),
                    'settings' => [
                        '_flexBasis' => '320px',
                        '_flexGrow'  => '1',
                        '_rowGap'    => '16px',
                    ],
                    'children' => [
                        [
                            'name'     => 'slider-nested',
                            'label'    => esc_html__('Grundriss-Slider', 'immoadmin'),
                            'settings' => [
                                // 'slide' (not Bricks' default 'loop'): a
                                // single plan must not be cloned into a fake
                                // carousel.
                                'type'        => 'slide',
                                'autoHeight'  => true,
                                'arrows'      => true,
                                'pagination'  => true,
                                'gap'         => '0',
                                '_conditions' => $slider_condition,
                            ],
                            'children' => [
                                [
                                    'name'     => 'block',
                                    'label'    => esc_html__('Grundriss (Loop)', 'immoadmin'),
                                    'settings' => [
                                        'hasLoop' => true,
                                        'query'   => [
                                            'objectType' => 'immoadmin_floor_plans',
                                        ],
                                    ],
                                    'children' => [
                                        [
                                            'name'     => 'image',
                                            'label'    => esc_html__('Grundriss', 'immoadmin'),
                                            'settings' => [
                                                'image'      => [
                                                    'useDynamicData' => '{immoadmin_media_url}',
                                                    'size'           => 'full',
                                                ],
                                                'caption'    => 'none',
                                                '_width'     => '100%',
                                                '_objectFit' => 'contain',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'name'     => 'image',
                            'label'    => esc_html__('Grundriss 1 (ohne Slider)', 'immoadmin'),
                            'settings' => [
                                'image'       => [
                                    'useDynamicData' => '{cf_floor_plan_1}',
                                    'size'           => 'full',
                                ],
                                'caption'     => 'none',
                                '_width'      => '100%',
                                '_objectFit'  => 'contain',
                                '_conditions' => $single_condition,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    // -----------------------------------------------------------------
    // BUILDING FILTER
    // -----------------------------------------------------------------

    /**
     * Distinct building names of all published units, natural-sorted.
     *
     * Only needed to fill the builder dropdown, so the frontend (Bricks
     * loads element controls on every request) never queries. Cached per
     * sync run: the key contains the last-sync timestamp, so a new sync
     * shows new buildings immediately and old entries just expire.
     */
    public static function building_name_options() {
        $in_builder = (function_exists('bricks_is_builder') && bricks_is_builder())
            || (function_exists('bricks_is_builder_call') && bricks_is_builder_call());
        if (!$in_builder) {
            return [];
        }

        $cache_key = 'immoadmin_bldg_' . md5((string) get_option('immoadmin_last_sync', '') . '|' . IMMOADMIN_VERSION);
        $cached    = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        global $wpdb;
        $names = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm"
            . " INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id"
            . " WHERE pm.meta_key = %s AND pm.meta_value <> ''"
            . " AND p.post_type = %s AND p.post_status = 'publish'",
            'building_name',
            'immoadmin_wohnung'
        ));

        $options = self::building_options_from_names(is_array($names) ? $names : []);
        set_transient($cache_key, $options, 12 * HOUR_IN_SECONDS);

        return $options;
    }

    /**
     * Pure: raw names → [name => name], unique, natural order ("Haus 9"
     * before "Haus 11").
     */
    public static function building_options_from_names(array $names) {
        $clean = self::sanitize_building_selection($names);
        usort($clean, 'strnatcasecmp');

        $options = [];
        foreach ($clean as $name) {
            $options[$name] = $name;
        }
        return $options;
    }

    /**
     * Normalise the stored control value: Bricks saves a multi-select as an
     * array, a single select as a string. Same sanitizer as the sync uses
     * for building_name, so the comparison matches what is in the DB.
     */
    public static function sanitize_building_selection($raw) {
        if (is_string($raw) || is_numeric($raw)) {
            $raw = [$raw];
        }
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $value = sanitize_text_field((string) $value);
            if ($value !== '' && !in_array($value, $out, true)) {
                $out[] = $value;
            }
        }

        return array_slice($out, 0, 200);
    }

    /**
     * Add "building_name IN (…)" to a meta_query without ever replacing or
     * widening what is already there.
     *
     *  - Empty selection → the meta_query comes back untouched (identical).
     *  - Our clause always sits in its OWN key-less group. Bricks' filter
     *    merge (Query::merge_tax_or_meta_query_vars) folds clauses with the
     *    same key+compare into each other index by index — a Bricks Filter
     *    on building_name would otherwise overwrite our values and widen
     *    the selection. A group without 'key' is skipped by that merge.
     *  - The user's relation is respected: with AND (WP's default) our
     *    group is appended; with OR the user's whole meta_query is nested
     *    and AND-ed with ours, so it still narrows instead of OR-ing in.
     */
    public static function build_building_meta_query($existing, array $buildings) {
        if (empty($buildings)) {
            return $existing;
        }

        $ours = [
            'relation' => 'AND',
            [
                'key'     => 'building_name',
                'value'   => array_values($buildings),
                'compare' => 'IN',
            ],
        ];

        if (empty($existing) || !is_array($existing)) {
            return ['relation' => 'AND', $ours];
        }

        // A bare single clause (['key' => …]) is not a list — wrap it.
        if (isset($existing['key']) || isset($existing['value'])) {
            return ['relation' => 'AND', $existing, $ours];
        }

        $relation = isset($existing['relation']) ? strtoupper((string) $existing['relation']) : 'AND';
        if ($relation !== 'AND') {
            return ['relation' => 'AND', $existing, $ours];
        }

        $existing[] = $ours;
        return $existing;
    }

    /**
     * bricks/posts/query_vars — apply the "Gebäude" control.
     *
     * Same pattern as apply_sort_query_vars(): everything comes from the
     * $settings Bricks hands in (so it also works when a Filter element
     * builds this query before the table renders), and we bail unless the
     * settings carry our own, uniquely named control. Priority 10 runs
     * before Bricks merges active Filter values (priority 999), so filters
     * narrow WITHIN the selected buildings.
     */
    public static function apply_building_query_vars($query_vars, $settings, $element_id = '') {
        if (!is_array($settings) || empty($settings['immoadmin_buildings']) || !is_array($query_vars)) {
            return $query_vars;
        }

        $buildings = self::sanitize_building_selection($settings['immoadmin_buildings']);
        if (empty($buildings)) {
            return $query_vars;
        }

        $query_vars['meta_query'] = self::build_building_meta_query(
            $query_vars['meta_query'] ?? [],
            $buildings
        );

        return $query_vars;
    }

    // -----------------------------------------------------------------
    // COLUMN VISIBILITY PER BREAKPOINT (v2.15.3)
    // -----------------------------------------------------------------

    /**
     * Bricks' default breakpoints (Breakpoints::get_default_breakpoints() in
     * 2.3.9) — used when Bricks' class isn't there or returns nothing.
     */
    public static function default_breakpoints() {
        return [
            ['key' => 'desktop',          'label' => esc_html__('Desktop', 'immoadmin'),         'width' => 1279, 'base' => true],
            ['key' => 'tablet_portrait',  'label' => esc_html__('Tablet hochkant', 'immoadmin'), 'width' => 991],
            ['key' => 'mobile_landscape', 'label' => esc_html__('Handy quer', 'immoadmin'),      'width' => 767],
            ['key' => 'mobile_portrait',  'label' => esc_html__('Handy hochkant', 'immoadmin'),  'width' => 478],
        ];
    }

    /**
     * The site's breakpoints (default + custom), normalised.
     *
     * Prefers Breakpoints::$breakpoints, which Bricks fills on `init`;
     * calls get_breakpoints() only after `init` — it runs esc_html__() and
     * would load translations too early otherwise.
     */
    public static function site_breakpoints() {
        $raw = [];
        if (class_exists('\\Bricks\\Breakpoints')) {
            if (property_exists('\\Bricks\\Breakpoints', 'breakpoints') && !empty(\Bricks\Breakpoints::$breakpoints)) {
                $raw = \Bricks\Breakpoints::$breakpoints;
            } elseif (method_exists('\\Bricks\\Breakpoints', 'get_breakpoints') && function_exists('did_action') && did_action('init')) {
                $raw = \Bricks\Breakpoints::get_breakpoints();
            }
        }

        $list = self::normalize_breakpoints($raw);
        return !empty($list) ? $list : self::normalize_breakpoints(self::default_breakpoints());
    }

    /**
     * Pure: raw Bricks breakpoint list → [['key','label','width','base'], …],
     * widest first, exactly one base.
     *
     * Mirrors Bricks 2.3.9: paused breakpoints produce no CSS
     * (Assets::generate_inline_css_for_breakpoints skips them), so they are
     * dropped here — their range belongs to the neighbour. Base = the last
     * entry flagged 'base', else 'desktop' (Breakpoints::get_breakpoints());
     * else the widest one. Entries without key / width, or repeating a key
     * or width, are dropped.
     */
    public static function normalize_breakpoints($raw) {
        if (!is_array($raw)) {
            return [];
        }

        $list   = [];
        $keys   = [];
        $widths = [];
        $base   = null;
        foreach ($raw as $bp) {
            if (!is_array($bp)) {
                continue;
            }
            $key   = isset($bp['key']) && is_scalar($bp['key']) ? trim((string) $bp['key']) : '';
            $width = isset($bp['width']) && is_numeric($bp['width']) ? (int) $bp['width'] : 0;
            $flag  = isset($bp['base']) && $bp['base'] !== false;
            if ($key === '' || $width <= 0 || isset($keys[$key]) || isset($widths[$width])) {
                continue;
            }
            if (isset($bp['paused']) && !$flag) {
                continue;
            }
            $label = isset($bp['label']) && is_scalar($bp['label']) && trim((string) $bp['label']) !== ''
                ? (string) $bp['label']
                : $key;

            $keys[$key]     = true;
            $widths[$width] = true;
            $list[] = ['key' => $key, 'label' => $label, 'width' => $width, 'base' => false];
            if ($flag) {
                $base = $key;
            }
        }
        if (empty($list)) {
            return [];
        }

        usort($list, function ($a, $b) {
            return $b['width'] <=> $a['width'];
        });

        if ($base === null) {
            $base = isset($keys['desktop']) ? 'desktop' : $list[0]['key'];
        }
        foreach ($list as $i => $bp) {
            $list[$i]['base'] = ($bp['key'] === $base);
        }

        return $list;
    }

    /**
     * Pure: the width range in which each breakpoint is the active one.
     *
     * Same rule Bricks uses to write breakpoint CSS (Assets 2.3.9: wider
     * than base → min-width, narrower → max-width, base → no media query):
     *
     *  - narrower than base, e.g. desktop-first 991 / 767 / 478:
     *      991 → (767, 991], 767 → (478, 767], 478 → (−∞, 478]
     *  - wider than base, e.g. mobile-first base 0 + 768 / 1024:
     *      768 → [768, 1024), 1024 → [1024, ∞)
     *  - base → between the two: desktop-first (991, ∞), mobile-first
     *      (−∞, 768). Hybrid set-ups (base in the middle) work the same way.
     *
     * So each breakpoint covers ONLY its own band — "tablet" does not
     * include the phones below it.
     *
     * A bound is a "cut" ['w' => px, 'op' => 'max'|'min']: 'max' = W belongs
     * to the band below (max-width: W), 'min' = W belongs to the band above
     * (min-width: W). null = open end.
     *
     * @param array $breakpoints normalize_breakpoints() output.
     * @return array key => ['lo' => cut|null, 'hi' => cut|null]
     */
    public static function breakpoint_ranges(array $breakpoints) {
        $base = null;
        foreach ($breakpoints as $bp) {
            if (!empty($bp['base'])) {
                $base = $bp;
                break;
            }
        }
        if ($base === null) {
            return [];
        }

        $below = []; // max-width breakpoints, widest first
        $above = []; // min-width breakpoints, narrowest first
        foreach ($breakpoints as $bp) {
            if ($bp['key'] === $base['key']) {
                continue;
            }
            if ($bp['width'] > $base['width']) {
                $above[] = $bp;
            } else {
                $below[] = $bp;
            }
        }
        usort($below, function ($a, $b) { return $b['width'] <=> $a['width']; });
        usort($above, function ($a, $b) { return $a['width'] <=> $b['width']; });

        $ranges = [];
        foreach ($below as $i => $bp) {
            $ranges[$bp['key']] = [
                'lo' => isset($below[$i + 1]) ? ['w' => $below[$i + 1]['width'], 'op' => 'max'] : null,
                'hi' => ['w' => $bp['width'], 'op' => 'max'],
            ];
        }
        foreach ($above as $i => $bp) {
            $ranges[$bp['key']] = [
                'lo' => ['w' => $bp['width'], 'op' => 'min'],
                'hi' => isset($above[$i + 1]) ? ['w' => $above[$i + 1]['width'], 'op' => 'min'] : null,
            ];
        }
        $ranges[$base['key']] = [
            'lo' => !empty($below) ? ['w' => $below[0]['width'], 'op' => 'max'] : null,
            'hi' => !empty($above) ? ['w' => $above[0]['width'], 'op' => 'min'] : null,
        ];

        // Widest band first, like the builder's breakpoint bar.
        $ordered = [];
        foreach ($breakpoints as $bp) {
            if (isset($ranges[$bp['key']])) {
                $ordered[$bp['key']] = $ranges[$bp['key']];
            }
        }
        return $ordered;
    }

    /**
     * Sort position of a cut on the width axis. max(W) sits right after W,
     * min(W) right before it — so max(767) < min(768) stay distinct.
     */
    private static function cut_order($cut, $open_end) {
        if ($cut === null) {
            return $open_end === 'lo' ? PHP_INT_MIN : PHP_INT_MAX;
        }
        return $cut['w'] * 2 + ($cut['op'] === 'max' ? 1 : 0);
    }

    /**
     * Media condition for a band. Classic min-/max-width (Level 4 range
     * syntax needs Safari/iOS 16.4+); exclusive bounds use the Bootstrap
     * .02px offset so neighbouring bands meet without a usable gap at
     * browser zoom: (767, 991] → (min-width: 767.02px) and (max-width: 991px),
     * [768, 1024) → (min-width: 768px) and (max-width: 1023.98px).
     * Widths are ints, so the decimals are built as strings — no locale
     * (decimal comma) can sneak in. '' = no bound at all.
     */
    private static function range_media_condition($lo, $hi) {
        $parts = [];
        if ($lo !== null) {
            $w = (int) $lo['w'];
            $parts[] = '(min-width: ' . ($lo['op'] === 'max' ? $w . '.02' : (string) $w) . 'px)';
        }
        if ($hi !== null) {
            $w = (int) $hi['w'];
            $parts[] = '(max-width: ' . ($hi['op'] === 'max' ? (string) $w : ($w - 1) . '.98') . 'px)';
        }
        return implode(' and ', $parts);
    }

    /**
     * Pure: dropdown options "Label (768–991 px)" so the designer sees which
     * band a breakpoint stands for.
     */
    public static function breakpoint_options(array $breakpoints) {
        $options = [];
        foreach (self::breakpoint_ranges($breakpoints) as $key => $range) {
            $label = $key;
            foreach ($breakpoints as $bp) {
                if ($bp['key'] === $key) {
                    $label = $bp['label'];
                    break;
                }
            }

            $from = $range['lo'] === null ? null : (int) $range['lo']['w'] + ($range['lo']['op'] === 'max' ? 1 : 0);
            $to   = $range['hi'] === null ? null : (int) $range['hi']['w'] - ($range['hi']['op'] === 'min' ? 1 : 0);
            if ($from !== null && $to !== null) {
                /* translators: 1: lower px, 2: upper px */
                $band = sprintf(esc_html__('%1$d–%2$d px', 'immoadmin'), $from, $to);
            } elseif ($from !== null) {
                /* translators: %d: px */
                $band = sprintf(esc_html__('ab %d px', 'immoadmin'), $from);
            } elseif ($to !== null) {
                /* translators: %d: px */
                $band = sprintf(esc_html__('bis %d px', 'immoadmin'), $to);
            } else {
                $band = esc_html__('alle Breiten', 'immoadmin');
            }

            $options[$key] = esc_html($label) . ' (' . $band . ')';
        }
        return $options;
    }

    /**
     * Pure: stored "Ausblenden auf" value → known breakpoint keys, in
     * breakpoint order, unique. Unknown keys (deleted or renamed custom
     * breakpoints, junk) are dropped, so they can never reach the CSS.
     */
    public static function sanitize_hide_on($raw, array $breakpoints) {
        if (is_string($raw)) {
            $raw = [$raw];
        }
        if (!is_array($raw)) {
            return [];
        }

        $wanted = [];
        foreach ($raw as $value) {
            if (is_scalar($value)) {
                $wanted[(string) $value] = true;
            }
        }

        $keys = [];
        foreach ($breakpoints as $bp) {
            if (isset($wanted[$bp['key']])) {
                $keys[] = $bp['key'];
            }
        }
        return $keys;
    }

    /**
     * Pure: scoped CSS for "Ausblenden auf".
     *
     * Cuts the width axis at every breakpoint bound (plus the legacy 767px
     * bound when old mobile_visible columns are still hidden by the static
     * CSS), and for every band where at least one column is hidden emits:
     *   - display:none for that column's header + cells (by data-col-index —
     *     the filter empty-row and accordion panel carry none, so they stay)
     *   - grid-template-columns from the tracks still visible in that band,
     *     so rows don't flow into the gaps (same reason as the mobile grid).
     * Neighbouring bands with the same result are merged. Bands without a
     * hidden column get nothing: the static CSS already fits them.
     *
     * Legacy mobile_visible=false and "Ausblenden auf" combine: such a
     * column keeps data-mobile-visible="0" (static rule hides it ≤767px)
     * and is additionally hidden in its selected bands; the grid of every
     * band leaves out both.
     *
     * Unlayered (beats the @layer bricks defaults, incl. the legacy mobile
     * grid). Selectors walk the real DOM path with child combinators from
     * this element's .brxe-{id} root, so a units table nested inside an
     * accordion panel is never matched:
     *   root > [.immoadmin-table-scroll >] .immoadmin-table
     *     > .immoadmin-table-header > .immoadmin-table-cell-header
     *     > [.accordion-item >] .immoadmin-table-row > .immoadmin-table-cell
     *
     * @param string $scope         e.g. ".brxe-abc123" (already sanitised)
     * @param array  $tracks        col index => grid track, in column order
     * @param array  $hide_on       col index => sanitize_hide_on() keys
     * @param array  $legacy_hidden col indexes with mobile_visible off
     * @param array  $breakpoints   normalize_breakpoints() output
     */
    public static function column_visibility_css($scope, array $tracks, array $hide_on, array $legacy_hidden, array $breakpoints) {
        $hide_on = array_filter($hide_on);
        if (empty($hide_on) || empty($tracks)) {
            return '';
        }

        $ranges = self::breakpoint_ranges($breakpoints);
        $tables = [
            $scope . ' > .immoadmin-table',
            $scope . ' > .immoadmin-table-scroll > .immoadmin-table',
        ];

        // Every bound on the axis, sorted + unique.
        $legacy_cut = ['w' => 767, 'op' => 'max'];
        $cuts = [];
        foreach ($ranges as $range) {
            foreach (['lo', 'hi'] as $end) {
                if ($range[$end] !== null) {
                    $cuts[self::cut_order($range[$end], $end)] = $range[$end];
                }
            }
        }
        if (!empty($legacy_hidden)) {
            $cuts[self::cut_order($legacy_cut, 'hi')] = $legacy_cut;
        }
        ksort($cuts);
        $bounds = array_merge([null], array_values($cuts), [null]);

        $bands = [];
        for ($i = 0; $i < count($bounds) - 1; $i++) {
            $lo = $bounds[$i];
            $hi = $bounds[$i + 1];
            $lo_ord = self::cut_order($lo, 'lo');
            $hi_ord = self::cut_order($hi, 'hi');

            $hidden = [];
            foreach ($hide_on as $idx => $keys) {
                foreach ($keys as $key) {
                    if (!isset($ranges[$key])) {
                        continue;
                    }
                    if (self::cut_order($ranges[$key]['lo'], 'lo') <= $lo_ord
                        && $hi_ord <= self::cut_order($ranges[$key]['hi'], 'hi')) {
                        $hidden[] = $idx;
                        break;
                    }
                }
            }
            $legacy = !empty($legacy_hidden) && $hi !== null
                && $hi_ord <= self::cut_order($legacy_cut, 'hi');

            $last = count($bands) - 1;
            if ($last >= 0 && $bands[$last]['hidden'] === $hidden && $bands[$last]['legacy'] === $legacy) {
                $bands[$last]['hi'] = $hi;
                continue;
            }
            $bands[] = ['lo' => $lo, 'hi' => $hi, 'hidden' => $hidden, 'legacy' => $legacy];
        }

        $css = [];
        foreach ($bands as $band) {
            if (empty($band['hidden'])) {
                continue;
            }

            $visible = [];
            foreach ($tracks as $idx => $track) {
                if (in_array($idx, $band['hidden'], true)) {
                    continue;
                }
                if ($band['legacy'] && in_array($idx, $legacy_hidden, true)) {
                    continue;
                }
                $visible[] = $track;
            }
            // All hidden: keep the full template, like the mobile grid does.
            $template = !empty($visible) ? implode(' ', $visible) : implode(' ', $tracks);

            $selectors = [];
            foreach ($band['hidden'] as $idx) {
                $col = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $idx);
                foreach ($tables as $table) {
                    $selectors[] = $table . ' > .immoadmin-table-header > .immoadmin-table-cell-header[data-col-index="' . $col . '"]';
                    $selectors[] = $table . ' > .immoadmin-table-row > .immoadmin-table-cell[data-col-index="' . $col . '"]';
                    $selectors[] = $table . ' > .accordion-item > .immoadmin-table-row > .immoadmin-table-cell[data-col-index="' . $col . '"]';
                }
            }

            $rules = implode(',', $tables) . '{grid-template-columns:' . $template . '}'
                . implode(',', $selectors) . '{display:none !important}';

            $condition = self::range_media_condition($band['lo'], $band['hi']);
            $css[] = $condition !== '' ? '@media ' . $condition . '{' . $rules . '}' : $rules;
        }

        return implode("\n", $css);
    }

    // -----------------------------------------------------------------
    // COLUMN WIDTH / SPACING (v2.15.4)
    // -----------------------------------------------------------------

    /**
     * Pure: grid track of one column.
     *
     *  - "Kompakt"                  → max-content (as wide as its content)
     *  - horizontal scroll          → auto: as wide as the content while the
     *    table overflows, and — unlike max-content — stretched by the grid
     *    when the table is NARROWER than its wrapper, so row lines and the
     *    detail panel span the full width (was: max-content, gap at right)
     *  - otherwise                  → minmax(min-content, 1fr): share the
     *    width, never narrower than the longest word
     *
     * "Mindestbreite" is NOT folded into the track: a minmax(<min>, 1fr)
     * track may shrink below its content (fr tracks only respect their
     * fixed minimum), which is exactly the mid-word wrapping this release
     * fixes. It is a min-width on the column's cells instead (see
     * column_style_css()) — a grid item's min-width raises its min- and
     * max-content contribution, so every track kind honours it.
     */
    public static function column_track(array $col, $horizontal_scroll) {
        if (!empty($col['compact'])) {
            return 'max-content';
        }
        return $horizontal_scroll ? 'auto' : 'minmax(min-content, 1fr)';
    }

    /**
     * Pure: one CSS length as typed in the builder → "80px" / "1.5rem" /
     * "0", or '' when it is anything else (dynamic data, calc(), var(),
     * %, junk). Only number + unit can reach the inline <style>.
     */
    public static function sanitize_css_length($raw) {
        if (!is_scalar($raw)) {
            return '';
        }
        $v = strtolower(trim((string) $raw));
        if ($v === '0') {
            return '0';
        }
        if (preg_match('/^(\d{1,4}(?:\.\d{1,3})?|\.\d{1,3})(px|em|rem|ch|vw)$/', $v, $m)) {
            return $m[1] . $m[2];
        }
        return '';
    }

    /**
     * Pure: padding shorthand with 1–4 lengths → normalised string, or ''
     * if any part is invalid (all or nothing, so a typo never half-applies).
     */
    public static function sanitize_css_padding($raw) {
        if (!is_scalar($raw)) {
            return '';
        }
        $parts = preg_split('/\s+/', trim((string) $raw), -1, PREG_SPLIT_NO_EMPTY);
        if (empty($parts) || count($parts) > 4) {
            return '';
        }
        $out = [];
        foreach ($parts as $part) {
            $len = self::sanitize_css_length($part);
            if ($len === '') {
                return '';
            }
            $out[] = $len;
        }
        return implode(' ', $out);
    }

    /**
     * Pure: a column's optional "Mindestbreite" / "Innenabstand" →
     * ['min-width' => …, 'padding' => …] (only the valid, set ones).
     */
    public static function column_style_from_settings(array $col) {
        $style = [];
        $min = self::sanitize_css_length($col['min_width'] ?? '');
        if ($min !== '') {
            $style['min-width'] = $min;
        }
        $pad = self::sanitize_css_padding($col['cell_padding'] ?? '');
        if ($pad !== '') {
            $style['padding'] = $pad;
        }
        return $style;
    }

    /**
     * Pure: scoped CSS for per-column min-width / padding.
     *
     * Same scoping as column_visibility_css(): child combinators from this
     * element's .brxe-{id} root along the real DOM path (plain + scroll
     * wrapper; header, bare row, accordion row), so a units table nested in
     * an accordion panel is never matched. Unlayered and more specific than
     * Bricks' "Zell-Padding" (.brxe-{id} .immoadmin-table-cell), so the
     * column value wins over the table-wide one.
     *
     * @param string $scope  e.g. ".brxe-abc123" (already sanitised)
     * @param array  $styles col index => column_style_from_settings()
     */
    public static function column_style_css($scope, array $styles) {
        $tables = [
            $scope . ' > .immoadmin-table',
            $scope . ' > .immoadmin-table-scroll > .immoadmin-table',
        ];
        $css = [];
        foreach ($styles as $idx => $style) {
            $decl = [];
            foreach (['min-width', 'padding'] as $prop) {
                if (isset($style[$prop])) {
                    // Re-validated here too: this function writes into <style>.
                    $value = $prop === 'padding'
                        ? self::sanitize_css_padding($style[$prop])
                        : self::sanitize_css_length($style[$prop]);
                    if ($value !== '') {
                        $decl[] = $prop . ':' . $value;
                    }
                }
            }
            if (empty($decl)) {
                continue;
            }
            $col = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $idx);
            $selectors = [];
            foreach ($tables as $table) {
                $selectors[] = $table . ' > .immoadmin-table-header > .immoadmin-table-cell-header[data-col-index="' . $col . '"]';
                $selectors[] = $table . ' > .immoadmin-table-row > .immoadmin-table-cell[data-col-index="' . $col . '"]';
                $selectors[] = $table . ' > .accordion-item > .immoadmin-table-row > .immoadmin-table-cell[data-col-index="' . $col . '"]';
            }
            $css[] = implode(',', $selectors) . '{' . implode(';', $decl) . '}';
        }
        return implode("\n", $css);
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
        // Default-on so existing sites get the hint without re-saving; only suppress
        // it when the user explicitly unchecked the new control (settings key present
        // and falsy). isset() check distinguishes "never touched" from "off".
        $scroll_hint_enabled = $horizontal_scroll
            && (!isset($settings['scroll_hint_enabled']) || !empty($settings['scroll_hint_enabled']));
        $scroll_hint_label_touch = !empty($settings['scroll_hint_label'])
            ? (string) $settings['scroll_hint_label']
            : __('Wischen', 'immoadmin');
        $scroll_hint_label_desktop = !empty($settings['scroll_hint_label_desktop'])
            ? (string) $settings['scroll_hint_label_desktop']
            : __('Scrollen', 'immoadmin');
        $url_state         = !empty($settings['url_state_enabled']);
        $url_state_key     = !empty($settings['url_state_key']) ? preg_replace('/[^a-z0-9_]/i', '', $settings['url_state_key']) : 'unit';
        $url_state_value_dd = !empty($settings['url_state_value']) ? $settings['url_state_value'] : '{post_id}';
        $inline_sort       = !empty($settings['inline_sort_enabled']);

        // Build _root attributes.
        // Bricks emits its loop-aware CSS rules using the class
        // ".brxe-{$this->id}" but doesn't add that class to our root by
        // default (it sets id="brxe-..." instead). Without the matching
        // class, descendant Block flex-direction etc. never apply. Add it.
        $this->set_attribute('_root', 'class', 'brxe-' . $this->id);
        $this->set_attribute('_root', 'data-element', 'immoadmin-units-table');
        $this->set_attribute('_root', 'data-mode', $mode);
        $this->set_attribute('_root', 'data-status-handling', $status_handling);
        if ($url_state) {
            $this->set_attribute('_root', 'data-url-state', '1');
            $this->set_attribute('_root', 'data-url-key', $url_state_key);
        }
        if ($inline_sort) {
            $this->set_attribute('_root', 'data-inline-sort', '1');
        }
        // Default true — single-open is the typical flatfinder UX.
        if (!isset($settings['accordion_single_open']) || !empty($settings['accordion_single_open'])) {
            $this->set_attribute('_root', 'data-single-open', '1');
        }
        // Build per-column grid track sizes — see column_track().
        $default_track = self::column_track([], $horizontal_scroll);

        // "Ausblenden auf" (v2.15.3). Adds scoped media queries ON TOP of
        // the legacy mobile_visible logic (union): markup, data-mobile-visible
        // and the mobile grid vars stay exactly as without the setting;
        // column_visibility_css() fits the grid per band from both sources.
        // Bricks breakpoints are only read when a column actually uses it.
        $hide_on     = [];
        $breakpoints = null;
        foreach ($columns as $idx => $col) {
            if (empty($col['hide_on_breakpoints'])) {
                continue;
            }
            if ($breakpoints === null) {
                $breakpoints = self::site_breakpoints();
            }
            $keys = self::sanitize_hide_on($col['hide_on_breakpoints'], $breakpoints);
            if (!empty($keys)) {
                $hide_on[$idx] = $keys;
            }
        }

        $tracks = [];
        $tracks_by_idx = [];
        $legacy_hidden = [];
        $mobile_tracks = [];
        $mobile_count = 0;
        $col_styles = [];
        foreach ($columns as $idx => $col) {
            $track = self::column_track(is_array($col) ? $col : [], $horizontal_scroll);
            $tracks[] = $track;
            $style = self::column_style_from_settings(is_array($col) ? $col : []);
            if (!empty($style)) {
                $col_styles[$idx] = $style;
            }
            $tracks_by_idx[$idx] = $track;
            if (empty($col['mobile_visible'])) {
                $legacy_hidden[] = $idx;
            }
            // Mobile-visible columns get their own track list so the @media
            // override can re-template the grid to the correct visible count.
            // Without this, hidden cells (display:none) leave the grid still
            // expecting N tracks per row → cells from the next row flow into
            // the gap and visually collide with the previous row.
            if (!empty($col['mobile_visible'])) {
                $mobile_tracks[] = $track;
                $mobile_count++;
            }
        }
        $grid_template = !empty($tracks) ? implode(' ', $tracks) : $default_track;
        $mobile_grid_template = !empty($mobile_tracks) ? implode(' ', $mobile_tracks) : $grid_template;
        $this->set_attribute('_root', 'style',
            '--iat-grid-cols: ' . $grid_template
            . '; --iat-cols: ' . max(1, count($columns))
            . '; --iat-mobile-grid-cols: ' . $mobile_grid_template
            . '; --iat-mobile-cols: ' . max(1, $mobile_count) . ';'
        );
        // Surface the query element id to JS so it can refetch via Bricks filter system.
        $this->set_attribute('_root', 'data-bricks-query-id', $this->id);

        // Filter widgets (v2.14.0 / v2.15.0). Nothing is emitted unless a
        // filter acts on this table — via its Filter-Gruppe, or (v2.15.0) a
        // filter on the page that picked it under "Ziel-Tabellen" or acts on
        // all tables. A page without ImmoAdmin filters keeps its exact markup.
        $filter_group  = self::filter_group_from_settings($settings);
        $filter_keys   = [];
        $participation = ['active' => false, 'keys' => []];
        if (class_exists('ImmoAdmin_Filter_Data') && method_exists('ImmoAdmin_Filter_Data', 'table_participation')) {
            $participation = ImmoAdmin_Filter_Data::table_participation(ImmoAdmin_Filter_Data::page_index(), $this->id, $filter_group);
        }
        $filter_active = $filter_group !== '' || $participation['active'];
        if ($filter_active) {
            if ($filter_group !== '') {
                $this->set_attribute('_root', 'data-immoadmin-filter-group', $filter_group);
            } else {
                $this->set_attribute('_root', 'data-immoadmin-filterable', '1');
            }
            if (($settings['immoadmin_filter_empty'] ?? '') === 'message') {
                $this->set_attribute('_root', 'data-immoadmin-filter-empty', 'message');
                $empty_text = isset($settings['immoadmin_filter_empty_text']) && is_string($settings['immoadmin_filter_empty_text'])
                    ? trim($settings['immoadmin_filter_empty_text']) : '';
                $this->set_attribute('_root', 'data-immoadmin-filter-empty-text',
                    $empty_text !== '' ? $empty_text : __('Keine passenden Wohnungen', 'immoadmin'));
            }
            $hide_with = $settings['immoadmin_filter_hide_with'] ?? '';
            if (in_array($hide_with, ['parent', 'container', 'section', 'custom'], true)) {
                $selector = trim((string) ($settings['immoadmin_filter_hide_selector'] ?? ''));
                if ($hide_with !== 'custom' || $selector !== '') {
                    $this->set_attribute('_root', 'data-immoadmin-filter-hide', $hide_with);
                }
                if ($hide_with === 'custom' && $selector !== '') {
                    $this->set_attribute('_root', 'data-immoadmin-filter-hide-selector', $selector);
                }
            }
            $filter_keys = array_values(array_unique(array_merge(
                ImmoAdmin_Filter_Data::parse_key_list($settings['immoadmin_filter_extra_keys'] ?? ''),
                $filter_group !== '' ? ImmoAdmin_Filter_Data::registered_keys($filter_group) : [],
                $participation['keys']
            )));
        }

        // In the Bricks builder: flag so CSS can force the first row's
        // accordion panel open (designer can't style what they can't see).
        // The canvas renders elements over REST/AJAX, where
        // bricks_is_builder_iframe() is false. bricks_is_builder_call() alone
        // is NOT enough: it is also true for a plain request with a forged
        // "bricks=run" Referer, which a page cache could then store. So the
        // same capability-checked test as the redaction bypass. The CSS also
        // keys off the canvas body ([data-builder-window="iframe"]).
        if (self::is_builder_context()) {
            $this->set_attribute('_root', 'data-builder', '1');
        }

        // On AJAX filter / pagination requests, Bricks expects ONLY the loop
        // items in the response (not the wrapper, not the header) — it then
        // injects them into the existing wrapper DOM. If we re-render the
        // wrapper here, Bricks JS nests a second full element inside the old
        // one (visible: 2 headers, doubled rows). Mirror Posts.php pattern.
        $is_ajax_loop = class_exists('\\Bricks\\Api') && (
            \Bricks\Api::is_current_endpoint('load_query_page')
            || \Bricks\Api::is_current_endpoint('query_result')
        );

        if (!$is_ajax_loop) {
            echo "<div {$this->render_attributes('_root')}>";

            // Scoped breakpoint CSS lives INSIDE the root: the builder canvas
            // keeps only the first element of render_element's HTML
            // (iframe.min.js setHTML: DOMParser → body.firstElementChild), so
            // a <style> before the root would be lost or replace it there.
            // Builder breakpoints resize the canvas iframe, so the media
            // queries preview as on the frontend. Every value in it is built
            // from integers / fixed track keywords — nothing user-typed.
            $scoped_css = [];
            if (!empty($hide_on)) {
                $scoped_css[] = self::column_visibility_css(
                    '.brxe-' . sanitize_html_class($this->id),
                    $tracks_by_idx,
                    $hide_on,
                    $legacy_hidden,
                    $breakpoints
                );
            }
            // Per-column "Mindestbreite" / "Innenabstand" (v2.15.4). Values
            // are validated lengths only (sanitize_css_length()).
            if (!empty($col_styles)) {
                $scoped_css[] = self::column_style_css('.brxe-' . sanitize_html_class($this->id), $col_styles);
            }
            $scoped_css = implode("\n", array_filter($scoped_css, 'strlen'));
            if ($scoped_css !== '') {
                echo '<style>' . $scoped_css . '</style>';
            }

            if ($horizontal_scroll) {
                $scroll_attrs = ' class="immoadmin-table-scroll"';
                if ($scroll_hint_enabled) {
                    $scroll_attrs .= ' data-scroll-hint="1"';
                }
                echo '<div' . $scroll_attrs . '>';
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
        } // end !$is_ajax_loop wrapper-open block

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
            'columns'           => $columns,
            'mode'              => $mode,
            'status_handling'   => $status_handling,
            'element_id'        => $this->id,
            'element_instance'  => $this,
            'url_state_value_dd'=> $url_state ? $url_state_value_dd : '',
            'is_builder'        => self::is_builder_context(),
            'filter_group'      => $filter_group,
            'filter_active'     => $filter_active,
            'filter_keys'       => $filter_keys,
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

        if (!$is_ajax_loop) {
            echo '</div>'; // .immoadmin-table

            if ($horizontal_scroll) {
                echo '</div>'; // .immoadmin-table-scroll
            }

            if ($horizontal_scroll && $scroll_hint_enabled) {
                // Scroll hint overlay — sibling of .immoadmin-table-scroll so it
                // does NOT scroll along with the content. Positioned absolutely
                // over the bottom of the scroll wrapper by the CSS. aria-hidden
                // because it's purely decorative — JS removes the [data-active]
                // flag when scrollWidth <= clientWidth, on first scroll, or after
                // the auto-hide timeout. Inline SVG = no extra HTTP request.
                //
                // Both touch and desktop labels are rendered server-side as data
                // attributes; JS picks one at runtime based on (hover & fine
                // pointer) media query and writes it into the visible span.
                // Default span text is the touch label so SSR-only / no-JS
                // visitors still see something sensible on mobile.
                echo '<div class="immoadmin-table-scroll-hint" aria-hidden="true"'
                    . ' data-label-touch="' . esc_attr($scroll_hint_label_touch) . '"'
                    . ' data-label-desktop="' . esc_attr($scroll_hint_label_desktop) . '">';
                echo '<svg class="immoadmin-table-scroll-hint__icon" viewBox="0 0 48 32" xmlns="http://www.w3.org/2000/svg" focusable="false">';
                // Left chevron
                echo '<path class="immoadmin-table-scroll-hint__chevron immoadmin-table-scroll-hint__chevron--left" d="M9 16l5-5v3h6v4h-6v3z" fill="currentColor"/>';
                // Right chevron
                echo '<path class="immoadmin-table-scroll-hint__chevron immoadmin-table-scroll-hint__chevron--right" d="M39 16l-5-5v3h-6v4h6v3z" fill="currentColor"/>';
                // Hand / finger swiping in the middle
                echo '<path class="immoadmin-table-scroll-hint__hand" d="M24 6c-1.66 0-3 1.34-3 3v9c-.66-.66-1.5-1-2.5-1-1.93 0-3.5 1.57-3.5 3.5 0 .55.45 1 1 1 1.18 0 2.21.6 2.82 1.5l1.05 1.58c.92 1.38 2.47 2.2 4.13 2.2H27c2.76 0 5-2.24 5-5v-4c0-1.1-.9-2-2-2-.39 0-.74.11-1.04.3-.18-.87-.96-1.55-1.96-1.55-.39 0-.74.11-1.04.3C25.78 13.18 25 12.5 25 11.5V9c0-1.66-1.34-3-3-3z" fill="currentColor" opacity="0.85"/>';
                echo '</svg>';
                if ($scroll_hint_label_touch !== '' || $scroll_hint_label_desktop !== '') {
                    // Default text = touch label (best fallback for no-JS mobile).
                    echo '<span class="immoadmin-table-scroll-hint__label">' . esc_html($scroll_hint_label_touch) . '</span>';
                }
                echo '</div>';
            }

            echo '</div>'; // _root
        }

        // Required for Filter / Pagination integration. Must run BEFORE destroy().
        // (render_query_loop_trail itself bails out on REST calls, so safe.)
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

        $columns           = $ctx['columns'] ?? [];
        $mode              = $ctx['mode'] ?? 'accordion';
        $status_handling   = $ctx['status_handling'] ?? 'show';
        $element_id        = $ctx['element_id'] ?? '';
        $element_instance  = $ctx['element_instance'] ?? null;
        $url_state_value_dd = $ctx['url_state_value_dd'] ?? '';
        $is_builder        = !empty($ctx['is_builder']);

        $post_id = get_the_ID();
        $status  = (string) get_post_meta($post_id, 'status', true);
        // Treat "sold" (Kauf) and "rented" (Miete) identically for hide/dim:
        // both mean "not available anymore" from the visitor's perspective.
        $is_unavailable = ($status === 'sold' || $status === 'rented');

        // Deliberately WIDER than $is_unavailable and a separate concept: a
        // reserved unit is still on the table (and may not even be dimmed),
        // but its price and its Exposé are nobody's business anymore, and its
        // accordion must stay shut. Not tied to $status_handling — redaction
        // is a data rule, not a display option the editor can switch off.
        // See render_cell() / is_sensitive_field_key() for what gets dropped.
        // Allowlist (same rule as REST / filters / public JSON): anything but
        // "available" — incl. an empty/unknown status — is restricted.
        if (class_exists('ImmoAdmin_Visibility') && method_exists('ImmoAdmin_Visibility', 'is_public_status')) {
            $status_is_public = ImmoAdmin_Visibility::is_public_status($status);
        } elseif (class_exists('ImmoAdmin_Filter_Data')) {
            $status_is_public = ImmoAdmin_Filter_Data::is_public_status($status);
        } else {
            $status_is_public = strtolower(trim($status)) === 'available';
        }
        $is_restricted = !$is_builder && !$status_is_public;

        // Filter values (v2.14.0) — only for tables a filter acts on.
        // Prices are dropped for every non-public status (allowlist, fails
        // closed — wider than $is_restricted on purpose): the attribute must
        // never be a side door to a redacted price.
        $filter_attr = '';
        if (!empty($ctx['filter_active']) && class_exists('ImmoAdmin_Filter_Data')) {
            $hide_sensitive = !$is_builder && !ImmoAdmin_Filter_Data::is_public_status($status);
            $filter_values  = ImmoAdmin_Filter_Data::values_for_post($post_id, $ctx['filter_keys'] ?? [], $hide_sensitive);
            $filter_attr    = ' ' . ImmoAdmin_Filter_Data::ROW_ATTR . '="' . esc_attr(wp_json_encode($filter_values)) . '"';
        }

        // Resolve URL-state value once per row (e.g. "{cf_door_number}" -> "15").
        $url_value = '';
        if ($url_state_value_dd !== '' && $element_instance) {
            $url_value = trim((string) $element_instance->render_dynamic_data($url_state_value_dd));
        }

        if ($is_unavailable && $status_handling === 'hide') {
            return '';
        }

        $row_classes = ['immoadmin-table-row'];
        // Loop-id class: Bricks' load-more / infinite scroll finds the query
        // trail as the LAST `.brxe-{queryId}` in the results container
        // (frontend.js 2.3.9 bricksQueryLoadPage / load-more handler) and
        // inserts the next page after it. Must stay on the rows.
        if ($element_id) {
            $row_classes[] = 'brxe-' . sanitize_html_class($element_id);
        }
        if ($status) {
            $row_classes[] = 'is-' . sanitize_html_class($status);
        }
        if ($is_unavailable && $status_handling === 'dim') {
            $row_classes[] = 'is-dimmed';
        }

        $title_id   = 'immoadmin-row-' . $element_id . '-' . (int) $post_id;
        $content_id = 'immoadmin-panel-' . $element_id . '-' . (int) $post_id;

        // Restricted rows fall back to the plain-row branch: no panel, no
        // rowgroup wrapper, and none of the toggle affordances below
        // (tabindex / aria-expanded / .accordion-title-wrapper). Nothing to
        // open, nothing to focus — enforced here rather than in CSS/JS.
        $is_accordion = ($mode === 'accordion') && !$is_restricted;

        $output = '';

        if ($is_accordion) {
            // Wrap the row in an accordion-item so the row + content are siblings
            // styled by .accordion-title-wrapper / .accordion-content-wrapper.
            $output .= '<div class="accordion-item ' . esc_attr(implode(' ', array_map('sanitize_html_class', ['immoadmin-table-rowgroup'])))
                . '" role="rowgroup"'
                . ' data-unit-id="' . esc_attr((string) $post_id) . '"'
                . ' data-url-value="' . esc_attr($url_value) . '"'
                . ' data-status="' . esc_attr($status) . '"'
                . $filter_attr
                . '>';

            // No tabindex / aria-expanded on the row any more (v2.15.4): it is
            // display:contents and can't take focus in Chromium. The toggle
            // is a real <button> inside one of its cells (see below).
            $row_classes[] = 'accordion-title-wrapper';
            $row_attr  = ' role="row"';
            $row_attr .= ' id="' . esc_attr($title_id) . '"';
        } else {
            $row_attr  = ' role="row"';
            $row_attr .= ' data-unit-id="' . esc_attr((string) $post_id) . '"';
            $row_attr .= ' data-url-value="' . esc_attr($url_value) . '"';
            $row_attr .= ' data-status="' . esc_attr($status) . '"';
            $row_attr .= $filter_attr;
        }

        $output .= '<div class="' . esc_attr(implode(' ', $row_classes)) . '"' . $row_attr . '>';

        $toggle_idx = $is_accordion ? self::toggle_column_index($columns) : null;
        $cells      = [];
        foreach ($columns as $idx => $col) {
            $cells[$idx] = self::render_cell($col, $idx, $is_restricted, $is_accordion);
        }
        if ($toggle_idx !== null && isset($cells[$toggle_idx])) {
            $cells[$toggle_idx] = self::inject_row_toggle(
                $cells[$toggle_idx],
                $content_id,
                self::toggle_label($cells)
            );
        }
        $output .= implode('', $cells);

        $output .= '</div>'; // .immoadmin-table-row

        if ($is_accordion) {
            // accordion-content-wrapper hosts the children slot. Bricks renders
            // child elements inline (each child has its own <div> etc.). We do
            // NOT wrap the call ourselves — the slot's own children carry the
            // styling class via _hidden._cssClasses (see get_nestable_item()).
            // For loop-iteration rendering, Frontend::render_children must be
            // called via the element wrapper.
            //
            // Inside a rowgroup only rows are valid (was role="region"): the
            // panel is a row, its content one full-width cell. The cell
            // wrapper is display:contents, so children lay out exactly as
            // before.
            $output .= '<div class="immoadmin-accordion-panel" role="row"'
                . ' id="' . esc_attr($content_id) . '"'
                . ' aria-labelledby="' . esc_attr($title_id) . '"'
                . '>';
            $output .= '<div class="immoadmin-accordion-panel__cell" role="cell">';
            $output .= self::render_accordion_children($element_instance);
            $output .= '</div>';
            $output .= '</div>';

            $output .= '</div>'; // .accordion-item
        }

        return $output;
    }

    /**
     * Column that hosts the keyboard toggle of an openable row. Prefers a
     * column that is never hidden (mobile_visible on, no "Ausblenden auf"):
     * the "Akkordion-Pfeil" icon column first, else the first such column;
     * only if every column can be hidden, the arrow / first column. Wherever
     * the host cell is display:none at the current width anyway,
     * units-table.js moves the button into the row's first visible cell.
     */
    public static function toggle_column_index(array $columns) {
        $always = function ($col) {
            return is_array($col) && !empty($col['mobile_visible']) && empty($col['hide_on_breakpoints']);
        };
        $is_arrow = function ($col) {
            return is_array($col) && ($col['type'] ?? '') === 'icon' && !empty($col['accordion_toggle']);
        };
        foreach ([[$is_arrow, $always], [$always], [$is_arrow], []] as $tests) {
            foreach ($columns as $idx => $col) {
                foreach ($tests as $test) {
                    if (!$test($col)) {
                        continue 2;
                    }
                }
                return $idx;
            }
        }
        return null;
    }

    /**
     * Accessible name of the toggle: "Details zu <text of the first cell
     * that has text>", e.g. "Details zu 1" / "Details zu Top 1". Built from
     * the already rendered (and already redacted) cells.
     */
    public static function toggle_label(array $cells) {
        foreach ($cells as $html) {
            $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags((string) $html), ENT_QUOTES, 'UTF-8')));
            if ($text !== '' && $text !== '—') {
                /* translators: %s: first cell of the row, e.g. "Top 1" */
                return sprintf(__('Details zu %s', 'immoadmin'), $text);
            }
        }
        return __('Details', 'immoadmin');
    }

    /**
     * Put the row's toggle button at the start of a rendered cell.
     *
     * A real <button> (visually hidden, focus ring drawn on the row by the
     * CSS): focusable, Enter/Space for free, and the right place for
     * aria-expanded / aria-controls. units-table.js keeps aria-expanded in
     * line with the row's .brx-open.
     */
    private static function inject_row_toggle($cell_html, $content_id, $label = '') {
        $label  = $label !== '' ? $label : __('Details', 'immoadmin');
        $button = '<button type="button" class="immoadmin-row-toggle"'
            . ' aria-expanded="false"'
            . ' aria-controls="' . esc_attr($content_id) . '"'
            . ' aria-label="' . esc_attr($label) . '">'
            . esc_html($label)
            . '</button>';
        $pos = strpos($cell_html, '>');
        if ($pos === false) {
            return $cell_html;
        }
        return substr($cell_html, 0, $pos + 1) . $button . substr($cell_html, $pos + 1);
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
     *
     * $is_restricted (every non-public status incl. empty, see render_row()) redacts the
     * sensitive columns: the DD is never resolved, so no price and no Exposé
     * URL exists at the point where markup gets built — nothing to fish out of
     * the DOM, and nothing left in data-sort-value / href / aria-label either.
     */
    private static function render_cell($col, $idx, $is_restricted = false, $has_panel = true) {
        $type   = !empty($col['type']) ? $col['type'] : 'text';
        $value  = isset($col['value']) ? (string) $col['value'] : '';
        $align  = !empty($col['align']) ? $col['align'] : 'left';
        $mobile = !empty($col['mobile_visible']) ? '1' : '0';

        $redact = $is_restricted && self::is_sensitive_column($col);

        // Accordion arrow (opt-in flag): a row without a panel (table mode,
        // or a reserved/sold/rented unit) has nothing to open, so an arrow
        // would promise something that doesn't exist. Keep the cell for the
        // grid, drop the icon.
        if ($type === 'icon' && !empty($col['accordion_toggle']) && !$has_panel) {
            return '<div class="immoadmin-table-cell" role="cell"'
                . ' data-align="' . esc_attr($align) . '"'
                . ' data-mobile-visible="' . esc_attr($mobile) . '"'
                . ' data-col-index="' . esc_attr((string) $idx) . '"'
                . ' data-sort-value=""></div>';
        }

        // Resolve dynamic data once. The global $post is set by Bricks during
        // the loop, so passing 0 lets the resolver find the right post.
        // Redacted columns skip the resolver entirely — the value must never
        // exist in this scope, not even to be thrown away later.
        $resolved = $redact ? '' : bricks_render_dynamic_data($value);

        $sort_value = is_string($resolved) ? $resolved : '';

        // Floor columns ({cf_floor_label}, "GG", "EG+OG", "1. OG", "DG") sort
        // by the numeric `floor` meta instead of the label text — otherwise
        // the JS falls back to string comparison and puts "1. OG" before
        // "EG". GG (negative) lands below UG/EG, a maisonette with its lower
        // floor. No numeric floor → the label stays the sort value.
        //
        // "Nach Zahlenwert sortieren" (opt-in, preset columns): sort by the
        // raw meta of the sort key instead of the display string — the JS
        // parseFloat()s the value, and "1.200.000" / "24,5 m²" (German
        // thousands dot / decimal comma) would parse as 1.2 / 24. Never for
        // redacted cells: the raw price must not reach data-sort-value.
        $sort_key = !empty($col['sort_meta_key'])
            ? (string) $col['sort_meta_key']
            : self::guess_meta_key_from_dd($value);
        if (!$redact && !empty($col['sort_raw_meta']) && $sort_key !== '') {
            $raw = get_post_meta((int) get_the_ID(), $sort_key, true);
            if (is_scalar($raw) && trim((string) $raw) !== '') {
                $sort_value = trim((string) $raw);
            }
        }
        if (!$redact && class_exists('ImmoAdmin_Unit_Fields')) {
            if (ImmoAdmin_Unit_Fields::is_floor_sort_key($sort_key)) {
                $sort_value = ImmoAdmin_Unit_Fields::floor_sort_value(
                    get_post_meta((int) get_the_ID(), 'floor', true),
                    $sort_value
                );
            }
        }

        $cell_attrs  = ' role="cell"';
        $cell_attrs .= ' data-align="' . esc_attr($align) . '"';
        $cell_attrs .= ' data-mobile-visible="' . esc_attr($mobile) . '"';
        $cell_attrs .= ' data-col-index="' . esc_attr((string) $idx) . '"';
        $cell_attrs .= ' data-sort-value="' . esc_attr($sort_value) . '"';

        // Treat whitespace-only as empty so the fallback kicks in for
        // " " or "\n" values returned by some DD providers.
        $resolved_trim = is_string($resolved) ? trim($resolved) : (string) $resolved;
        $is_empty = ($resolved_trim === '');

        // Per-column fallback for empty values (default "—"). Resolve DD too
        // so users can put e.g. "{post_title}" or any string. A fallback that
        // points at a sensitive field would smuggle back exactly what we just
        // redacted, so it stays unresolved in that case.
        $fallback_raw = isset($col['fallback']) ? (string) $col['fallback'] : '—';
        $fallback     = ($redact && self::dd_has_sensitive_field($fallback_raw))
            ? ''
            : bricks_render_dynamic_data($fallback_raw);

        $inner = '';

        // Icon columns are exempt from the empty-fallback: an icon column may
        // intentionally have no DD value (= just a static icon, no link).
        // Redacted ones are NOT exempt — a dangling Exposé icon that links
        // nowhere is worse than the fallback dash.
        if ($is_empty && ($type !== 'icon' || $redact)) {
            $inner = $fallback !== ''
                ? '<span class="immoadmin-cell-empty">' . esc_html($fallback) . '</span>'
                : '';
            return '<div class="immoadmin-table-cell"' . $cell_attrs . '>' . $inner . '</div>';
        }

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
                $status_class = sanitize_html_class(self::status_class_source($col, $resolved));
                $inner = '<span class="immoadmin-status-badge is-' . esc_attr($status_class) .
                    '">' . esc_html($resolved) . '</span>';
                break;

            case 'status_dot':
                $status_class = sanitize_html_class(self::status_class_source($col, $resolved));
                // role="img": aria-label on a plain span is ignored by most
                // screen readers (v2.15.4).
                $inner = '<span class="immoadmin-status-dot is-' . esc_attr($status_class) .
                    '" role="img" aria-label="' . esc_attr($resolved) . '" title="' . esc_attr($resolved) . '"></span>';
                break;

            case 'icon':
                $icon_setting = isset($col['icon']) && is_array($col['icon']) ? $col['icon'] : null;
                if (!$icon_setting || empty($icon_setting['icon'])) {
                    // No icon picked → fallback or nothing.
                    $inner = $fallback !== ''
                        ? '<span class="immoadmin-cell-empty">' . esc_html($fallback) . '</span>'
                        : '';
                    break;
                }
                $icon_html = \Bricks\Element::render_icon($icon_setting, ['immoadmin-table-icon']);
                $href      = $resolved_trim !== '' ? esc_url($resolved) : '';
                $aria      = isset($col['link_text']) && $col['link_text'] !== ''
                    ? bricks_render_dynamic_data((string) $col['link_text'])
                    : '';
                if ($href !== '') {
                    $target = !empty($col['link_target']) ? $col['link_target'] : '_self';
                    $rel    = ($target === '_blank') ? ' rel="noopener noreferrer"' : '';
                    $aria_attr = $aria !== '' ? ' aria-label="' . esc_attr($aria) . '"' : '';
                    $inner  = '<a class="immoadmin-table-link immoadmin-table-icon-link" href="' . $href .
                        '" target="' . esc_attr($target) . '"' . $rel . $aria_attr . '>' .
                        $icon_html . '</a>';
                } else {
                    // Static icon, no link.
                    $aria_attr = $aria !== '' ? ' aria-label="' . esc_attr($aria) . '" role="img"' : ' aria-hidden="true"';
                    $wrap_class = 'immoadmin-table-icon-wrap'
                        . (!empty($col['accordion_toggle']) ? ' immoadmin-accordion-toggle' : '');
                    $inner = '<span class="' . $wrap_class . '"' . $aria_attr . '>' . $icon_html . '</span>';
                }
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
     * Source of the status CSS class (is-available, is-reserved, …).
     *
     * Default: the resolved value, as always — right for {cf_status}. With
     * the opt-in "Farbe aus Status-Feld" the class comes from the unit's raw
     * `status` meta, so a column can SHOW {cf_status_label} ("Verfügbar")
     * and still get the colour of "available".
     */
    private static function status_class_source($col, $resolved) {
        if (!empty($col['status_color_from_meta'])) {
            $status = get_post_meta((int) get_the_ID(), 'status', true);
            if (is_scalar($status) && (string) $status !== '') {
                return (string) $status;
            }
        }
        return (string) $resolved;
    }

    /**
     * True while rendering for the Bricks builder on behalf of a user who may
     * actually edit. Builder renders skip redaction: the designer needs the
     * real values and an accordion panel to style — same reasoning as the
     * data-builder hook in render() that forces the first panel open. The
     * capability check makes sure no anonymous frontend request can ever
     * reach this branch, whatever endpoint it comes in on.
     */
    private static function is_builder_context() {
        $in_builder = (function_exists('bricks_is_builder_iframe') && bricks_is_builder_iframe())
            || (function_exists('bricks_is_builder_call') && bricks_is_builder_call());

        return $in_builder && current_user_can('edit_posts');
    }

    /**
     * Is this column one we must redact for reserved / sold / rented units?
     *
     * Decided from the column CONFIG, never from the resolved value — a column
     * is sensitive by what it points at, so an empty price is treated exactly
     * like a filled one. link/icon columns keep the URL in `value` and the
     * label in `link_text` ({cf_document_1_title}), so both are checked.
     */
    private static function is_sensitive_column($col) {
        return self::dd_has_sensitive_field($col['value'] ?? '')
            || self::dd_has_sensitive_field($col['link_text'] ?? '');
    }

    /**
     * True if ANY "{cf_…}" tag in $dd names a sensitive field. Scans every tag
     * because one column may combine several (e.g. "{cf_purchase_price_formatted}
     * / {cf_price_per_sqm_formatted}"). The pattern is looser than
     * guess_meta_key_from_dd()'s — it must still catch tags that carry Bricks
     * filter args, like "{cf_purchase_price:number}".
     */
    private static function dd_has_sensitive_field($dd) {
        if (!is_string($dd) || $dd === '') {
            return false;
        }
        if (!preg_match_all('/\{cf_([a-z0-9_]+)/i', $dd, $matches)) {
            return false;
        }
        foreach ($matches[1] as $key) {
            if (self::is_sensitive_field_key($key)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The redaction list: every price, and every Exposé/document link.
     *
     * "_formatted" is stripped first so {cf_purchase_price_formatted} and
     * {cf_purchase_price} hit the same rule (mirrors guess_meta_key_from_dd()).
     * Price matching is segment-exact on "price"/"rent" rather than substring:
     * that catches purchase_price(_investor|_private), price_per_sqm,
     * parking_price, rent_cold, rent_warm, vat_rent and
     * gross_rent_without_heating, while leaving rental_term_years (Befristung
     * — a duration, not money) alone.
     *
     * Ancillary running costs (heating_costs, operating_costs, deposit,
     * minimum_income, commission) are NOT redacted: they are not the asking
     * price. Add the key here if that ever changes.
     */
    private static function is_sensitive_field_key($key) {
        $key = preg_replace('/_formatted$/', '', (string) $key);
        if ($key === '') {
            return false;
        }

        // Exposé & friends: document_1_url, document_2_title, … plus the raw
        // `documents` JSON blob, which carries the same URLs.
        if ($key === 'documents' || preg_match('/^document_\d+_/', $key)) {
            return true;
        }

        $segments = explode('_', $key);

        return in_array('price', $segments, true) || in_array('rent', $segments, true);
    }

    /**
     * Sortable fields offered in the builder, keyed by meta_key.
     *
     * Labels are what the site builder sees — never a raw meta key, because
     * "sort_key" tells nobody that it means staircase + door number. The two
     * "__"-prefixed entries are not meta at all: they map to WP_Query's own
     * orderby values in build_sort_query_vars().
     */
    private static function sort_field_options() {
        return [
            'sort_key'       => esc_html__('Stiege & Tür (Standard)', 'immoadmin'),
            'door_number'    => esc_html__('Türnummer', 'immoadmin'),
            'staircase'      => esc_html__('Stiege', 'immoadmin'),
            'building_name'  => esc_html__('Haus / Gebäude', 'immoadmin'),
            'floor'          => esc_html__('Geschoss', 'immoadmin'),
            'living_area'    => esc_html__('Wohnfläche', 'immoadmin'),
            'room_count'     => esc_html__('Zimmer', 'immoadmin'),
            'purchase_price' => esc_html__('Kaufpreis', 'immoadmin'),
            'rent_cold'      => esc_html__('Miete (kalt)', 'immoadmin'),
            'price_per_sqm'  => esc_html__('Preis pro m²', 'immoadmin'),
            'status'         => esc_html__('Status', 'immoadmin'),
            'object_type'    => esc_html__('Objekttyp', 'immoadmin'),
            '__title'        => esc_html__('Titel', 'immoadmin'),
            '__date'         => esc_html__('Zuletzt hinzugefügt', 'immoadmin'),
            '__custom'       => esc_html__('Eigenes Feld …', 'immoadmin'),
        ];
    }

    /**
     * Meta keys whose values are stored as plain numbers by the sync, so they
     * need NUMERIC casting. Everything else sorts as text.
     */
    private static function numeric_sort_fields() {
        return [
            'sort_key', 'door_number', 'staircase', 'floor', 'living_area',
            'room_count', 'purchase_price', 'rent_cold', 'price_per_sqm',
        ];
    }

    /**
     * Text fields that carry a number inside the string and must sort the way
     * a human counts: "Mackgasse 9" before "Mackgasse 11", not after it.
     *
     * Plain alphabetical comparison puts "11" first because "1" < "9". The fix
     * is ORDER BY LENGTH(value), value — see apply_sort_clauses(). It is
     * opt-in per field rather than blanket-applied to every text column,
     * because for names of equal shape (status, object type) sorting by length
     * first would scramble an otherwise correct alphabetical order.
     */
    private static function natural_sort_fields() {
        return ['building_name'];
    }

    /**
     * Translate the builder's sort controls into WP_Query vars.
     *
     * Returns [] when the settings ask for nothing beyond what the element's
     * query control already does, so the filter can bail without touching a
     * query the user configured by hand.
     */
    private static function build_sort_query_vars($settings) {
        $field = !empty($settings['default_sort_key']) ? (string) $settings['default_sort_key'] : 'sort_key';
        $order = (isset($settings['default_sort_order']) && $settings['default_sort_order'] === 'DESC')
            ? 'DESC'
            : 'ASC';

        if ($field === '__title') {
            return ['orderby' => 'title', 'order' => $order];
        }
        if ($field === '__date') {
            return ['orderby' => 'date', 'order' => $order];
        }

        $numeric = in_array($field, self::numeric_sort_fields(), true);

        if ($field === '__custom') {
            $field = !empty($settings['default_sort_key_custom'])
                ? sanitize_key($settings['default_sort_key_custom'])
                : '';
            if ($field === '') {
                return [];
            }
            $numeric = !empty($settings['default_sort_key_custom_numeric']);
        }

        // Secondary sort only makes sense when the primary is something else —
        // and only helps when the primary produces ties (same house, same
        // floor, same status …), which is exactly the common case here.
        //
        // An absent setting means "never touched", which must resolve to the
        // control's own default rather than to "off" — Bricks only stores what
        // the user actually changed.
        $tiebreak_setting = isset($settings['default_sort_tiebreak'])
            ? (string) $settings['default_sort_tiebreak']
            : 'sort_key';
        $tiebreak = $field !== 'sort_key' && $tiebreak_setting === 'sort_key';

        // Deliberately NOT meta_query. Every meta_query form that WP can order
        // by also filters: 'EXISTS' drops units that never got the meta row,
        // and a plain meta_key adds an INNER JOIN that does the same. Reserved
        // units on liesi.wien have no sort_key, so ordering by house silently
        // removed them from the table — a sort option must never decide which
        // units a visitor gets to see.
        //
        // apply_sort_clauses() turns this spec into LEFT JOINs, where a missing
        // value costs a unit its position but never its row.
        $spec = [[
            'key'     => $field,
            'numeric' => $numeric,
            'natural' => !$numeric && in_array($field, self::natural_sort_fields(), true),
            'dir'     => $order,
        ]];

        if ($tiebreak) {
            $spec[] = [
                'key'     => 'sort_key',
                'numeric' => true,
                'natural' => false,
                'dir'     => 'ASC',
            ];
        }

        return [
            'immoadmin_sort_spec' => $spec,
            // WP must not build an ORDER BY of its own — apply_sort_clauses()
            // replaces it wholesale, and 'none' keeps the element's default
            // meta_value_num ordering (plus its filtering INNER JOIN) out of
            // the SQL entirely.
            'orderby'             => 'none',
            'meta_key'            => '',
        ];
    }

    /**
     * Build the JOIN + ORDER BY for the sort spec.
     *
     * LEFT JOIN per sort level, so a unit missing the meta still appears — it
     * just sorts to the end (the IS NULL term is always ASC, which keeps blanks
     * last in both directions rather than flipping them to the top on DESC).
     *
     * Natural ordering ("Haus 9" before "Haus 11") comes from ordering by
     * LENGTH() first: MySQL compares strings character by character, so plain
     * alphabetical puts "11" ahead of "9". Only fields flagged in
     * natural_sort_fields() get it — for values of uniform shape it would
     * scramble an otherwise correct alphabetical order.
     */
    public static function apply_sort_clauses($clauses, $query) {
        $spec = $query->get('immoadmin_sort_spec');
        if (empty($spec) || !is_array($spec)) {
            return $clauses;
        }

        global $wpdb;
        $order_terms = [];

        foreach (array_values($spec) as $i => $level) {
            if (empty($level['key'])) {
                continue;
            }

            $alias = 'immo_sort_' . (int) $i;
            $dir   = (isset($level['dir']) && strtoupper($level['dir']) === 'DESC') ? 'DESC' : 'ASC';

            $clauses['join'] .= $wpdb->prepare(
                " LEFT JOIN {$wpdb->postmeta} AS {$alias}"
                . " ON {$alias}.post_id = {$wpdb->posts}.ID AND {$alias}.meta_key = %s",
                $level['key']
            );

            // Treat "" like a missing row: the sync stores null as an empty
            // string, which would otherwise cast to 0 and sort ahead of every
            // real value.
            $value = "NULLIF({$alias}.meta_value, '')";

            $order_terms[] = "({$value} IS NULL) ASC";

            if (!empty($level['natural'])) {
                $order_terms[] = "LENGTH({$value}) {$dir}";
            }

            $order_terms[] = !empty($level['numeric'])
                ? "CAST({$value} AS DECIMAL(20,6)) {$dir}"
                : "{$value} {$dir}";
        }

        if (!empty($order_terms)) {
            // Stable last resort: without it MySQL is free to shuffle units
            // that tie on every sort level between one page load and the next.
            $order_terms[]      = "{$wpdb->posts}.ID ASC";
            $clauses['orderby'] = implode(', ', $order_terms);
        }

        return $clauses;
    }

    /**
     * Apply the sort controls to this element's query.
     *
     * Runs on Bricks' own filter rather than mutating the element settings,
     * because Bricks\Query rebuilds query vars from its query control and
     * would drop an unknown meta_query key on the way through.
     *
     * Everything comes from the $settings Bricks hands in, so this works no
     * matter when the query runs. It cannot depend on render() having executed
     * first: a Filter element sitting above the table builds the target query
     * to populate its own options, which happens before the table renders.
     *
     * The filter fires for every Bricks post query on the page, so bail unless
     * the settings carry our own control — no other element has it.
     */
    public static function apply_sort_query_vars($query_vars, $settings, $element_id) {
        if (!is_array($settings) || empty($settings['default_sort_key'])) {
            return $query_vars;
        }

        $sort_vars = self::build_sort_query_vars($settings);

        return empty($sort_vars) ? $query_vars : array_merge($query_vars, $sort_vars);
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

// Registered at file scope, not inside render(). Bricks requires every
// registered element file on `init`, so both filters are in place long before
// anything renders — which matters because a Filter element above the table
// builds the table's query to populate its own options, and a filter added
// during render() would miss that first, page-defining run.
add_filter('bricks/posts/query_vars', ['ImmoAdmin_Units_Table', 'apply_sort_query_vars'], 10, 3);
add_filter('posts_clauses', ['ImmoAdmin_Units_Table', 'apply_sort_clauses'], 10, 2);
add_filter('bricks/posts/query_vars', ['ImmoAdmin_Units_Table', 'apply_building_query_vars'], 10, 3);
