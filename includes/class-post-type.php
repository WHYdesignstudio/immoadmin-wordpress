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
        register_post_type('immoadmin_unit', array(
            'labels' => array(
                'name'               => 'Einheiten',
                'singular_name'      => 'Einheit',
                'menu_name'          => 'Einheiten',
                'add_new'            => 'Hinzufügen',
                'add_new_item'       => 'Neue Einheit',
                'edit_item'          => 'Einheit bearbeiten',
                'view_item'          => 'Einheit ansehen',
                'all_items'          => 'Alle Einheiten',
                'search_items'       => 'Einheiten suchen',
                'not_found'          => 'Keine Einheiten gefunden',
            ),
            'public'              => true,
            'publicly_queryable'  => true,
            'show_ui'             => true,
            'show_in_menu'        => false, // Hidden from menu, shown in ImmoAdmin dashboard
            'show_in_rest'        => true,
            'query_var'           => true,
            'rewrite'             => array('slug' => 'einheit'),
            'capability_type'     => 'post',
            'capabilities'        => array(
                'create_posts' => 'do_not_allow', // Disable creating new posts manually
            ),
            'map_meta_cap'        => true,
            'has_archive'         => true,
            'hierarchical'        => false,
            'supports'            => array('title', 'editor', 'thumbnail', 'custom-fields'),
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
            register_post_meta('immoadmin_unit', $key, array(
                'type'              => $args['type'],
                'description'       => $args['description'] ?? '',
                'single'            => true,
                'show_in_rest'      => true,
                'sanitize_callback' => $args['sanitize'] ?? null,
            ));
        }
    }

    /**
     * Get all meta field definitions
     */
    public static function get_meta_fields() {
        return array(
            // IDs
            '_immoadmin_id'          => array('type' => 'string', 'description' => 'ImmoAdmin Unit ID'),
            'external_id'            => array('type' => 'string', 'description' => 'External Reference ID'),
            'building_id'            => array('type' => 'string', 'description' => 'Building ID'),
            'building_name'          => array('type' => 'string', 'description' => 'Building Name'),

            // Basic
            'status'                 => array('type' => 'string', 'description' => 'Status (available/reserved/sold)'),
            'object_type'            => array('type' => 'string', 'description' => 'Object Type'),
            'marketing_type'         => array('type' => 'string', 'description' => 'Marketing Type (sale/rent)'),

            // Location
            'street'                 => array('type' => 'string'),
            'house_number'           => array('type' => 'string'),
            'staircase'              => array('type' => 'string'),
            'door_number'            => array('type' => 'string'),
            'floor'                  => array('type' => 'integer'),
            'postal_code'            => array('type' => 'string'),
            'city'                   => array('type' => 'string'),
            'country'                => array('type' => 'string'),
            'orientation'            => array('type' => 'string'),
            'latitude'               => array('type' => 'number'),
            'longitude'              => array('type' => 'number'),

            // Areas (m²)
            'living_area'            => array('type' => 'number'),
            'usable_area'            => array('type' => 'number'),
            'total_area'             => array('type' => 'number'),
            'plot_area'              => array('type' => 'number'),
            'balcony_area'           => array('type' => 'number'),
            'terrace_area'           => array('type' => 'number'),
            'roof_terrace_area'      => array('type' => 'number'),
            'loggia_area'            => array('type' => 'number'),
            'garden_area'            => array('type' => 'number'),
            'basement_area'          => array('type' => 'number'),
            'storage_area'           => array('type' => 'number'),

            // Rooms
            'room_count'             => array('type' => 'number'),
            'bedrooms'               => array('type' => 'integer'),
            'bathrooms'              => array('type' => 'integer'),
            'toilets'                => array('type' => 'integer'),

            // Pricing
            'purchase_price'         => array('type' => 'number'),
            'purchase_price_investor'=> array('type' => 'number'),
            'purchase_price_private' => array('type' => 'number'),
            'rent_cold'              => array('type' => 'number'),
            'rent_warm'              => array('type' => 'number'),
            'operating_costs'        => array('type' => 'number'),
            'deposit'                => array('type' => 'number'),
            'commission'             => array('type' => 'string'),
            'price_per_sqm'          => array('type' => 'number'),

            // Building
            'construction_year'      => array('type' => 'integer'),
            'renovation_year'        => array('type' => 'integer'),
            'condition'              => array('type' => 'string'),
            'equipment'              => array('type' => 'string'),
            'heating_type'           => array('type' => 'string'),
            'energy_source'          => array('type' => 'string'),

            // Energy
            'hwb'                    => array('type' => 'number'),
            'hwb_class'              => array('type' => 'string'),
            'fgee'                   => array('type' => 'number'),
            'fgee_class'             => array('type' => 'string'),

            // Parking
            'parking_spaces'         => array('type' => 'integer'),
            'garage_spaces'          => array('type' => 'integer'),
            'outdoor_spaces'         => array('type' => 'integer'),
            'carport_spaces'         => array('type' => 'integer'),
            'parking_price'          => array('type' => 'number'),

            // Media (stored as JSON arrays)
            'images'                 => array('type' => 'string', 'description' => 'JSON array of image URLs'),
            'floor_plans'            => array('type' => 'string', 'description' => 'JSON array of floor plan URLs'),
            'documents'              => array('type' => 'string', 'description' => 'JSON array of document URLs'),

            // Other
            'features'               => array('type' => 'string', 'description' => 'JSON array of features'),
            'extras'                 => array('type' => 'string', 'description' => 'JSON object of extra fields'),

            // Sync metadata
            '_last_synced'           => array('type' => 'string', 'description' => 'Last sync timestamp'),
            '_content_hash'          => array('type' => 'string', 'description' => 'Content hash for change detection'),
        );
    }
}
