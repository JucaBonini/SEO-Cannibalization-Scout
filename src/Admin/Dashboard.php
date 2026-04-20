<?php
namespace STSCannibal\Admin;

class Dashboard {
    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_sts_cannibal_run_audit', [$this, 'ajax_run_audit']);
        add_action('wp_ajax_sts_cannibal_resolve_issue', [$this, 'ajax_resolve_issue']);
        add_action('wp_ajax_sts_cannibal_bulk_resolve', [$this, 'ajax_bulk_resolve']);
        add_action('wp_ajax_sts_cannibal_save_lang', [$this, 'ajax_save_lang']);
        add_action('wp_ajax_sts_cannibal_save_gsc', [$this, 'ajax_save_gsc']);
        
        add_filter('plugin_locale', [$this, 'force_plugin_locale'], 10, 2);
        
        // Capturar o retorno do Google
        add_action('admin_init', [$this, 'handle_google_auth_callback']);
    }

    public function handle_google_auth_callback() {
        if (isset($_GET['page']) && $_GET['page'] === 'seo-cannibalization-scout' && isset($_GET['code'])) {
            $code = sanitize_text_field($_GET['code']);
            // Aqui futuramente faremos a troca do CODE pelo TOKEN (v1.5)
            // Por enquanto, salvamos que a autorização foi recebida
            update_option('sts_scout_gsc_auth_code', $code);
            wp_redirect(admin_url('admin.php?page=seo-cannibalization-scout&auth=success'));
            exit;
        }
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
            .sts-scout-card { max-width: 1300px; margin: 20px auto; border-radius: 8px; position:relative; background:#fff; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid #ccd0d4; overflow:hidden; }
            .sts-scout-header { background: #d63638; color: #fff; padding: 40px; display:flex; justify-content:space-between; align-items:center; }
            .sts-scout-header h2 { color: #fff !important; margin: 0 !important; font-size: 28px; line-height:1.2; }
            .sts-scout-header p { color: rgba(255,255,255,0.8); margin: 5px 0 0 0; }
            
            .sts-header-actions { display:flex; align-items:center; gap:15px; }
            .sts-lang-selector { background:rgba(255,255,255,0.2); border:1px solid rgba(255,255,255,0.3); color:#fff; padding:5px 10px; border-radius:5px; cursor:pointer; font-size:12px; }
            .sts-lang-selector option { color:#333; }

            .sts-help-trigger { background:rgba(255,255,255,0.2); color:#fff; width:35px; height:35px; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; font-weight:bold; font-size:20px; border:1px solid rgba(255,255,255,0.3); transition:0.3s; }
            .sts-help-trigger:hover { background:#fff; color:#d63638; }

            .sts-scout-content { padding: 30px; }
            .sts-scout-tabs { display:flex; gap:5px; margin-bottom:20px; border-bottom:1px solid #ddd; overflow-x: auto; white-space: nowrap; -webkit-overflow-scrolling: touch; }
            .sts-scout-tabs::-webkit-scrollbar { display: none; }
            .sts-tab-link { padding:10px 20px; cursor:pointer; border:1px solid transparent; border-bottom:none; margin-bottom:-1px; border-radius:5px 5px 0 0; font-weight:600; color:#666; display: inline-block; }
            .sts-tab-link.active { background:#fff; border-color:#ddd; color:#d63638; }
            .sts-tab-content { display:none; }
            .sts-tab-content.active { display:block; }

            /* Mobile Adjustments */
            @media (max-width: 782px) {
                .sts-scout-header { padding: 25px 20px; flex-direction: column; align-items: flex-start; gap: 20px; }
                .sts-scout-header h2 { font-size: 22px; }
                .sts-scout-header p { font-size: 13px; }
                .sts-header-group { width: 100%; display: flex !important; justify-content: space-between; align-items: center; }
                .sts-scout-content { padding: 15px; }
                .sts-conflict-item { flex-direction: column; align-items: flex-start; gap: 15px; }
                .sts-conflict-item button { width: 100%; }
                .sts-modal-content { width: 90% !important; margin: 20% auto; padding: 20px; }
                .sts-stat-box { padding: 10px; }
                .sts-stat-num { font-size: 22px; }
            }

            .sts-conflict-item { background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin-bottom: 12px; border-radius: 4px; display: flex; justify-content: space-between; align-items: center; border-left: 5px solid #d63638; }
            .sts-conflict-item.warning { border-left-color: #ffb900; }
            .sts-gsc-badge { background: #f0fdf4; padding: 5px 12px; border-radius: 4px; font-size: 11px; display: inline-flex; align-items: center; gap: 5px; margin-top: 10px; color: #15803d; border:1px solid #bcf0da; }
            
            .sts-stat-box { background: #f6f7f7; padding: 15px; border-radius: 4px; flex: 1; text-align: center; border: 1px solid #dcdcde; }
            .sts-stat-num { display: block; font-size: 28px; font-weight: 700; color: #d63638; }
            
            .sts-audit-modal { display:none; position:fixed; z-index:10000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.6); }
            .sts-modal-content { background:#fff; margin:10% auto; padding:30px; border-radius:12px; width:550px; box-shadow:0 20px 50px rgba(0,0,0,0.2); }
            .sts-modal-actions { display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-top:25px; }
            .sts-modal-btn { padding:15px; border:1px solid #eee; border-radius:10px; cursor:pointer; text-align:center; transition:0.3s; }
            .sts-modal-btn:hover { background:#f6f7f7; border-color:#2271b1; }
            .sts-modal-btn h4 { margin:0 0 5px 0; color:#2271b1; }
            .sts-modal-btn p { margin:0; font-size:12px; color:#666; }
            .sts-pt-label { background:#fff; padding:5px 12px; border:1px solid #ccd0d4; border-radius:4px; font-size:13px; cursor:pointer; display:inline-block; margin:0 5px 5px 0; }
            
            .sts-side-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 15px; }
            .sts-side-table th { text-align: left; background: #f6f7f7; padding: 10px; border: 1px solid #dcdcde; }
            .sts-side-table td { padding: 10px; border: 1px solid #dcdcde; vertical-align: top; }
        ");
    }

    public function render_page() {
        $current_lang = get_user_meta(get_current_user_id(), 'sts_scout_lang', true);
        if (!$current_lang) $current_lang = get_locale();
        $gsc_client_id = get_option('sts_scout_gsc_client_id', '');
        $is_authed = get_option('sts_scout_gsc_auth_code', false);
        ?>
        <div class="wrap" style="max-width: 1300px; margin: 20px auto;">
            <?php if (isset($_GET['auth']) && $_GET['auth'] === 'success') : ?>
                <div class="notice notice-success is-dismissible"><p>✅ <?php _e('Conectado ao Google com sucesso!', 'seo-cannibalization-scout'); ?></p></div>
            <?php endif; ?>

            <div class="sts-scout-card card">
                <div class="sts-scout-header">
                    <div style="display:flex; align-items:center; gap:20px;">
                        <div>
                            <h2><?php _e('SEO Cannibalization Scout', 'seo-cannibalization-scout'); ?></h2>
                            <p><?php _e('Professional URL Conflict and Content Cannibalization Detector.', 'seo-cannibalization-scout'); ?></p>
                        </div>
                        <div class="sts-help-trigger" id="open-help-modal" title="<?php _e('Help Summary', 'seo-cannibalization-scout'); ?>">?</div>
                    </div>
                    <div class="sts-header-actions">
                        <select class="sts-lang-selector" id="sts-scout-lang-switch">
                            <option value="pt_BR" <?php selected($current_lang, 'pt_BR'); ?>>🇧🇷 PT</option>
                            <option value="en_US" <?php selected($current_lang, 'en_US'); ?>>🇺🇸 EN</option>
                            <option value="es_ES" <?php selected($current_lang, 'es_ES'); ?>>🇪🇸 ES</option>
                        </select>
                    </div>
                </div>
                
                <div class="sts-scout-content">
                    <div class="sts-scout-tabs">
                        <div class="sts-tab-link active" data-tab="audit"><?php _e('Audit Dashboard', 'seo-cannibalization-scout'); ?></div>
                        <div class="sts-tab-link" data-tab="settings"><?php _e('GSC Integration', 'seo-cannibalization-scout'); ?> <?php echo $is_authed ? '✅' : ''; ?></div>
                        <div class="sts-tab-link" data-tab="about"><?php _e('Review & Support', 'seo-cannibalization-scout'); ?></div>
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
                        <div style="display:flex; align-items:center; gap:15px;">
                            <button type="button" class="button button-primary button-large" id="run-scout-btn">
                                <?php _e('Start Scan', 'seo-cannibalization-scout'); ?>
                            </button>
                            <span class="spinner" id="scout-spinner"></span>
                        </div>
                        <div id="scout-results" style="margin-top:30px;"></div>
                    </div>

                    <!-- Tab GSC -->
                    <div id="tab-settings" class="sts-tab-content">
                        <div style="display:grid; grid-template-columns: 1fr 350px; gap:40px;">
                            <div>
                                <h3><?php _e('Como conectar ao Google Search Console', 'seo-cannibalization-scout'); ?></h3>
                                <p style="color:#666; margin-bottom:30px;"><?php _e('Siga os passos abaixo para liberar os dados de cliques e impressões no seu painel.', 'seo-cannibalization-scout'); ?></p>

                                <div class="sts-guide-step" style="margin-bottom:25px;">
                                    <div style="font-weight:bold; color:#d63638; margin-bottom:5px;">1. <?php _e('Crie um projeto no Google Cloud', 'seo-cannibalization-scout'); ?></div>
                                    <p style="font-size:13px; margin:0;"><?php _e('Acesse o <a href="https://console.cloud.google.com/" target="_blank">Google Cloud Console</a> e crie um novo projeto chamado "Scout SEO".', 'seo-cannibalization-scout'); ?></p>
                                </div>

                                <div class="sts-guide-step" style="margin-bottom:25px;">
                                    <div style="font-weight:bold; color:#d63638; margin-bottom:5px;">2. <?php _e('Ative a API do Search Console', 'seo-cannibalization-scout'); ?></div>
                                    <p style="font-size:13px; margin:0;"><?php _e('No menu "APIs e Serviços", clique em "Ativar APIs" e procure por "Google Search Console API". Ative-a.', 'seo-cannibalization-scout'); ?></p>
                                </div>

                                <div class="sts-guide-step" style="margin-bottom:25px;">
                                    <div style="font-weight:bold; color:#d63638; margin-bottom:5px;">3. <?php _e('Crie as Credenciais (OAuth)', 'seo-cannibalization-scout'); ?></div>
                                    <p style="font-size:13px; margin:0;"><?php _e('Vá em "Credenciais" > "Criar Credenciais" > "ID do cliente OAuth". Escolha "Aplicativo da Web".', 'seo-cannibalization-scout'); ?></p>
                                    <div style="background:#fff9e6; border-left:3px solid #ffb900; padding:10px; margin-top:10px; font-size:12px;">
                                        <strong>URI de Redirecionamento Autorizado:</strong><br>
                                        <code style="word-break:break-all;"><?php echo admin_url('admin.php?page=seo-cannibalization-scout'); ?></code>
                                    </div>
                                </div>

                                <div class="sts-guide-step">
                                    <div style="font-weight:bold; color:#d63638; margin-bottom:5px;">4. <?php _e('Cole as Chaves e Salve', 'seo-cannibalization-scout'); ?></div>
                                    <p style="font-size:13px; margin:0;"><?php _e('Copie o "Client ID" e o "Client Secret" gerados e cole nos campos ao lado.', 'seo-cannibalization-scout'); ?></p>
                                </div>
                            </div>

                            <div style="background:#f6f7f7; padding:30px; border-radius:15px; border:1px solid #ddd; height:fit-content;">
                                <h4 style="margin-top:0;"><?php _e('Configuração das Chaves', 'seo-cannibalization-scout'); ?></h4>
                                <label style="display:block; margin-bottom:15px;">
                                    <strong>Google Client ID:</strong><br>
                                    <input type="text" id="gsc-client-id" class="regular-text" style="width:100%; font-size:11px;" value="<?php echo esc_attr($gsc_client_id); ?>">
                                </label>
                                <label style="display:block; margin-bottom:20px;">
                                    <strong>Google Client Secret:</strong><br>
                                    <input type="password" id="gsc-client-secret" class="regular-text" style="width:100%; font-size:11px;" value="********">
                                </label>
                                <button class="button button-primary button-large" style="width:100%;" id="save-gsc-btn"><?php _e('Salvar e Conectar', 'seo-cannibalization-scout'); ?></button>
                                <p style="font-size:11px; color:#666; margin-top:15px; text-align:center; line-height:1.4;">
                                    <?php _e('Ao clicar em Salvar, você será levado para o Google para autorizar o acesso.', 'seo-cannibalization-scout'); ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Tab About -->
                    <div id="tab-about" class="sts-tab-content">
                        <div style="text-align:center; padding:40px;">
                            <div style="font-size:50px; margin-bottom:20px;">⭐</div>
                            <h2><?php _e('Help us grow!', 'seo-cannibalization-scout'); ?></h2>
                            <p style="font-size:16px; color:#666; max-width:600px; margin:0 auto 30px auto;">
                                <?php _e('This plugin is 100% free and open-source. If it helped you organize your SEO, please consider leaving a 5-star review on WordPress.org.', 'seo-cannibalization-scout'); ?>
                            </p>
                            <a href="https://wordpress.org/support/plugin/seo-cannibalization-scout/reviews/#new-post" target="_blank" class="button button-primary button-large">
                                <?php _e('Leave a Review', 'seo-cannibalization-scout'); ?>
                            </a>
                            <div style="margin-top:60px; border-top:1px solid #eee; padding-top:40px;">
                                <div style="font-size:40px; margin-bottom:10px;">☕</div>
                                <h3><?php _e('Support the Project', 'seo-cannibalization-scout'); ?></h3>
                                <p style="color:#666;"><?php _e('Maintain this free tool and buy the developer a coffee!', 'seo-cannibalization-scout'); ?></p>
                                <div style="display:flex; justify-content:center; gap:10px; margin-top:20px; flex-wrap:wrap;">
                                    <a href="https://www.paypal.com/cgi-bin/webscr?cmd=_xclick&business=jucasouzabonini@gmail.com&item_name=Support+Scout&amount=2.00&currency_code=USD" target="_blank" class="button">$2.00</a>
                                    <a href="https://www.paypal.com/cgi-bin/webscr?cmd=_xclick&business=jucasouzabonini@gmail.com&item_name=Support+Scout&amount=5.00&currency_code=USD" target="_blank" class="button">$5.00</a>
                                    <a href="https://www.paypal.com/cgi-bin/webscr?cmd=_xclick&business=jucasouzabonini@gmail.com&item_name=Support+Scout&amount=10.00&currency_code=USD" target="_blank" class="button" style="background:#d63638!important;color:#fff!important;border:none;">$10.00</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modals -->
            <div id="sts-help-modal" class="sts-audit-modal">
                <div class="sts-modal-content" style="width:600px;">
                    <h3><?php _e('Resumo Comparativo', 'seo-cannibalization-scout'); ?></h3>
                    <table class="sts-side-table">
                        <tr><th>Característica</th><th>Canonical</th><th>Redirect 301</th></tr>
                        <tr><td>Post Antigo</td><td>Online</td><td>Offline</td></tr>
                        <tr><td>Poder SEO</td><td>Lento</td><td>Instataneo</td></tr>
                    </table>
                    <button onclick="jQuery('#sts-help-modal').fadeOut()" class="button button-primary" style="margin-top:30px; width:100%">Entendi!</button>
                </div>
            </div>

            <div id="sts-resolve-modal" class="sts-audit-modal">
                <div class="sts-modal-content">
                    <h3><?php _e('How to resolve?', 'seo-cannibalization-scout'); ?></h3>
                    <div class="sts-modal-actions">
                        <div class="sts-modal-btn" data-action="canonical"><h4>Authority</h4><p>Canonical</p></div>
                        <div class="sts-modal-btn" data-action="redirect"><h4>Redirect</h4><p>301</p></div>
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
                    resolve: '<?php _e('Resolve', 'seo-cannibalization-scout'); ?>',
                    bulk: '<?php _e('Bulk Resolve (Manual)', 'seo-cannibalization-scout'); ?>'
                };
                const nonce_audit = '<?php echo wp_create_nonce("cannibal_audit_nonce"); ?>';

                $('.sts-tab-link').on('click', function() {
                    $('.sts-tab-link, .sts-tab-content').removeClass('active');
                    $(this).addClass('active');
                    $('#tab-' + $(this).data('tab')).addClass('active');
                });

                $('#sts-scout-lang-switch').on('change', function() {
                    $.post(ajaxurl, { action: 'sts_cannibal_save_lang', lang: $(this).val() }, function() { location.reload(); });
                });

                $('#open-help-modal').on('click', function() { $('#sts-help-modal').fadeIn(); });

                $('#save-gsc-btn').on('click', function() {
                    const id = $('#gsc-client-id').val();
                    const secret = $('#gsc-client-secret').val();
                    if (!id || !secret) { alert('Preencha o Client ID e o Secret!'); return; }
                    
                    $.post(ajaxurl, { action: 'sts_cannibal_save_gsc', client_id: id, client_secret: secret }, function(res) {
                        if (res.success && res.data.auth_url) {
                            window.location.href = res.data.auth_url;
                        } else {
                            alert('Erro ao salvar chaves.');
                        }
                    });
                });

                $('#run-scout-btn').on('click', function() {
                    const btn = $(this); const spinner = $('#scout-spinner'); const results = $('#scout-results');
                    const selectedTypes = $('input[name="post_types[]"]:checked').map(function(){ return $(this).val(); }).get();
                    if (selectedTypes.length === 0) return;
                    btn.prop('disabled', true); spinner.addClass('is-active'); results.fadeOut();
                    $.post(ajaxurl, { action: 'sts_cannibal_run_audit', types: selectedTypes, _ajax_nonce: nonce_audit }, function(response) {
                        btn.prop('disabled', false); spinner.removeClass('is-active');
                        if (response.success) {
                            let html = '<div style="display:flex; gap:20px; margin:20px 0;">';
                            html += '<div class="sts-stat-box"><span class="sts-stat-num">' + response.data.total_posts + '</span> ' + i18n.posts + '</div>';
                            html += '<div class="sts-stat-box"><span class="sts-stat-num">' + response.data.conflicts.length + '</span> ' + i18n.conflicts + '</div>';
                            html += '</div>';
                            if (response.data.conflicts.length > 0) {
                                html += `<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                                            <h3 style="margin:0;">${i18n.found}</h3>
                                            <button class="button button-secondary" id="bulk-resolve-all">⚡ ${i18n.bulk}</button>
                                         </div>`;
                                response.data.conflicts.forEach((item, index) => {
                                    html += `<div class="sts-conflict-item" id="item-${index}"><div><strong>[${item.status}]</strong> ${item.keyword}<br><span class="sts-slug-tag">/${item.post1}/</span> vs <span class="sts-slug-tag">/${item.post2}/</span></div><button class="button button-secondary resolve-btn" data-index="${index}">${i18n.resolve}</button></div>`;
                                });
                                window.audit_items = response.data.conflicts;
                            } else { html += '<div class="notice notice-success"><p>' + i18n.none + '</p></div>'; }
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
                    if (!currentItem) return;
                    $.post(ajaxurl, { action: 'sts_cannibal_resolve_issue', type: $(this).data('action'), post_from: currentItem.id1, post_to_url: currentItem.url2, slug_from: currentItem.post1 }, function(res) {
                        if (res.success) { $(currentItem.dom_id).fadeOut(); }
                        $('#sts-resolve-modal').fadeOut();
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
        $client_id = sanitize_text_field($_POST['client_id']);
        $client_secret = sanitize_text_field($_POST['client_secret']);
        
        update_option('sts_scout_gsc_client_id', $client_id);
        update_option('sts_scout_gsc_client_secret', $client_secret);
        
        // Gerar URL de Auth do Google
        $redirect_uri = urlencode(admin_url('admin.php?page=seo-cannibalization-scout'));
        $scope = urlencode('https://www.googleapis.com/auth/webmasters.readonly');
        $auth_url = "https://accounts.google.com/o/oauth2/v2/auth?client_id={$client_id}&redirect_uri={$redirect_uri}&response_type=code&scope={$scope}&access_type=offline&prompt=consent";
        
        wp_send_json_success(['auth_url' => $auth_url]);
    }

    public function ajax_bulk_resolve() {
        if (!current_user_can('manage_options')) wp_send_json_error();
        $items = $_POST['items'];
        foreach ($items as $item) { update_post_meta((int)$item['id1'], '_sts_seo_canonical', esc_url_raw($item['url2'])); }
        wp_send_json_success();
    }

    public function ajax_resolve_issue() {
        if (!current_user_can('manage_options')) wp_send_json_error();
        $type = sanitize_text_field($_POST['type']);
        $post_from = (int) $_POST['post_from'];
        if ($type === 'canonical') { update_post_meta($post_from, '_sts_seo_canonical', esc_url_raw($_POST['post_to_url'])); } 
        elseif ($type === 'redirect' && class_exists('\STSRedirect\Core\RedirectStorage')) {
            $storage = new \STSRedirect\Core\RedirectStorage();
            $storage->add('/' . sanitize_title($_POST['slug_from']), esc_url_raw($_POST['post_to_url']), 301);
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
            $slug = get_post_field('post_name', $post_id);
            $norm = preg_replace($suffix_pattern, '', $slug);
            $norm = preg_replace('/(s|es)$/', '', $norm);
            $slugs_data[$post_id] = [ 'id' => $post_id, 'slug' => $slug, 'type' => get_post_type($post_id), 'url' => get_permalink($post_id), 'norm' => $norm ];
        }
        $ids = array_keys($slugs_data);
        for ($i = 0; $i < count($ids); $i++) {
            for ($j = $i + 1; $j < count($ids); $j++) {
                $a = $slugs_data[$ids[$i]]; $b = $slugs_data[$ids[$j]];
                if ($a['norm'] === $b['norm'] || (strlen($a['norm']) > 5 && strpos($b['norm'], $a['norm']) !== false)) {
                    $conflicts[] = [ 'keyword' => $a['norm'], 'post1' => $a['slug'], 'post2' => $b['slug'], 'type1' => $a['type'], 'id1' => $a['id'], 'url2' => $b['url'], 'status' => $a['norm']===$b['norm'] ? 'CRÍTICO' : 'ATENÇÃO' ];
                }
            }
        }
        wp_send_json_success(['total_posts' => count($posts), 'conflicts' => $conflicts]);
    }
}
