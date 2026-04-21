<?php
namespace STSCannibal\Core;

class Database {
    
    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'sts_scout_index';
    }

    /**
     * Cria a tabela de alta performance para o Scout
     */
    public static function create_table() {
        global $wpdb;
        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            norm_title text NOT NULL,
            norm_slug text NOT NULL,
            clicks int(11) DEFAULT 0,
            impressions int(11) DEFAULT 0,
            last_updated datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY post_id (post_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Atualiza ou Insere um post no índice
     */
    public static function update_index($post_id) {
        global $wpdb;
        $post = get_post($post_id);
        if (!$post || !in_array($post->post_status, ['publish', 'future'])) return;

        $table_name = self::get_table_name();
        
        $norm_title = DeepAnalyzer::normalize($post->post_title);
        $norm_slug = DeepAnalyzer::normalize($post->post_name);

        $wpdb->replace(
            $table_name,
            [
                'post_id' => $post_id,
                'norm_title' => $norm_title,
                'norm_slug' => $norm_slug,
                'last_updated' => current_time('mysql')
            ],
            ['%d', '%s', '%s', '%s']
        );
    }

    /**
     * Remove um post do índice
     */
    public static function delete_from_index($post_id) {
        global $wpdb;
        $table_name = self::get_table_name();
        $wpdb->delete($table_name, ['post_id' => $post_id], ['%d']);
    }
}
