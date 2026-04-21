<?php
/**
 * Plugin Name: SEO Cannibalization Scout
 * Plugin URI: https://descomplicandoreceitas.com.br
 * Description: [GOD MODE ENABLED] Auditoria Cirúrgica e Execução de Redirecionamento 301 para aniquilar a canibalização de conteúdo.
 * Version: 3.0.0
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
        // Verificador de Updates via GitHub
        new \STSCannibal\Engine\Updater('sts-seo-cannibalization-scout', '3.0.0', 'JucaBonini/SEO-Cannibalization-Scout');
    }
});
