<?php
namespace STSCannibal\Engine;

class Redirector {
    public function __construct() {
        add_action('template_redirect', [$this, 'handle_seo_actions']);
        add_action('wp_head', [$this, 'inject_canonical'], 1);
    }

    /**
     * Gerencia Redirecionamentos 301 salvos pelo Scout
     */
    public function handle_seo_actions() {
        if (!is_singular()) return;

        $post_id = get_the_ID();
        $redirect_url = get_post_meta($post_id, '_sts_seo_redirect', true);

        if ($redirect_url && !empty($redirect_url)) {
            // Garante que não redirecione para si mesmo
            if (untrailingslashit($redirect_url) !== untrailingslashit(get_permalink($post_id))) {
                wp_redirect($redirect_url, 301);
                exit;
            }
        }
    }

    /**
     * Injeta a Canonical Tag se o usuário escolheu o modo de Autoridade
     */
    public function inject_canonical() {
        if (!is_singular()) return;

        $post_id = get_the_ID();
        $canonical_url = get_post_meta($post_id, '_sts_seo_canonical', true);

        if ($canonical_url && !empty($canonical_url)) {
            // Remove a canonical padrão do WordPress para não duplicar
            remove_action('wp_head', 'rel_canonical');
            echo '<link rel="canonical" href="' . esc_url($canonical_url) . '" />' . "\n";
            echo '<!-- STS Cannibalization Scout: Canonical Fixed -->' . "\n";
        }
    }
}
