<?php
/**
 * Custom Post Type Registration
 */

if (!defined('ABSPATH')) {
    exit;
}

class ImmoAdmin_Post_Type {

    /**
     * Register the custom post type and meta fields
     */
    public static function register() {
        // Register Custom Post Type
        register_post_type('immoadmin_wohnung', array(
            'labels' => array(
                'name'               => 'Wohnungen',
                'singular_name'      => 'Wohnung',
                'menu_name'          => 'ImmoAdmin',
                'add_new'            => 'Hinzufügen',
                'add_new_item'       => 'Neue Wohnung',
                'edit_item'          => 'Wohnung bearbeiten',
                'view_item'          => 'Wohnung ansehen',
                'all_items'          => 'Alle Wohnungen',
                'search_items'       => 'Wohnungen suchen',
                'not_found'          => 'Keine Wohnungen gefunden',
            ),
            'public'              => true,
            'publicly_queryable'  => true,
            'show_ui'             => true,
            'show_in_menu'        => false, // Hidden from menu, shown in ImmoAdmin dashboard
            'show_in_rest'        => true,
            'query_var'           => true,
            'rewrite'             => array('slug' => 'wohnung'),
            'capability_type'     => 'post',
            'capabilities'        => array(
                'create_posts' => 'do_not_allow', // Disable creating new posts manually
            ),
            'map_meta_cap'        => true,
            'has_archive'         => true,
            'hierarchical'        => false,
            'supports'            => array('title', 'editor', 'thumbnail', 'custom-fields'),
        ));

        // Keep old post type as alias for backwards compatibility
        register_post_type('immoadmin_unit', array(
            'public'          => false,
            'show_ui'         => false,
            'show_in_rest'    => true,
            'rewrite'         => false,
            'capability_type' => 'post',
            'supports'        => array('title', 'custom-fields'),
        ));

        // Register all meta fields
        self::register_meta_fields();
    }

    /**
     * Register all meta fields for the custom post type
     */
    private static function register_meta_fields() {
        $meta_fields = self::get_meta_fields();

        foreach ($meta_fields as $key => $args) {
            // Register for both post types
            foreach (array('immoadmin_wohnung', 'immoadmin_unit') as $post_type) {
                register_post_meta($post_type, $key, array(
                    'type'              => $args['type'],
                    'description'       => $args['description'] ?? '',
                    'single'            => true,
                    'show_in_rest'      => true,
                    'sanitize_callback' => $args['sanitize'] ?? null,
                ));
            }
        }
    }

    /**
     * Get all meta field definitions
     * Keys are kept as-is for sync compatibility
     * Descriptions are German for better usability in Bricks/Elementor
     */
    public static function get_meta_fields() {
        return array(
            // === IDs & Referenzen ===
            '_immoadmin_id'          => array('type' => 'string', 'description' => 'ImmoAdmin ID'),
            'external_id'            => array('type' => 'string', 'description' => 'Externe Referenz-Nr.'),
            'building_id'            => array('type' => 'string', 'description' => 'Gebäude-ID'),
            'building_name'          => array('type' => 'string', 'description' => 'Gebäude-Name'),

            // === Grunddaten ===
            'status'                 => array('type' => 'string', 'description' => 'Status (available / reserved / sold)'),
            'object_type'            => array('type' => 'string', 'description' => 'Objektart (flat / house / plot / commercial / parking)'),
            'marketing_type'         => array('type' => 'string', 'description' => 'Vermarktungsart (sale / rent)'),

            // === Adresse ===
            'street'                 => array('type' => 'string', 'description' => 'Straße'),
            'house_number'           => array('type' => 'string', 'description' => 'Hausnummer'),
            'staircase'              => array('type' => 'string', 'description' => 'Stiege'),
            'door_number'            => array('type' => 'string', 'description' => 'Top / Türnummer'),
            'floor'                  => array('type' => 'integer', 'description' => 'Stockwerk'),
            'floor_label'            => array('type' => 'string', 'description' => 'Stockwerk (Text, z.B. "1. OG", "DG")'),
            'postal_code'            => array('type' => 'string', 'description' => 'PLZ'),
            'city'                   => array('type' => 'string', 'description' => 'Ort'),
            'country'                => array('type' => 'string', 'description' => 'Land'),
            'orientation'            => array('type' => 'string', 'description' => 'Ausrichtung (N/S/O/W)'),
            'latitude'               => array('type' => 'number', 'description' => 'Breitengrad'),
            'longitude'              => array('type' => 'number', 'description' => 'Längengrad'),

            // === Flächen (m²) ===
            'living_area'            => array('type' => 'number', 'description' => 'Wohnfläche (m²)'),
            'usable_area'            => array('type' => 'number', 'description' => 'Nutzfläche (m²)'),
            'total_area'             => array('type' => 'number', 'description' => 'Gesamtfläche (m²)'),
            'plot_area'              => array('type' => 'number', 'description' => 'Grundstücksfläche (m²)'),
            'balcony_area'           => array('type' => 'number', 'description' => 'Balkonfläche (m²)'),
            'terrace_area'           => array('type' => 'number', 'description' => 'Terrassenfläche (m²)'),
            'roof_terrace_area'      => array('type' => 'number', 'description' => 'Dachterrassenfläche (m²)'),
            'loggia_area'            => array('type' => 'number', 'description' => 'Loggiafläche (m²)'),
            'garden_area'            => array('type' => 'number', 'description' => 'Gartenfläche (m²)'),
            'basement_area'          => array('type' => 'number', 'description' => 'Kellerfläche (m²)'),
            'storage_area'           => array('type' => 'number', 'description' => 'Abstellraumfläche (m²)'),

            // === Zimmer ===
            'room_count'             => array('type' => 'number', 'description' => 'Zimmeranzahl'),
            'bedrooms'               => array('type' => 'integer', 'description' => 'Schlafzimmer'),
            'bathrooms'              => array('type' => 'integer', 'description' => 'Badezimmer'),
            'toilets'                => array('type' => 'integer', 'description' => 'WCs'),

            // === Preise ===
            'purchase_price'         => array('type' => 'number', 'description' => 'Kaufpreis (€)'),
            'purchase_price_investor'=> array('type' => 'number', 'description' => 'Kaufpreis Anleger (€)'),
            'purchase_price_private' => array('type' => 'number', 'description' => 'Kaufpreis Eigennutzer (€)'),
            'rent_cold'              => array('type' => 'number', 'description' => 'Kaltmiete (€)'),
            'rent_warm'              => array('type' => 'number', 'description' => 'Warmmiete (€)'),
            'operating_costs'        => array('type' => 'number', 'description' => 'Betriebskosten (€)'),
            'deposit'                => array('type' => 'number', 'description' => 'Kaution (€)'),
            'commission'             => array('type' => 'string', 'description' => 'Provision'),
            'price_per_sqm'          => array('type' => 'number', 'description' => 'Preis pro m² (€)'),

            // === Gebäude ===
            'construction_year'      => array('type' => 'integer', 'description' => 'Baujahr'),
            'renovation_year'        => array('type' => 'integer', 'description' => 'Renovierungsjahr'),
            'condition'              => array('type' => 'string', 'description' => 'Zustand'),
            'equipment'              => array('type' => 'string', 'description' => 'Ausstattung'),
            'heating_type'           => array('type' => 'string', 'description' => 'Heizungsart'),
            'energy_source'          => array('type' => 'string', 'description' => 'Energieträger'),

            // === Energie ===
            'hwb'                    => array('type' => 'number', 'description' => 'HWB (kWh/m²a)'),
            'hwb_class'              => array('type' => 'string', 'description' => 'HWB-Klasse (A++ bis G)'),
            'fgee'                   => array('type' => 'number', 'description' => 'fGEE'),
            'fgee_class'             => array('type' => 'string', 'description' => 'fGEE-Klasse (A++ bis G)'),

            // === Parkplätze ===
            'parking_spaces'         => array('type' => 'integer', 'description' => 'Stellplätze gesamt'),
            'garage_spaces'          => array('type' => 'integer', 'description' => 'Garagenplätze'),
            'outdoor_spaces'         => array('type' => 'integer', 'description' => 'Außenstellplätze'),
            'carport_spaces'         => array('type' => 'integer', 'description' => 'Carport-Plätze'),
            'parking_price'          => array('type' => 'number', 'description' => 'Stellplatz-Preis (€)'),

            // === Medien (JSON) ===
            'images'                 => array('type' => 'string', 'description' => 'Bilder (JSON)'),
            'floor_plans'            => array('type' => 'string', 'description' => 'Grundrisse (JSON)'),
            'documents'              => array('type' => 'string', 'description' => 'Dokumente (JSON)'),

            // === Sonstiges ===
            'features'               => array('type' => 'string', 'description' => 'Ausstattungsmerkmale (JSON)'),
            'extras'                 => array('type' => 'string', 'description' => 'Zusatzfelder (JSON)'),

            // === Sync ===
            '_last_synced'           => array('type' => 'string', 'description' => 'Letzter Sync'),
            '_content_hash'          => array('type' => 'string', 'description' => 'Content-Hash'),
        );
    }
}
