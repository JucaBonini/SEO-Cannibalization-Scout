<?php
/**
 * Plugin Name: SEO Cannibalization Scout IA
 * Plugin URI: https://descomplicandoreceitas.com.br
 * Description: [GOD MODE ENABLED] Auditoria Cirúrgica, Estratégia de Topic Clusters e Execução de Redirecionamento 301.
 * Version: 3.4.1 [SYNC & GUTENBERG FIX]
 * Author: Juca Souza Bonini
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: seo-cannibalization-scout
 */

defined('ABSPATH') || exit;

// PSR-4 Autoloader
spl_autoload_register(function ($class) {
    if (strpos($class, 'STSCannibal\\') !== 0) return;
    $file = plugin_dir_path(__FILE__) . 'src/' . str_replace('\\', '/', substr($class, 12)) . '.php';
    if (file_exists($file)) require $file;
});

// Inicialização
add_action('plugins_loaded', function() {
    load_plugin_textdomain('seo-cannibalization-scout', false, dirname(plugin_basename(__FILE__)) . '/languages');
    
    // Motor de Execução (Frontend)
    if (!is_admin()) {
        new \STSCannibal\Engine\Redirector();
    }

    if (is_admin()) {
        new \STSCannibal\Admin\Dashboard();
        new \STSCannibal\Admin\EditorScout();
        
        // Verificador de Updates via GitHub (Dinâmico)
        $plugin_data = get_file_data(__FILE__, array('Version' => 'Version'), 'plugin');
        new \STSCannibal\Engine\Updater('sts-seo-cannibalization-scout', $plugin_data['Version'], 'JucaBonini/SEO-Cannibalization-Scout');
    }
});

// Fase 1 & 2: Infraestrutura de Dados (God Mode)
register_activation_hook(__FILE__, ['\STSCannibal\Core\Database', 'create_table']);

// Sincronização Automática do Índice
add_action('save_post', function($post_id, $post, $update) {
    if (wp_is_post_revision($post_id) || $post->post_status !== 'publish') return;
    \STSCannibal\Core\Database::update_index($post_id);
}, 10, 3);

add_action('before_delete_post', function($post_id) {
    \STSCannibal\Core\Database::delete_from_index($post_id);
});
