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
        add_action('admin_init', [$this, 'handle_google_auth_callback']);
    }

    public function handle_google_auth_callback() {
        if (isset($_GET['page']) && $_GET['page'] === 'seo-cannibalization-scout' && isset($_GET['code'])) {
            update_option('sts_scout_gsc_auth_code', sanitize_text_field($_GET['code']));
            delete_transient('sts_scout_gsc_data');
            wp_redirect(admin_url('admin.php?page=seo-cannibalization-scout&auth=success'));
            exit;
        }
    }

    private function get_gsc_performance_data() {
        $cached = get_transient('sts_scout_gsc_data');
        if ($cached !== false) return $cached;
        $id = get_option('sts_scout_gsc_client_id'); $sec = get_option('sts_scout_gsc_client_secret'); $code = get_option('sts_scout_gsc_auth_code');
        if (!$code || !$id || !$sec) return [];
        $resp = wp_remote_post('https://oauth2.googleapis.com/token', ['body'=>['code'=>$code, 'client_id'=>$id, 'client_secret'=>$sec, 'redirect_uri'=>admin_url('admin.php?page=seo-cannibalization-scout'), 'grant_type'=>'authorization_code']]);
        if (is_wp_error($resp)) return [];
        $token = json_decode(wp_remote_retrieve_body($resp),true)['access_token'] ?? '';
        if (!$token) return [];
        $site = urlencode(trailingslashit(home_url()));
        $perf = wp_remote_post("https://www.googleapis.com/webmasters/v3/sites/{$site}/searchAnalytics/query", ['headers'=>['Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json'],'body'=>json_encode(['startDate'=>date('Y-m-d',strtotime('-30 days')),'endDate'=>date('Y-m-d'),'dimensions'=>['page'],'rowLimit'=>5000])]);
        if (is_wp_error($perf)) return [];
        $rows = json_decode(wp_remote_retrieve_body($perf),true)['rows'] ?? [];
        $map = []; foreach($rows as $r) { 
            $map[$r['keys'][0]] = [
                'clicks' => $r['clicks'],
                'impressions' => $r['impressions'],
                'ctr' => round($r['ctr'] * 100, 2),
                'position' => round($r['position'], 1)
            ]; 
        }
        set_transient('sts_scout_gsc_data', $map, DAY_IN_SECONDS); return $map;
    }

    public function force_plugin_locale($locale, $domain) {
        if ($domain === 'seo-cannibalization-scout') {
            $user_lang = get_user_meta(get_current_user_id(), 'sts_scout_lang', true);
            if ($user_lang) return $user_lang;
        }
        return $locale;
    }

    public function add_menu_page() {
        add_menu_page(__('Cannibal Audit','seo-cannibalization-scout'), __('Cannibal Scout 🔴','seo-cannibalization-scout'), 'manage_options', 'seo-cannibalization-scout', [$this,'render_page'], 'dashicons-performance', 30);
    }

    public function enqueue_assets($hook) {
        if('toplevel_page_seo-cannibalization-scout'!==$hook) return;
        wp_add_inline_style('wp-admin', "
            .sts-scout-card { max-width:1300px; margin:20px auto; border-radius:12px; background:#fff; border:1px solid #e2e8f0; overflow:hidden; }
            .sts-scout-header { background:#d63638; color:#fff; padding:35px 45px; display:flex; justify-content:space-between; align-items:center; }
            .sts-scout-header h2 { color:#fff!important; margin:0!important; font-size:28px; }
            .sts-help-btn { background:#fff; color:#d63638; width:30px; height:30px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; cursor:pointer; font-weight:bold; margin-left:15px; vertical-align:middle; transition:0.3s; }
            .sts-help-btn:hover { background:rgba(255,255,255,0.8); }
            .sts-scout-content { padding:30px; }
            .sts-scout-tabs { display:flex; gap:10px; border-bottom:1px solid #edf2f7; margin-bottom:30px; }
            .sts-tab-nav { padding:12px 25px; cursor:pointer; font-weight:600; color:#718096; border-bottom:3px solid transparent; }
            .sts-tab-nav.active { color:#d63638; border-bottom-color:#d63638; }
            .sts-tab-panel { display:none; }
            .sts-tab-panel.active { display:block; }
            .sts-type-pill { background:#fff; border:1px solid #e2e8f0; padding:10px 18px; border-radius:8px; cursor:pointer; display:inline-flex; align-items:center; gap:8px; margin-bottom:10px; }
            .sts-conflict-card { background:#fff; border:1px solid #e2e8f0; padding:25px; margin-bottom:20px; border-radius:12px; border-left:6px solid #d63638; display:flex; justify-content:space-between; align-items:center; }
            .sts-duel-grid { display:grid; grid-template-columns:1fr auto 1fr; gap:20px; align-items:center; width:100%; }
            .sts-url-box { background:#f8fafc; padding:20px; border-radius:10px; border:1px solid #edf2f7; }
            .sts-metrics-row { display:flex; gap:10px; margin-top:12px; flex-wrap:wrap; }
            .sts-metric-tag { background:#fff; border:1px solid #e2e8f0; padding:4px 10px; border-radius:6px; font-size:11px; font-weight:700; color:#4a5568; display:flex; align-items:center; gap:5px; }
            .sts-vs-circle { background:#d63638; color:#fff; width:30px; height:30px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:bold; font-size:11px; }
            .sts-stat-card { background:#f8fafc; padding:20px; border-radius:10px; flex:1; text-align:center; border:1px solid #e2e8f0; }
            .sts-stat-val { display:block; font-size:28px; font-weight:800; color:#d63638; }
            .sts-help-modal { display:none; position:fixed; z-index:10000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.6); backdrop-filter:blur(4px); }
            .sts-modal-content { background:#fff; margin:5% auto; padding:35px; border-radius:12px; width:700px; max-width:95%; position:relative; box-shadow:0 15px 40px rgba(0,0,0,0.2); }
            .sts-choice-card { border: 2px solid #e2e8f0; border-radius: 12px; padding: 20px; transition: 0.3s; cursor: pointer; margin-bottom: 15px; position: relative; overflow: hidden; }
            .sts-choice-card:hover { border-color: #d63638; background: #fffaf0; transform: translateY(-3px); }
            .sts-choice-card.active { border-color: #d63638; background: #fffaf0; }
            .sts-choice-card h4 { margin: 0 0 5px 0; color: #1a202c; }
            .sts-choice-card p { margin: 0; font-size: 13px; color: #718096; }
            .sts-resolve-confirm { background: #d63638; color: #fff; width: 100%; border: none; padding: 15px; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 16px; margin-top: 10px; }
            .sts-resolve-confirm:disabled { opacity: 0.6; cursor: not-allowed; }
        ");
    }

    public function render_page() {
        $is_authed = get_option('sts_scout_gsc_auth_code', false);
        $current_lang = get_user_meta(get_current_user_id(), 'sts_scout_lang', true) ?: get_locale();
        
        $i18n = [
            'analyzing' => __('Analyzing Content Ecosystem...','seo-cannibalization-scout'),
            'scanning' => __('SURGICAL SCANNING...','seo-cannibalization-scout'),
            'analyzed_label' => __('Items','seo-cannibalization-scout'),
            'conflicts_label' => __('Conflicts Found','seo-cannibalization-scout'),
            'clicks_label' => __('clicks','seo-cannibalization-scout'),
            'views_label' => __('views','seo-cannibalization-scout'),
            'impress_label' => __('impressions','seo-cannibalization-scout'),
            'resolve_btn' => __('Resolve','seo-cannibalization-scout'),
            'start_action' => __('Iniciar Varredura','seo-cannibalization-scout'),
        ];
        ?>
        <div class="wrap" style="max-width:1300px; margin:20px auto;">
            <div class="sts-scout-card">
                <div class="sts-scout-header">
                    <div>
                        <h2>SEO Cannibalization Scout <div class="sts-help-btn" onclick="jQuery('#sts-help-modal').fadeIn()">?</div></h2>
                        <p><?php _e('Detector Profissional de Conflitos de URL e Canibalização de Conteúdo.','seo-cannibalization-scout');?></p>
                    </div>
                    <div class="sts-header-actions">
                        <select class="sts-lang-switch" id="sts-lang-selector">
                            <option value="pt_BR" <?php selected($current_lang,'pt_BR');?>>🇧🇷 Português</option>
                            <option value="en_US" <?php selected($current_lang,'en_US');?>>🇺🇸 English</option>
                            <option value="es_ES" <?php selected($current_lang,'es_ES');?>>🇪🇸 Español</option>
                        </select>
                    </div>
                </div>
                
                <div class="sts-scout-content">
                    <div class="sts-scout-tabs">
                        <div class="sts-tab-nav active" data-tab="audit">🔍 <?php _e('Audit Dashboard','seo-cannibalization-scout');?></div>
                        <div class="sts-tab-nav" data-tab="settings">⚙️ <?php _e('GSC Integration','seo-cannibalization-scout');?> <?php echo $is_authed?'✅':'';?></div>
                        <div class="sts-tab-nav" data-tab="support">❤️ <?php _e('Support','seo-cannibalization-scout');?></div>
                    </div>

                    <div id="panel-audit" class="sts-tab-panel active">
                        <p style="font-weight:600; margin-bottom:15px; color:#4a5568;"><?php _e('Selecione os tipos de conteúdo para auditar:','seo-cannibalization-scout');?></p>
                        <div style="display:flex; gap:10px; margin-bottom:25px; flex-wrap:wrap;">
                            <?php
                            $pts = get_post_types(['public'=>true],'objects');
                            foreach ($pts as $t) : if(in_array($t->name,['attachment','revision','nav_menu_item'])) continue;
                                $check = in_array($t->name,['post','page','web-story','receita']) ? 'checked':'';
                            ?>
                                <label class="sts-type-pill">
                                    <input type="checkbox" name="post_types[]" value="<?php echo $t->name;?>" <?php echo $check;?>> <?php echo $t->label;?>
                                </label>
                            <?php endforeach;?>
                        </div>
                        <button class="button button-primary button-hero" id="run-audit-action" style="height:45px; padding:0 30px;"><?php _e('Iniciar Varredura','seo-cannibalization-scout');?></button>
                        
                        <div id="sts-audit-loader" style="text-align:center; padding:60px; display:none;">
                            <div class="sts-loader-spinner"></div>
                            <h3><?php echo $i18n['analyzing'];?></h3>
                        </div>

                        <div id="sts-audit-results" style="margin-top:40px;"></div>
                    </div>

                    <div id="panel-settings" class="sts-tab-panel">
                        <div style="display:grid; grid-template-columns: 1fr 380px; gap:40px;">
                            <div>
                                <h3 style="margin-top:0;"><?php _e('Como conectar ao Google Search Console','seo-cannibalization-scout');?></h3>
                                <p><?php _e('Siga os passos para liberar os dados de cliques e impressões.','seo-cannibalization-scout');?></p>
                                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:20px; margin-bottom:15px;">
                                    <h4 style="margin-top:0;">1. Google Cloud Console</h4>
                                    <p><?php _e('Crie um projeto "Scout SEO".','seo-cannibalization-scout');?></p>
                                </div>
                                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:20px; margin-bottom:15px;">
                                    <h4 style="margin-top:0;">2. Ative as APIs</h4>
                                    <p><?php _e('Ative a "Google Search Console API".','seo-cannibalization-scout');?></p>
                                </div>
                                <div style="background:#fffaf0; border:1px solid #fbd38d; border-radius:10px; padding:20px;">
                                    <h4 style="margin-top:0; color:#975a16;">3. OAuth Redirect URI</h4>
                                    <code style="word-break:break-all; display:block; padding:10px; background:#fff; border:1px solid #e2e8f0;"><?php echo admin_url('admin.php?page=seo-cannibalization-scout');?></code>
                                </div>
                            </div>
                            <div style="background:#f8fafc; padding:35px; border-radius:15px; border:1px solid #e2e8f0;">
                                <label><strong>Client ID:</strong><br><input type="text" id="gsc-client-id" style="width:100%" value="<?php echo esc_attr(get_option('sts_scout_gsc_client_id'));?>"></label><br><br>
                                <label><strong>Client Secret:</strong><br><input type="password" id="gsc-client-secret" style="width:100%" value="********"></label><br><br>
                                <button class="button button-primary button-large" style="width:100%; height:45px;" id="save-gsc-config"><?php _e('Save','seo-cannibalization-scout');?></button>
                            </div>
                        </div>
                    </div>

                    <div id="panel-support" class="sts-tab-panel">
                        <div style="text-align:center; padding:60px 0;">
                             <h2 style="font-size:28px;"><?php _e('Support & Donations','seo-cannibalization-scout');?></h2>
                             <div style="display:flex; justify-content:center; gap:20px; margin:30px 0;">
                                 <a href="https://www.paypal.com/cgi-bin/webscr?cmd=_xclick&business=jucasouzabonini@gmail.com&amount=2.00" target="_blank" class="button button-hero">$2.00</a>
                                 <a href="https://www.paypal.com/cgi-bin/webscr?cmd=_xclick&business=jucasouzabonini@gmail.com&amount=5.00" target="_blank" class="button button-hero button-primary">$5.00</a>
                                 <a href="https://www.paypal.com/cgi-bin/webscr?cmd=_xclick&business=jucasouzabonini@gmail.com&amount=10.00" target="_blank" class="button button-hero" style="background:#111!important; color:#fff!important;">$10.00</a>
                             </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="sts-help-modal" class="sts-help-modal">
                <div class="sts-modal-content">
                    <h3 style="border-bottom:3px solid #d63638; padding-bottom:15px; margin-top:0;">🛡️ <?php _e('Centro de Estratégia Scout','seo-cannibalization-scout');?></h3>
                    <p style="font-size:15px; line-height:1.6; color:#4a5568;">
                        <?php _e('Compare Cliques e Visualizações para decidir qual URL manter.','seo-cannibalization-scout');?><br>
                        <strong><?php _e('Dica de Mestre','seo-cannibalization-scout');?>:</strong> <?php _e('Se uma URL tem muito mais cliques, ela deve ser a sua URL Master.','seo-cannibalization-scout');?>
                    </p>
                    <div style="background:#f8fafc; padding:20px; border-radius:10px; margin:20px 0; border:1px solid #e2e8f0;">
                        <h4 style="margin-top:0; color:#d63638;"><?php _e('Como deseja resolver?','seo-cannibalization-scout');?></h4>
                        <p><strong><?php _e('Autoridade (Canonical)','seo-cannibalization-scout');?>:</strong> <?php _e('Mantenha ambas URLs online, mas aponte a força do SEO para a principal. Use quando o conteúdo for útil mas repetido.','seo-cannibalization-scout');?></p>
                        <p><strong><?php _e('Redirecionamento (301)','seo-cannibalization-scout');?>:</strong> <?php _e('Envio automático do usuário para a Master. Use para exterminar páginas de baixa qualidade e consolidar autoridade.','seo-cannibalization-scout');?></p>
                    </div>
                    <button onclick="jQuery('#sts-help-modal').fadeOut()" class="button button-primary button-large" style="width:100%; height:45px; font-weight:bold;"><?php _e('Entendi, vamos decolar!','seo-cannibalization-scout');?></button>
                </div>
            </div>

            <!-- NOVO MODAL DE RESOLUÇÃO PREMIUM -->
            <div id="sts-resolve-modal" class="sts-help-modal">
                <div class="sts-modal-content" style="width: 550px;">
                    <div style="text-align: right; margin-bottom: -10px;">
                        <span onclick="jQuery('#sts-resolve-modal').fadeOut()" style="cursor:pointer; font-size:20px; color:#cbd5e0;">&times;</span>
                    </div>
                    <h3 style="margin-top:0; color:#1a202c;"><?php _e('Blindar Autoridade','seo-cannibalization-scout');?></h3>
                    <p style="color:#718096; margin-bottom:25px;"><?php _e('Escolha o método de aniquilação da canibalização:','seo-cannibalization-scout');?></p>
                    
                    <div class="sts-choice-card" data-type="canonical">
                        <div style="display:flex; gap:15px; align-items:center;">
                            <div style="background:#ebf8ff; color:#3182ce; padding:10px; border-radius:10px;">🛡️</div>
                            <div>
                                <h4><?php _e('Poder da Autoridade (Canonical)','seo-cannibalization-scout');?></h4>
                                <p><?php _e('Une a força das duas URLs sem apagar nada. Seguro e elegante.','seo-cannibalization-scout');?></p>
                            </div>
                        </div>
                    </div>

                    <div class="sts-choice-card" data-type="redirect">
                        <div style="display:flex; gap:15px; align-items:center;">
                            <div style="background:#fff5f5; color:#e53e3e; padding:10px; border-radius:10px;">⚔️</div>
                            <div>
                                <h4><?php _e('Modo Aniquilação (Redirect 301)','seo-cannibalization-scout');?></h4>
                                <p><?php _e('Mata a página fraca e manda tudo para a Master. 100% garantido.','seo-cannibalization-scout');?></p>
                            </div>
                        </div>
                    </div>

                    <button id="sts-confirm-resolve-btn" class="sts-resolve-confirm" style="display:none; transition: 0.3s;"><?php _e('EXECUTAR RESOLUÇÃO','seo-cannibalization-scout');?></button>
                </div>
            </div>

            <script>
            const sts_i18n = <?php echo json_encode($i18n); ?>;
            jQuery(document).ready(function($) {
                $('.sts-tab-nav').on('click', function() {
                    $('.sts-tab-nav, .sts-tab-panel').removeClass('active');
                    $(this).addClass('active'); $('#panel-'+$(this).data('tab')).addClass('active');
                });
                $('#sts-lang-selector').on('change', function() {
                    $.post(ajaxurl, {action:'sts_cannibal_save_lang', lang:$(this).val()}, function(){ location.reload(); });
                });
                $('#save-gsc-config').on('click', function() {
                    const btn = $(this);
                    btn.prop('disabled', true).text('Saving...');
                    $.post(ajaxurl,{action:'sts_cannibal_save_gsc',client_id:$('#gsc-client-id').val(),client_secret:$('#gsc-client-secret').val()},function(res){
                        if(res.success) window.location.href=res.data.auth_url;
                        else { alert('Error saving config.'); btn.prop('disabled', false).text('Save'); }
                    });
                });

            // Função MODO DEUS de Resolução
                let currentConflictIndex = null;
                let currentBtnElem = null;

                window.stsResolveConflict = function(index, btnElem) {
                    currentConflictIndex = index;
                    currentBtnElem = btnElem;
                    $('#sts-resolve-modal').fadeIn();
                };

                window.stsExecuteResolution = function(type) {
                    const conflict = window.scout_conflicts[currentConflictIndex];
                    const $btn = $(currentBtnElem);
                    const $resolveBtn = $('#sts-confirm-resolve-btn');
                    
                    $resolveBtn.prop('disabled', true).html('<span class="spinner is-active" style="float:none;"></span> Processando...');

                    $.post(ajaxurl, {
                        action: 'sts_cannibal_resolve_issue',
                        _ajax_nonce: '<?php echo wp_create_nonce("cannibal_resolve_nonce"); ?>',
                        post_from: conflict.id1,
                        post_to_url: conflict.url2,
                        resolve_type: type
                    }, function(res) {
                        $('#sts-resolve-modal').fadeOut();
                        $resolveBtn.prop('disabled', false).text('EXECUTAR RESOLUÇÃO');
                        
                        if (res.success) {
                            $btn.closest('.sts-conflict-card').css('border-left-color', type === 'redirect' ? '#4a5568' : '#22c55e');
                            $btn.closest('.sts-conflict-card').fadeOut(800, function() {
                                $(this).remove();
                                const $count = $('.sts-stat-val').last();
                                $count.text(parseInt($count.text()) - 1);
                                if (parseInt($count.text()) === 0) {
                                    $('#sts-audit-results').html('<div style="text-align:center; padding:40px;"><h2 style="color:#22c55e;">🛡️ ESTRUTURA BLINDADA! Todos os conflitos foram aniquilados.</h2></div>');
                                }
                            });
                        } else {
                            alert('Erro: ' + (res.data || 'Falha na comunicação.'));
                        }
                    });
                };

                $('.sts-choice-card').on('click', function() {
                    $('.sts-choice-card').removeClass('active');
                    $(this).addClass('active');
                    $('#sts-confirm-resolve-btn').data('type', $(this).data('type')).fadeIn();
                });

                $('#sts-confirm-resolve-btn').on('click', function() {
                    window.stsExecuteResolution($(this).data('type'));
                });

                $('#run-audit-action').on('click', function() {
                    const btn=$(this); const loader=$('#sts-audit-loader'); const res_div=$('#sts-audit-results');
                    const types=$('input[name="post_types[]"]:checked').map(function(){return $(this).val();}).get();
                    
                    if (types.length === 0) { alert('Selecione ao menos um tipo de conteúdo.'); return; }
                    
                    btn.prop('disabled',true).text(sts_i18n.scanning); res_div.fadeOut(); loader.fadeIn();
                    
                    $.post(ajaxurl,{action:'sts_cannibal_run_audit',types:types,_ajax_nonce:'<?php echo wp_create_nonce("cannibal_audit_nonce"); ?>'},function(res){
                        btn.prop('disabled',false).html('🚀 ' + sts_i18n.start_action);
                        loader.fadeOut(function(){
                            if(res.success){
                                let h=`<div style='display:flex;gap:20px;margin-bottom:30px;'><div class='sts-stat-card'><span class='sts-stat-val'>${res.data.total_posts}</span> ${sts_i18n.analyzed_label}</div><div class='sts-stat-card'><span class='sts-stat-val'>${res.data.conflicts.length}</span> ${sts_i18n.conflicts_label}</div></div>`;
                                res.data.conflicts.forEach((item,index)=>{
                                    h+=`<div class='sts-conflict-card' id='conflict-${index}'>
                                        <div class='sts-duel-grid'>
                                            <div class='sts-url-box' style='${item.gsc1.clicks < item.gsc2.clicks ? 'opacity:0.6' : 'border:2px solid #d63638; box-shadow: 0 0 10px rgba(214, 54, 56, 0.1);'}'>
                                                <span class='sts-badge' style='background:#e2e8f0; color:#4a5568; font-size:9px; padding:2px 6px; border-radius:4px; text-transform:uppercase;'>${item.type1}</span><br><strong>/${item.post1}/</strong>
                                                <div class='sts-metrics-row'>
                                                    <div class='sts-metric-tag' title='Cliques no último mês'>🖱️ ${item.gsc1.clicks}</div>
                                                    <div class='sts-metric-tag' title='Impressões no Search Console'>👁️ ${item.gsc1.impressions}</div>
                                                </div>
                                            </div>
                                            <div class='sts-vs-circle' style='box-shadow: 0 4px 10px rgba(214, 54, 56, 0.3);'>VS</div>
                                            <div class='sts-url-box' style='${item.gsc2.clicks < item.gsc1.clicks ? 'opacity:0.6' : 'border:2px solid #d63638; box-shadow: 0 0 10px rgba(214, 54, 56, 0.1);'}'>
                                                <span class='sts-badge' style='background:#e2e8f0; color:#4a5568; font-size:9px; padding:2px 6px; border-radius:4px; text-transform:uppercase;'>${item.type2}</span><br><strong>/${item.post2}/</strong>
                                                <div class='sts-metrics-row'>
                                                    <div class='sts-metric-tag'>🖱️ ${item.gsc2.clicks}</div>
                                                    <div class='sts-metric-tag'>👁️ ${item.gsc2.impressions}</div>
                                                </div>
                                            </div>
                                        </div>
                                        <button class='button button-primary' onclick='stsResolveConflict(${index}, this)' style='margin-left:20px; font-weight:700;'>${sts_i18n.resolve_btn}</button>
                                    </div>`;
                                });
                                res_div.html(h).fadeIn(); window.scout_conflicts=res.data.conflicts;
                            }
                        });
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
        update_option('sts_scout_gsc_client_id', sanitize_text_field($_POST['client_id']));
        update_option('sts_scout_gsc_client_secret', sanitize_text_field($_POST['client_secret']));
        $id = get_option('sts_scout_gsc_client_id'); $red = urlencode(admin_url('admin.php?page=seo-cannibalization-scout'));
        $url = "https://accounts.google.com/o/oauth2/v2/auth?client_id={$id}&redirect_uri={$red}&response_type=code&scope=".urlencode('https://www.googleapis.com/auth/webmasters.readonly')."&access_type=offline&prompt=consent";
        wp_send_json_success(['auth_url'=>$url]);
    }

    /**
     * Termos irrelevantes que diluem o SEO e causam canibalização falsa
     */
    private function get_stop_words() {
        return [
            'aprenda-a-fazer','como-fazer','o-melhor','a-melhor','receita-de','receita','facil','simples',
            'caseiro','economico','rápido','rapido','super','passo-a-passo','dicas','segredo',
            'perfeito','inesquecível','inesquecivel','delicioso','deliciosa','com','para'
        ];
    }

    /**
     * Normalização profunda para encontrar a intenção de busca real
     */
    private function deep_normalize($slug) {
        $stops = $this->get_stop_words();
        $slug = str_replace($stops, '', $slug);
        $slug = preg_replace('/-+/', '-', trim($slug, '-'));
        // Remove plural básico
        $slug = preg_replace('/(s|es)$/', '', $slug);
        return $slug;
    }

    public function ajax_run_audit() {
        check_ajax_referer('cannibal_audit_nonce');
        $ts = $_POST['types']??['post','page','receita'];
        $ps = get_posts(['post_type'=>$ts,'posts_per_page'=>-1,'post_status'=>'publish','fields'=>'ids']);
        $gsc = $this->get_gsc_performance_data();
        
        $conf = [];
        $posts_data = [];
        
        // Coleta e normaliza todos os posts
        foreach($ps as $pid) {
            if(get_post_meta($pid,'_sts_seo_canonical',true)) continue;
            if(get_post_meta($pid,'_sts_seo_redirect',true)) continue;

            $slug = get_post_field('post_name',$pid);
            $url = get_permalink($pid);
            $norm = $this->deep_normalize($slug);

            $posts_data[] = [
                'id' => $pid,
                'slug' => $slug,
                'norm' => $norm,
                'type' => get_post_type($pid),
                'url' => $url,
                'gsc' => $gsc[$url] ?? ['clicks'=>0,'impressions'=>0]
            ];
        }

        // Comparação Cruzada de Similaridade (MODO GOD)
        $processed = [];
        for ($i = 0; $i < count($posts_data); $i++) {
            if (in_array($i, $processed)) continue;
            
            $current_group = [$posts_data[$i]];
            $processed[] = $i;

            for ($j = $i + 1; $j < count($posts_data); $j++) {
                if (in_array($j, $processed)) continue;

                // Teste 1: Igualdade após normalização profunda
                if ($posts_data[$i]['norm'] === $posts_data[$j]['norm']) {
                    $current_group[] = $posts_data[$j];
                    $processed[] = $j;
                    continue;
                }

                // Teste 2: Similaridade de Levenshtein (Fuzzy Match)
                $sim = 0;
                similar_text($posts_data[$i]['norm'], $posts_data[$j]['norm'], $sim);
                if ($sim > 85) {
                    $current_group[] = $posts_data[$j];
                    $processed[] = $j;
                }
            }

            if (count($current_group) > 1) {
                // Ordena por performance (Master é quem tem mais cliques)
                usort($current_group, function($a, $b) {
                    return ($b['gsc']['clicks'] ?? 0) - ($a['gsc']['clicks'] ?? 0);
                });

                $master = $current_group[0];
                for ($k = 1; $k < count($current_group); $k++) {
                    $slave = $current_group[$k];
                    $conf[] = [
                        'id1' => $slave['id'],
                        'post1' => $slave['slug'],
                        'type1' => $slave['type'],
                        'gsc1' => $slave['gsc'],
                        'id2' => $master['id'], // Adicionado ID da master para facilitar a resolução
                        'post2' => $master['slug'],
                        'type2' => $master['type'],
                        'gsc2' => $master['gsc'],
                        'url2' => $master['url']
                    ];
                }
            }
        }

        wp_send_json_success(['total_posts'=>count($ps), 'conflicts'=>$conf]);
    }

    public function ajax_resolve_issue() {
        check_ajax_referer('cannibal_resolve_nonce');
        
        $post_from = isset($_POST['post_from']) ? (int)$_POST['post_from'] : 0;
        $post_to_url = isset($_POST['post_to_url']) ? esc_url_raw($_POST['post_to_url']) : '';
        $type = isset($_POST['resolve_type']) ? sanitize_text_field($_POST['resolve_type']) : 'canonical';

        if (!$post_from || !$post_to_url) {
            wp_send_json_error('Parâmetros inválidos.');
        }

        if ($type === 'redirect') {
            update_post_meta($post_from, '_sts_seo_redirect', $post_to_url);
            delete_post_meta($post_from, '_sts_seo_canonical');
            // Opcional: Mudar status para draft ou manter publish? 
            // Recomendado manter publish para o 301 funcionar melhor com indexação
        } else {
            update_post_meta($post_from, '_sts_seo_canonical', $post_to_url);
            delete_post_meta($post_from, '_sts_seo_redirect');
        }

        wp_send_json_success();
    }

}
