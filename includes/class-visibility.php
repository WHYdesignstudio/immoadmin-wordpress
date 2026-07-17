<?php
/**
 * Public Visibility Guards
 *
 * Reserved / verkaufte / vermietete Einheiten dürfen Besuchern weder Preise
 * noch Dokumente zeigen. Diese Klasse schließt drei Wege:
 *
 *   1. REST      - Preis-/Dokument-Meta wird aus /wp-json/wp/v2/... entfernt
 *   2. Single    - /wohnung/<slug> liefert 404 statt der Detailseite
 *   3. Archiv    - Archiv, Feed und Sitemap listen die Einheit nicht mehr
 *
 * Der Post Type bleibt bewusst public: andere Installationen brauchen
 * Permalinks, Archiv und Bricks-Query-Loops. Gefiltert wird pro Einheit
 * anhand des status-Meta, nicht global am Post Type.
 *
 * Redaktion greift NUR im Lesepfad (rest_prepare_*). Der auth_callback von
 * register_post_meta() ist dafür nicht zuständig: er wird ausschließlich über
 * edit_post_meta / add_post_meta / delete_post_meta ausgewertet, also beim
 * SCHREIBEN. WP_REST_Meta_Fields::get_value() liefert jedes registrierte Meta
 * mit show_in_rest => true ohne jede Capability-Prüfung aus.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ImmoAdmin_Visibility {

    /**
     * Post Types, die geschützt werden (inkl. Legacy-Alias)
     */
    private static $post_types = array('immoadmin_wohnung', 'immoadmin_unit');

    /**
     * Status-Werte, die öffentlich bleiben (Allowlist).
     * Alles andere - reserved, sold, rented und jeder Status, den das Backend
     * später ergänzt - gilt als eingeschränkt (fail closed).
     * Leerer Status bleibt öffentlich, damit Installationen ohne Status-Sync
     * weiter funktionieren.
     */
    private static $public_statuses = array('available', '');

    /**
     * Meta-Keys, die NIE redigiert werden. Das Frontend braucht sie und sie
     * sind nicht sensibel. Sicherheitsnetz gegen zu gierige Patterns.
     */
    private static $never_redact = array('status', 'status_label');

    /**
     * Preis- und Dokument-Patterns.
     *
     * Bewusst Pattern statt fixer Liste: die Meta-Keys kommen 1:1 vom Backend,
     * sind teilweise dynamisch (document_1_url, document_1_title) und wachsen
     * mit jedem Backend-Release. Eine hardcodierte Liste würde still veralten.
     * Geprüft wird gegen die tatsächlichen Keys der REST-Antwort - also eine
     * Obermenge von ImmoAdmin_Post_Type::get_meta_fields() plus alles, was
     * dynamisch dazukommt.
     *
     * Fail closed: lieber ein Feld zu viel redigieren (z.B. rental_term_years
     * über "rent") als ein neues Preisfeld zu leaken.
     *
     * Eindeutige Tokens - dürfen überall im Key stehen.
     */
    private static $sensitive_pattern = '/(price|preis|miete|cost|kosten|kaution|deposit|income|commission|provision|payment|installment|financ|charge|document|dokument|expose|brochure|prospekt|datasheet|attachment)/i';

    /**
     * Kurze/mehrdeutige Tokens - nur am Anfang eines snake_case-Segments,
     * sonst zünden sie mitten in harmlosen Wörtern ("renoVATion",
     * "curRENT_x", "fGEE").
     */
    private static $sensitive_prefix_pattern = '/(?:^|_)(vat|ust|tax|fee|rent|pdf|file)/i';

    /**
     * Register hooks
     */
    public static function init() {
        // REST: sensible Meta aus den Antworten beider Post Types entfernen.
        // Der Legacy-Alias ist zwar public => false, wird von der REST-API
        // wegen show_in_rest => true aber trotzdem ausgeliefert.
        foreach (self::$post_types as $post_type) {
            add_filter("rest_prepare_{$post_type}", array(__CLASS__, 'redact_rest_meta'), 10, 2);
        }

        // Frontend: keine Detailseite, kein Archiv-/Feed-/Sitemap-Eintrag
        add_action('template_redirect', array(__CLASS__, 'block_restricted_single'));
        add_action('pre_get_posts', array(__CLASS__, 'exclude_restricted_from_archive'));
        add_filter('wp_sitemaps_posts_query_args', array(__CLASS__, 'exclude_restricted_from_sitemap'), 10, 2);
    }

    /**
     * REST: Preis- und Dokumentfelder eingeschränkter Einheiten entfernen.
     * Läuft vor dem _fields-Filter (rest_post_dispatch), daher greift auch
     * ?_fields=meta.purchase_price ins Leere.
     */
    public static function redact_rest_meta($response, $post) {
        if (!self::is_restricted($post) || self::can_edit_unit($post)) {
            return $response;
        }

        $data = $response->get_data();
        if (empty($data['meta']) || !is_array($data['meta'])) {
            return $response;
        }

        foreach (array_keys($data['meta']) as $key) {
            if (self::is_sensitive_key($key)) {
                unset($data['meta'][$key]);
            }
        }

        $response->set_data($data);

        return $response;
    }

    /**
     * Detailseite eingeschränkter Einheiten als 404 ausliefern.
     * set_404() vor template_include => WordPress rendert das 404-Template.
     */
    public static function block_restricted_single() {
        if (!is_singular(self::$post_types)) {
            return;
        }

        $post = get_queried_object();
        if (!self::is_restricted($post) || self::can_edit_unit($post)) {
            return;
        }

        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();
    }

    /**
     * Archiv und Feed: eingeschränkte Einheiten nicht listen.
     */
    public static function exclude_restricted_from_archive($query) {
        if (is_admin() || !$query->is_main_query()) {
            return;
        }

        // Einzelansicht übernimmt block_restricted_single()
        if ($query->is_singular() || $query->is_preview()) {
            return;
        }

        // Nur Queries anfassen, die ausschließlich unsere Einheiten holen.
        // Sonst würde die meta_query bei z.B. einer Suche über alle Post Types
        // alle anderen Inhalte mit ausfiltern.
        $post_type = $query->get('post_type');
        if (is_array($post_type) && count($post_type) === 1) {
            $post_type = reset($post_type);
        }
        if (!is_string($post_type) || !in_array($post_type, self::$post_types, true)) {
            return;
        }

        if (self::can_edit_units()) {
            return;
        }

        $query->set('meta_query', self::merge_public_meta_query($query->get('meta_query')));
    }

    /**
     * Sitemap: eingeschränkte Einheiten nicht bewerben - ihre URLs sind 404.
     */
    public static function exclude_restricted_from_sitemap($args, $post_type) {
        if (!in_array($post_type, self::$post_types, true)) {
            return $args;
        }

        $args['meta_query'] = self::merge_public_meta_query(
            isset($args['meta_query']) ? $args['meta_query'] : null
        );

        return $args;
    }

    /**
     * meta_query, die nur öffentliche Einheiten durchlässt, mit einer
     * eventuell vorhandenen meta_query per AND verschachteln (ein simples
     * Anhängen würde bei 'relation' => 'OR' den Schutz aushebeln).
     */
    private static function merge_public_meta_query($existing) {
        $guard = array(
            'relation' => 'OR',
            array(
                'key'     => 'status',
                'value'   => self::$public_statuses,
                'compare' => 'IN',
            ),
            // Einheiten ohne Status-Meta bleiben sichtbar (siehe $public_statuses)
            array(
                'key'     => 'status',
                'compare' => 'NOT EXISTS',
            ),
        );

        if (!empty($existing) && is_array($existing)) {
            return array('relation' => 'AND', $existing, $guard);
        }

        return array($guard);
    }

    /**
     * Ist die Einheit eingeschränkt (= nicht öffentlich verfügbar)?
     */
    private static function is_restricted($post) {
        $post = get_post($post);
        if (!$post || !in_array($post->post_type, self::$post_types, true)) {
            return false;
        }

        $status = strtolower(trim((string) get_post_meta($post->ID, 'status', true)));

        return !in_array($status, self::$public_statuses, true);
    }

    /**
     * Darf der aktuelle User diese Einheit bearbeiten - und damit auch
     * Preise/Dokumente sehen?
     *
     * edit_post ist die Meta-Capability: der Post Type registriert
     * map_meta_cap => true, WordPress löst sie also gegen edit_posts /
     * edit_others_posts / edit_published_posts auf. Redakteure und Admins
     * kommen durch, Abonnenten/Autoren/Mitarbeiter nicht - im Gegensatz zu
     * einem pauschalen current_user_can('edit_posts'), das jeden Mitarbeiter
     * (Contributor) einschließen würde. read_post wäre wirkungslos, weil
     * veröffentlichte Posts für alle lesbar sind.
     */
    private static function can_edit_unit($post) {
        $post = get_post($post);
        if (!$post) {
            return false;
        }

        return current_user_can('edit_post', $post->ID);
    }

    /**
     * Variante ohne konkreten Post (Query-Kontext): primitive Capability aus
     * dem Post Type Objekt, damit Installationen mit eigenem capability_type
     * ebenfalls korrekt geprüft werden. Für die vom Sync angelegten Posts ist
     * das deckungsgleich mit can_edit_unit().
     */
    private static function can_edit_units() {
        $post_type = get_post_type_object('immoadmin_wohnung');
        if (!$post_type || empty($post_type->cap->edit_others_posts)) {
            return false;
        }

        return current_user_can($post_type->cap->edit_others_posts);
    }

    /**
     * Ist der Meta-Key ein Preis- oder Dokumentfeld?
     */
    private static function is_sensitive_key($key) {
        if (in_array($key, self::$never_redact, true)) {
            return false;
        }

        return (bool) preg_match(self::$sensitive_pattern, $key)
            || (bool) preg_match(self::$sensitive_prefix_pattern, $key);
    }
}
