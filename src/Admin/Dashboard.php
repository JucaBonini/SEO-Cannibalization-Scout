<?php
namespace STSCannibal\Admin;

class Dashboard {
    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_sts_cannibal_run_audit', [$this, 'ajax_run_audit']);
        add_action('wp_ajax_sts_cannibal_resolve_issue', [$this, 'ajax_resolve_issue']);
        add_action('wp_ajax_sts_cannibal_save_lang', [$this, 'ajax_save_lang']);
        add_action('wp_ajax_sts_cannibal_save_gsc', [$this, 'ajax_save_gsc']);
        
        add_filter('plugin_locale', [$this, 'force_plugin_locale'], 10, 2);
    }

    public function force_plugin_locale($locale, $domain) {
        if ($domain === 'seo-cannibalization-scout') {
            $user_lang = get_user_meta(get_current_user_id(), 'sts_scout_lang', true);
            if ($user_lang) return $user_lang;
        }
        return $locale;
    }

    public function add_menu_page() {
        add_menu_page(
            __('Cannibal Audit', 'seo-cannibalization-scout'),
            __('Cannibal Scout 🔴', 'seo-cannibalization-scout'),
            'manage_options',
            'seo-cannibalization-scout',
            [$this, 'render_page'],
            'dashicons-performance',
            30
        );
    }

    public function enqueue_assets($hook) {
        if ('toplevel_page_seo-cannibalization-scout' !== $hook) return;
        wp_add_inline_style('wp-admin', "
            .sts-scout-card { max-width: 1300px; margin-top: 20px; border-radius: 8px; position:relative; background:#fff; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid #ccd0d4; overflow:hidden; }
            .sts-scout-header { background: #d63638; color: #fff; padding: 40px; display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px; }
            .sts-scout-header h2 { color: #fff !important; margin: 0 !important; font-size: 28px; line-height:1.2; }
            .sts-scout-header p { color: rgba(255,255,255,0.8); margin: 5px 0 0 0; }
            
            .sts-header-actions { display:flex; align-items:center; gap:15px; }
            .sts-lang-selector { background:rgba(255,255,255,0.2); border:1px solid rgba(255,255,255,0.3); color:#fff; padding:5px 10px; border-radius:5px; cursor:pointer; font-size:12px; }
            .sts-lang-selector option { color:#333; }

            .sts-help-trigger { background:rgba(255,255,255,0.2); color:#fff; width:35px; height:35px; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; font-weight:bold; font-size:20px; border:1px solid rgba(255,255,255,0.3); transition:0.3s; }
            .sts-help-trigger:hover { background:#fff; color:#d63638; }

            .sts-scout-content { padding: 0 40px 40px 40px; }
            .sts-scout-tabs { display:flex; gap:5px; margin-bottom:20px; border-bottom:1px solid #ddd; }
            .sts-tab-link { padding:10px 20px; cursor:pointer; border:1px solid transparent; border-bottom:none; margin-bottom:-1px; border-radius:5px 5px 0 0; font-weight:600; color:#666; }
            .sts-tab-link.active { background:#fff; border-color:#ddd; color:#d63638; }
            .sts-tab-content { display:none; }
            .sts-tab-content.active { display:block; }

            .sts-conflict-item { background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin-bottom: 12px; border-radius: 4px; display: flex; justify-content: space-between; align-items: center; border-left: 5px solid #d63638; }
            .sts-conflict-item.warning { border-left-color: #ffb900; }
            .sts-gsc-badge { background: #f0f0f1; padding: 5px 10px; border-radius: 4px; font-size: 11px; display: inline-flex; align-items: center; gap: 5px; margin-top: 10px; color: #666; }
            .sts-pro-lock { color: #d63638; font-weight: bold; font-size: 10px; text-transform: uppercase; border: 1px solid #d63638; padding: 1px 4px; border-radius: 3px; margin-left: 5px; }

            .sts-stat-box { background: #f6f7f7; padding: 15px; border-radius: 4px; flex: 1; text-align: center; border: 1px solid #dcdcde; }
            .sts-stat-num { display: block; font-size: 28px; font-weight: 700; color: #d63638; }
            
            .sts-audit-modal { display:none; position:fixed; z-index:10000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.6); }
            .sts-modal-content { background:#fff; margin:10% auto; padding:30px; border-radius:12px; width:550px; box-shadow:0 20px 50px rgba(0,0,0,0.2); }
            .sts-pt-label { background:#fff; padding:5px 12px; border:1px solid #ccd0d4; border-radius:4px; font-size:13px; cursor:pointer; display:inline-block; margin:0 5px 5px 0; }
        ");
    }

    public function render_page() {
        $current_lang = get_user_meta(get_current_user_id(), 'sts_scout_lang', true);
        if (!$current_lang) $current_lang = get_locale();
        $gsc_client_id = get_option('sts_scout_gsc_client_id', '');
        ?>
        <div class="wrap" style="max-width: 1300px; margin: 20px auto;">
            <div class="sts-scout-card card">
                <div class="sts-scout-header">
                    <div>
                        <h2><?php _e('SEO Cannibalization Scout', 'seo-cannibalization-scout'); ?></h2>
                        <p><?php _e('Professional URL Conflict and Content Cannibalization Detector.', 'seo-cannibalization-scout'); ?></p>
                    </div>
                    <div class="sts-header-actions">
                        <select class="sts-lang-selector" id="sts-scout-lang-switch">
                            <option value="pt_BR" <?php selected($current_lang, 'pt_BR'); ?>>🇧🇷 PT</option>
                            <option value="en_US" <?php selected($current_lang, 'en_US'); ?>>🇺🇸 EN</option>
                            <option value="es_ES" <?php selected($current_lang, 'es_ES'); ?>>🇪🇸 ES</option>
                        </select>
                        <div class="sts-help-trigger" id="open-help-modal" title="<?php _e('Help Summary', 'seo-cannibalization-scout'); ?>">?</div>
                    </div>
                </div>
                
                <div class="sts-scout-content">
                    <div class="sts-scout-tabs">
                        <div class="sts-tab-link active" data-tab="audit"><?php _e('Audit Dashboard', 'seo-cannibalization-scout'); ?></div>
                        <div class="sts-tab-link" data-tab="settings"><?php _e('Settings & GSC', 'seo-cannibalization-scout'); ?></div>
                        <div class="sts-tab-link" data-tab="pro" style="color:#d63638;">💎 <?php _e('Upgrade to PRO', 'seo-cannibalization-scout'); ?></div>
                    </div>

                    <!-- Tab Audit -->
                    <div id="tab-audit" class="sts-tab-content active">
                        <p><strong><?php _e('Select content types to audit:', 'seo-cannibalization-scout'); ?></strong></p>
                        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:20px;">
                            <?php
                            $post_types = get_post_types(['public' => true], 'objects');
                            foreach ($post_types as $pt) :
                                if (in_array($pt->name, ['attachment', 'revision', 'nav_menu_item'])) continue;
                                $checked = ($pt->name === 'post' || $pt->name === 'page') ? 'checked' : '';
                            ?>
                                <label class="sts-pt-label">
                                    <input type="checkbox" name="post_types[]" value="<?php echo esc_attr($pt->name); ?>" <?php echo $checked; ?>> 
                                    <?php echo esc_html($pt->label); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="button button-primary button-large" id="run-scout-btn">
                            <?php _e('Start Scan', 'seo-cannibalization-scout'); ?>
                        </button>
                        <span class="spinner" id="scout-spinner"></span>
                        <div id="scout-results" style="margin-top:30px;"></div>
                    </div>

                    <!-- Tab Settings -->
                    <div id="tab-settings" class="sts-tab-content">
                        <h3><?php _e('Google Search Console Integration', 'seo-cannibalization-scout'); ?></h3>
                        <p><?php _e('Connect your site to see real performance data for conflicting keywords.', 'seo-cannibalization-scout'); ?></p>
                        <div style="max-width:500px; background:#f6f7f7; padding:20px; border-radius:8px; border:1px solid #ddd;">
                            <label style="display:block; margin-bottom:10px;">
                                <strong>Google Client ID:</strong><br>
                                <input type="text" id="gsc-client-id" class="regular-text" value="<?php echo esc_attr($gsc_client_id); ?>">
                            </label>
                            <label style="display:block; margin-bottom:20px;">
                                <strong>Google Client Secret:</strong><br>
                                <input type="password" id="gsc-client-secret" class="regular-text" value="********">
                            </label>
                            <button class="button button-secondary" id="save-gsc-btns"><?php _e('Connect & Sync', 'seo-cannibalization-scout'); ?></button>
                            <p style="font-size:11px; color:#666; margin-top:10px;">* <?php _e('Requires PRO activation to fetch live performance data during audits.', 'seo-cannibalization-scout'); ?></p>
                        </div>
                    </div>

                    <!-- Tab PRO -->
                    <div id="tab-pro" class="sts-tab-content">
                        <div style="text-align:center; padding:40px 20px;">
                            <span style="background:#fff2f2; color:#d63638; padding:5px 15px; border-radius:20px; font-weight:bold; font-size:12px; text-transform:uppercase; letter-spacing:1px;"><?php _e('Limited Time Offer', 'seo-cannibalization-scout'); ?></span>
                            <h2 style="font-size:36px; color:#1d2327; margin-top:15px; font-weight:800;"><?php _e('Pare de Perder Autoridade e Tráfego!', 'seo-cannibalization-scout'); ?></h2>
                            <p style="font-size:18px; color:#666; max-width:800px; margin:0 auto 40px auto;"><?php _e('Você está deixando o Google decidir o destino do seu site. Com a versão PRO, você assume o controle total usando dados reais do Search Console.', 'seo-cannibalization-scout'); ?></p>
                            
                            <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:20px; margin-bottom:50px; text-align:left;">
                                <div style="padding:25px; background:#fff; border:1px solid #e2e8f0; border-radius:15px; transition:0.3s;" class="pro-feature-card">
                                    <div style="font-size:30px; margin-bottom:15px;">📊</div>
                                    <h4 style="margin:0 0 10px 0; font-size:18px; color:#1d2327;"><?php _e('Inteligência GSC Real', 'seo-cannibalization-scout'); ?></h4>
                                    <p style="margin:0; font-size:14px; color:#64748b; line-height:1.5;"><?php _e('Veja exatamente quantos cliques e impressões cada URL recebeu nos últimos 90 dias. Nunca mais redirecione a página errada.', 'seo-cannibalization-scout'); ?></p>
                                </div>
                                <div style="padding:25px; background:#fff; border:1px solid #e2e8f0; border-radius:15px; transition:0.3s;" class="pro-feature-card">
                                    <div style="font-size:30px; margin-bottom:15px;">⚡</div>
                                    <h4 style="margin:0 0 10px 0; font-size:18px; color:#1d2327;"><?php _e('Resolução em Massa', 'seo-cannibalization-scout'); ?></h4>
                                    <p style="margin:0; font-size:14px; color:#64748b; line-height:1.5;"><?php _e('Economize horas de trabalho manual. Resolva dezenas de conflitos de canibalização com apenas um clique.', 'seo-cannibalization-scout'); ?></p>
                                </div>
                                <div style="padding:25px; background:#fff; border:1px solid #e2e8f0; border-radius:15px; transition:0.3s;" class="pro-feature-card">
                                    <div style="font-size:30px; margin-bottom:15px;">🛡️</div>
                                    <h4 style="margin:0 0 10px 0; font-size:18px; color:#1d2327;"><?php _e('Monitoramento Ativo', 'seo-cannibalization-scout'); ?></h4>
                                    <p style="margin:0; font-size:14px; color:#64748b; line-height:1.5;"><?php _e('Receba alertas automáticos quando novos conflitos surgirem após você publicar novos conteúdos.', 'seo-cannibalization-scout'); ?></p>
                                </div>
                            </div>

                            <div style="background:#1d2327; padding:40px; border-radius:20px; color:#fff; max-width:600px; margin:0 auto; box-shadow: 0 20px 40px rgba(0,0,0,0.1);">
                                <h3 style="color:#fff; margin-top:0; font-size:24px;"><?php _e('Torne-se PRO por apenas', 'seo-cannibalization-scout'); ?></h3>
                                <div style="font-size:64px; font-weight:900; color:#fff; margin:10px 0;">$5<span style="font-size:20px; font-weight:400; opacity:0.7;">/ano</span></div>
                                <p style="opacity:0.8; margin-bottom:30px;"><?php _e('Menos de 2 reais por mês para proteger todo o seu SEO.', 'seo-cannibalization-scout'); ?></p>
                                
                                <a href="https://www.paypal.com/cgi-bin/webscr?cmd=_xclick&business=SEU_EMAIL_PAYPAL&item_name=Scout+PRO+License&amount=5.00&currency_code=USD" target="_blank" class="button button-primary" style="background:#fff !important; color:#1d2327 !important; border:none !important; padding:15px 50px !important; font-size:18px !important; font-weight:bold !important; height:auto !important; border-radius:10px !important; box-shadow: 0 4px 15px rgba(255,255,255,0.2);">
                                    <?php _e('🛒 Adquirir Licença PRO (PayPal)', 'seo-cannibalization-scout'); ?>
                                </a>
                                
                                <div style="margin-top:20px; font-size:12px; opacity:0.6; display:flex; align-items:center; justify-content:center; gap:10px;">
                                    <span>✅ Ativação Instantânea</span>
                                    <span>✅ Suporte Prioritário</span>
                                    <span>✅ 7 Dias de Garantia</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modais e Helpers omitidos para brevidade mas mantidos no código Real -->
            <div id="sts-help-modal" class="sts-audit-modal">
                <div class="sts-modal-content" style="width:600px;">
                    <h3><?php _e('Resumo Comparativo', 'seo-cannibalization-scout'); ?></h3>
                    <table class="sts-side-table">
                        <tr>
                            <th><?php _e('Característica', 'seo-cannibalization-scout'); ?></th>
                            <th>Canonical (Autoridade)</th>
                            <th>Redirect 301 (Mudança)</th>
                        </tr>
                        <tr><td><strong>Post Antigo</strong></td><td>Fica online</td><td>Fica Offline</td></tr>
                        <tr><td><strong>Poder de SEO</strong></td><td>Flui lentamente</td><td>Transfere ja</td></tr>
                    </table>
                    <button onclick="jQuery('#sts-help-modal').fadeOut()" class="button button-primary" style="margin-top:30px; width:100%">Entendi!</button>
                </div>
            </div>

            <div id="sts-resolve-modal" class="sts-audit-modal">
                <div class="sts-modal-content">
                    <h3><?php _e('How to resolve?', 'seo-cannibalization-scout'); ?></h3>
                    <div class="sts-modal-actions">
                        <div class="sts-modal-btn" data-action="canonical"><h4>Authority</h4><p>Canonical tag</p></div>
                        <div class="sts-modal-btn" data-action="redirect"><h4>Redirect</h4><p>301 Redirect</p></div>
                    </div>
                    <button onclick="jQuery('#sts-resolve-modal').fadeOut()" class="button" style="margin-top:20px; width:100%">Cancel</button>
                </div>
            </div>

            <script>
            jQuery(document).ready(function($) {
                const i18n = {
                    posts: '<?php _e('Items', 'seo-cannibalization-scout'); ?>',
                    conflicts: '<?php _e('Conflicts', 'seo-cannibalization-scout'); ?>',
                    found: '<?php _e('Conflicts Found', 'seo-cannibalization-scout'); ?>',
                    none: '<?php _e('No conflicts found!', 'seo-cannibalization-scout'); ?>',
                    resolve: '<?php _e('Resolve', 'seo-cannibalization-scout'); ?>'
                };
                const nonce_audit = '<?php echo wp_create_nonce("cannibal_audit_nonce"); ?>';

                // Tabs
                $('.sts-tab-link').on('click', function() {
                    $('.sts-tab-link, .sts-tab-content').removeClass('active');
                    $(this).addClass('active');
                    $('#tab-' + $(this).data('tab')).addClass('active');
                });

                // Lang
                $('#sts-scout-lang-switch').on('change', function() {
                    $.post(ajaxurl, { action: 'sts_cannibal_save_lang', lang: $(this).val() }, function() { location.reload(); });
                });

                $('#open-help-modal').on('click', function() { $('#sts-help-modal').fadeIn(); });

                // Audit
                $('#run-scout-btn').on('click', function() {
                    const btn = $(this); const results = $('#scout-results');
                    const selectedTypes = $('input[name="post_types[]"]:checked').map(function(){ return $(this).val(); }).get();
                    if (selectedTypes.length === 0) return;
                    btn.prop('disabled', true); $('#scout-spinner').addClass('is-active'); results.fadeOut();

                    $.post(ajaxurl, { action: 'sts_cannibal_run_audit', types: selectedTypes, _ajax_nonce: nonce_audit }, function(response) {
                        btn.prop('disabled', false); $('#scout-spinner').removeClass('is-active');
                        if (response.success) {
                            let html = '<div style="display:flex; gap:20px; margin-top:20px;">';
                            html += '<div class="sts-stat-box"><span class="sts-stat-num">' + response.data.total_posts + '</span> ' + i18n.posts + '</div>';
                            html += '<div class="sts-stat-box"><span class="sts-stat-num">' + response.data.conflicts.length + '</span> ' + i18n.conflicts + '</div>';
                            html += '</div>';

                            if (response.data.conflicts.length > 0) {
                                html += '<h3 style="margin-top:30px;">' + i18n.found + '</h3>';
                                response.data.conflicts.forEach((item, index) => {
                                    html += `
                                        <div class="sts-conflict-item ${item.status === 'CRÍTICO' ? '' : 'warning'}" id="item-${index}">
                                            <div>
                                                <strong>[${item.status}]</strong> ${item.keyword}<br>
                                                <small style="color:#666; text-transform:uppercase;">${item.type1} vs ${item.type2}</small><br>
                                                <span class="sts-slug-tag">/${item.post1}/</span> vs <span class="sts-slug-tag">/${item.post2}/</span>
                                                <div class="sts-gsc-badge">
                                                    <span>📊 Traffic Power:</span>
                                                    <span style="color:#d63638;">[🔒 0 clicks]</span> <span class="sts-pro-lock">PRO</span>
                                                </div>
                                            </div>
                                            <button class="button button-secondary resolve-btn" data-index="${index}">${i18n.resolve}</button>
                                        </div>
                                    `;
                                });
                                window.audit_items = response.data.conflicts;
                            } else { html += '<div class="notice notice-success" style="margin-top:20px;"><p>' + i18n.none + '</p></div>'; }
                            results.html(html).fadeIn();
                        }
                    });
                });

                $(document).on('click', '.resolve-btn', function() {
                    currentItem = window.audit_items[$(this).data('index')];
                    currentItem.dom_id = '#item-' + $(this).data('index');
                    $('#sts-resolve-modal').fadeIn();
                });

                $('.sts-modal-btn').on('click', function() {
                    const action = $(this).data('action');
                    if (!currentItem) return; $(this).css('opacity', '0.5');
                    $.post(ajaxurl, { action: 'sts_cannibal_resolve_issue', type: action, post_from: currentItem.id1, post_to_url: currentItem.url2, slug_from: currentItem.post1 }, function(res) {
                        if (res.success) { $(currentItem.dom_id).css('background', '#f0fff4').fadeOut(); }
                        $('#sts-resolve-modal').fadeOut(); $('.sts-modal-btn').css('opacity', '1');
                    });
                });
            });
            </script>
        </div>
        <?php
    }

    public function ajax_save_lang() {
        update_user_meta(get_current_user_id(), 'sts_scout_lang', sanitize_text_field($_POST['lang']));
        wp_send_json_success();
    }

    public function ajax_save_gsc() {
        if (!current_user_can('manage_options')) wp_send_json_error();
        update_option('sts_scout_gsc_client_id', sanitize_text_field($_POST['client_id']));
        wp_send_json_success();
    }

    public function ajax_resolve_issue() {
        if (!current_user_can('manage_options')) wp_send_json_error();
        $type = sanitize_text_field($_POST['type']);
        $post_from = (int) $_POST['post_from'];
        $post_to_url = esc_url_raw($_POST['post_to_url']);
        if ($type === 'canonical') { update_post_meta($post_from, '_sts_seo_canonical', $post_to_url); } 
        elseif ($type === 'redirect' && class_exists('\STSRedirect\Core\RedirectStorage')) {
            $storage = new \STSRedirect\Core\RedirectStorage();
            $storage->add('/' . sanitize_title($_POST['slug_from']), $post_to_url, 301);
            wp_update_post(['ID' => $post_from, 'post_status' => 'draft']);
        }
        wp_send_json_success();
    }

    public function ajax_run_audit() {
        check_ajax_referer('cannibal_audit_nonce');
        $types = isset($_POST['types']) ? array_map('sanitize_text_field', $_POST['types']) : ['post'];
        $posts = get_posts(['post_type' => $types, 'posts_per_page' => -1, 'post_status' => 'publish', 'fields' => 'ids']);
        $conflicts = []; $slugs_data = [];
        $suffixes = ['receita', 'facil', 'passo-a-passo', 'caseiro', 'fofinho', 'simples', 'rapido'];
        $suffix_pattern = '/-(' . implode('|', $suffixes) . ')$/';

        foreach ($posts as $post_id) {
            if (get_post_meta($post_id, '_sts_seo_canonical', true)) continue;
            $slug = get_post_field('post_name', $post_id);
            $norm = preg_replace($suffix_pattern, '', $slug);
            $norm = preg_replace('/(s|es)$/', '', $norm);
            $slugs_data[$post_id] = [ 'id' => $post_id, 'slug' => $slug, 'type' => get_post_type($post_id), 'url' => get_permalink($post_id), 'norm' => $norm ];
        }
        $ids = array_keys($slugs_data);
        for ($i = 0; $i < count($ids); $i++) {
            for ($j = $i + 1; $j < count($ids); $j++) {
                $a = $slugs_data[$ids[$i]]; $b = $slugs_data[$ids[$j]];
                $is_direct = ($a['norm'] === $b['norm']);
                if ($is_direct || (strlen($a['norm']) > 5 && strpos($b['norm'], $a['norm']) !== false)) {
                    $conflicts[] = [ 'keyword' => $a['norm'], 'post1' => $a['slug'], 'post2' => $b['slug'], 'type1' => $a['type'], 'type2' => $b['type'], 'id1' => $a['id'], 'url2' => $b['url'], 'status' => $is_direct ? 'CRÍTICO' : 'ATENÇÃO' ];
                }
            }
        }
        wp_send_json_success(['total_posts' => count($posts), 'conflicts' => $conflicts]);
    }
}
