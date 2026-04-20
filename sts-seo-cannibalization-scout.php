<?php
/**
 * Plugin Name: SEO Cannibalization Scout
 * Plugin URI: https://descomplicandoreceitas.com.br
 * Description: Auditoria avançada de canibalização de conteúdo e conflitos de URLs para WordPress. Detecte e organize sua autoridade de pesquisa.
 * Version: 1.6.6
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
    
    if (is_admin()) {
        new \STSCannibal\Admin\Dashboard();
    }
});
