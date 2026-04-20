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
        $map = []; foreach($rows as $r) { $map[$r['keys'][0]] = ['clicks'=>$r['clicks'],'impressions'=>$r['impressions']]; }
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
            .sts-scout-card { max-width:1300px; margin:20px auto; border-radius:8px; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,0.1); border:1px solid #ccd0d4; overflow:hidden; }
            .sts-scout-header { background:#d63638; color:#fff; padding:30px 40px; display:flex; justify-content:space-between; align-items:center; }
            .sts-scout-header h2 { color:#fff!important; margin:0!important; font-size:26px; }
            .sts-help-trigger { background:rgba(255,255,255,0.2); color:#fff; width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; font-weight:bold; font-size:18px; border:1px solid rgba(255,255,255,0.3); }
            .sts-scout-content { padding:30px; }
            .sts-scout-tabs { display:flex; gap:5px; border-bottom:1px solid #ddd; margin-bottom:20px; }
            .sts-tab-link { padding:10px 20px; cursor:pointer; font-weight:600; color:#666; border:1px solid transparent; border-bottom:none; border-radius:5px 5px 0 0; margin-bottom:-1px; }
            .sts-tab-link.active { background:#fff; border-color:#ddd; color:#d63638; }
            .sts-tab-content { display:none; }
            .sts-tab-content.active { display:block; }
            .sts-conflict-item { background:#fff; border:1px solid #ccd0d4; padding:25px; margin-bottom:15px; border-radius:8px; border-left:6px solid #d63638; display:flex; justify-content:space-between; align-items:center; }
            .sts-conflict-grid { display:grid; grid-template-columns:1fr auto 1fr; gap:15px; align-items:center; width:100%; }
            .sts-url-unit { background:#f9fafa; padding:15px; border-radius:8px; border:1px solid #eee; position:relative; }
            .sts-type-tag { font-size:9px; text-transform:uppercase; font-weight:bold; padding:2px 6px; border-radius:4px; margin-bottom:10px; display:inline-block; }
            .sts-type-post { background:#e0f2fe; color:#0369a1; }
            .sts-type-story { background:#fef3c7; color:#92400e; }
            .sts-gsc-stats { display:flex; gap:10px; margin-top:10px; }
            .sts-mini-stat { background:#fff; border:1px solid #ddd; padding:3px 8px; border-radius:4px; font-size:11px; display:inline-flex; align-items:center; gap:4px; }
            .sts-vs-icon { background:#d63638; color:#fff; width:28px; height:28px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:bold; font-size:10px; }
            .sts-stat-box { background:#f6f7f7; padding:15px; border-radius:4px; flex:1; text-align:center; border:1px solid #dcdcde; }
            .sts-stat-num { display:block; font-size:26px; font-weight:700; color:#d63638; }
            .sts-loading-area { text-align:center; padding:50px; display:none; }
            .sts-spinner { border: 4px solid #f3f3f3; border-top: 4px solid #d63638; border-radius: 50%; width: 35px; height: 35px; animation: spin 1s linear infinite; margin: 0 auto 20px; }
            @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
            .sts-tuto-step { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:25px; margin-bottom:20px; position:relative; }
            .sts-tuto-num { background:#d63638; color:#fff; width:28px; height:28px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:bold; margin-bottom:15px; }
            .sts-audit-modal { display:none; position:fixed; z-index:10000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.6); }
            .sts-modal-content { background:#fff; margin:10% auto; padding:35px; border-radius:12px; width:650px; max-width:90%; position:relative; }
        ");
    }

    public function render_page() {
        $is_authed = get_option('sts_scout_gsc_auth_code', false);
        ?>
        <div class="wrap" style="max-width:1300px; margin:20px auto;">
            <div class="sts-scout-card card">
                <div class="sts-scout-header">
                    <div style="display:flex; align-items:center; gap:15px;">
                        <div><h2>SEO Cannibalization Scout</h2><p>Ultimate SEO Authority Auditor</p></div>
                        <div class="sts-help-trigger" onclick="jQuery('#sts-help-modal').fadeIn()">?</div>
                    </div>
                </div>
                
                <div class="sts-scout-content">
                    <div class="sts-scout-tabs">
                        <div class="sts-tab-link active" data-tab="audit">Audit Dashboard</div>
                        <div class="sts-tab-link" data-tab="settings">GSC Integration <?php echo $is_authed?'✅':'';?></div>
                        <div class="sts-tab-link" data-tab="support">Support</div>
                    </div>

                    <div id="tab-audit" class="sts-tab-content active">
                        <p><strong>Select content types:</strong></p>
                        <div style="display:flex; gap:10px; margin-bottom:20px;">
                            <?php foreach (['post','page','web-story'] as $pt) : ?>
                                <label style="background:#fff; padding:8px 15px; border:1px solid #ddd; border-radius:5px; cursor:pointer;">
                                    <input type="checkbox" name="post_types[]" value="<?php echo $pt;?>" <?php checked(in_array($pt,['post','page']));?>> <?php echo ucfirst($pt);?>
                                </label>
                            <?php endforeach;?>
                        </div>
                        <button class="button button-primary button-large" id="run-scout-btn">🚀 Start Surgical Scan</button>
                        <div id="sts-loader" class="sts-loading-area"><div class="sts-spinner"></div><h3>Scout está trabalhando...</h3><p>Cruzando dados de Cliques e Visualizações.</p></div>
                        <div id="scout-results" style="margin-top:30px;"></div>
                    </div>

                    <div id="tab-settings" class="sts-tab-content">
                        <!-- Tutorial GSC mantido v1.6.6 -->
                        <div style="display:grid; grid-template-columns: 1fr 350px; gap:40px;">
                            <div>
                                <h3 style="margin-top:0;">🏁 Guia Completo de Configuração Google</h3>
                                <div class="sts-tuto-step"><div class="sts-tuto-num">1</div><h4>Projeto no Cloud</h4><p>Crie um projeto no <a href="https://console.cloud.google.com/" target="_blank">Google Cloud</a> chamado "Scout SEO".</p></div>
                                <div class="sts-tuto-step"><div class="sts-tuto-num">2</div><h4>Ativar API</h4><p>Pesquise e ative a <b>"Google Search Console API"</b>.</p></div>
                                <div class="sts-tuto-step" style="border-color:#fbbf24; background:#fffdf5;"><div class="sts-tuto-num" style="background:#fbbf24;">3</div><h4>Redirect URI</h4><code><?php echo admin_url('admin.php?page=seo-cannibalization-scout');?></code></div>
                            </div>
                            <div style="background:#f8fafc; padding:25px; border-radius:12px; border:1px solid #e2e8f0; height:fit-content;">
                                <label><strong>Client ID:</strong><br><input type="text" id="gsc-client-id" style="width:100%" value="<?php echo esc_attr(get_option('sts_scout_gsc_client_id'));?>"></label><br><br>
                                <label><strong>Client Secret:</strong><br><input type="password" id="gsc-client-secret" style="width:100%" value="********"></label><br><br>
                                <button class="button button-primary" style="width:100%" id="save-gsc-btn">🚀 Salvar e Autorizar</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="sts-help-modal" class="sts-audit-modal">
                <div class="sts-modal-content">
                    <h3>🛡️ Ajuda do Scout</h3>
                    <p>Compare Cliques e Visualizações para decidir qual URL manter.</p>
                    <button onclick="jQuery('#sts-help-modal').fadeOut()" class="button button-primary" style="width:100%">Fechar</button>
                </div>
            </div>

            <script>
            jQuery(document).ready(function($) {
                $('.sts-tab-link').on('click', function() {
                    $('.sts-tab-link, .sts-tab-content').removeClass('active');
                    $(this).addClass('active'); $('#tab-'+$(this).data('tab')).addClass('active');
                });
                $('#save-gsc-btn').on('click', function() {
                    $.post(ajaxurl,{action:'sts_cannibal_save_gsc',client_id:$('#gsc-client-id').val(),client_secret:$('#gsc-client-secret').val()},function(res){
                        if(res.success) window.location.href=res.data.auth_url;
                    });
                });
                $('#run-scout-btn').on('click', function() {
                    const btn = $(this); const loader = $('#sts-loader'); const results = $('#scout-results');
                    const types = $('input[name="post_types[]"]:checked').map(function(){ return $(this).val(); }).get();
                    btn.prop('disabled', true).text('⏳ Scaneando...'); results.fadeOut(); loader.fadeIn();
                    $.post(ajaxurl, {action:'sts_cannibal_run_audit', types:types, _ajax_nonce:'<?php echo wp_create_nonce("cannibal_audit_nonce"); ?>'}, function(res) {
                        btn.prop('disabled', false).html('🚀 Start Surgical Scan');
                        loader.fadeOut(function() {
                            if(res.success) {
                                let h = `<div style='display:flex;gap:20px;margin-bottom:30px;'><div class='sts-stat-box'><span class='sts-stat-num'>${res.data.total_posts}</span> Items</div><div class='sts-stat-box'><span class='sts-stat-num'>${res.data.conflicts.length}</span> Conflicts</div></div>`;
                                res.data.conflicts.forEach((item, index) => {
                                    h += `<div class='sts-conflict-item' id='item-${index}'>
                                        <div class='sts-conflict-grid'>
                                            <div class='sts-url-unit' style='${item.gsc1.clicks < item.gsc2.clicks ? 'opacity:0.6' : 'border:2px solid #d63638'}'>
                                                <span class='sts-type-tag sts-type-${item.type1}'>${item.type1}</span><br><strong>/${item.post1}/</strong>
                                                <div class='sts-gsc-stats'>
                                                    <div class='sts-mini-stat'>🖱️ ${item.gsc1.clicks} clicks</div>
                                                    <div class='sts-mini-stat'>👁️ ${item.gsc1.impressions} views</div>
                                                </div>
                                            </div>
                                            <div class='sts-vs-icon'>VS</div>
                                            <div class='sts-url-unit' style='${item.gsc2.clicks < item.gsc1.clicks ? 'opacity:0.6' : 'border:2px solid #d63638'}'>
                                                <span class='sts-type-tag sts-type-${item.type2}'>${item.type2}</span><br><strong>/${item.post2}/</strong>
                                                <div class='sts-gsc-stats'>
                                                    <div class='sts-mini-stat'>🖱️ ${item.gsc2.clicks} clicks</div>
                                                    <div class='sts-mini-stat'>👁️ ${item.gsc2.impressions} views</div>
                                                </div>
                                            </div>
                                        </div>
                                        <button class='button resolve-btn' data-index='${index}' style='margin-left:15px;'>Resolve</button>
                                    </div>`;
                                });
                                results.html(h).fadeIn(); window.audit_items=res.data.conflicts;
                            }
                        });
                    });
                });
            });
            </script>
        </div>
        <?php
    }

    public function ajax_save_gsc() {
        update_option('sts_scout_gsc_client_id', sanitize_text_field($_POST['client_id']));
        update_option('sts_scout_gsc_client_secret', sanitize_text_field($_POST['client_secret']));
        $id = get_option('sts_scout_gsc_client_id'); $red = urlencode(admin_url('admin.php?page=seo-cannibalization-scout'));
        $url = "https://accounts.google.com/o/oauth2/v2/auth?client_id={$id}&redirect_uri={$red}&response_type=code&scope=".urlencode('https://www.googleapis.com/auth/webmasters.readonly')."&access_type=offline&prompt=consent";
        wp_send_json_success(['auth_url'=>$url]);
    }

    public function ajax_run_audit() {
        check_ajax_referer('cannibal_audit_nonce');
        $ts = $_POST['types']??['post','page'];
        $ps = get_posts(['post_type'=>$ts,'posts_per_page'=>-1,'post_status'=>'publish','fields'=>'ids']);
        $gsc = $this->get_gsc_performance_data();
        $conf=[]; $map=[]; $pat='/-(receita|facil|caseiro)$/';
        foreach($ps as $pid) {
             if(get_post_meta($pid,'_sts_seo_canonical',true)) continue;
             $slug = get_post_field('post_name',$pid); $url=get_permalink($pid);
             $norm = preg_replace($pat,'',$slug); $norm = preg_replace('/(s|es)$/','',$norm);
             $map[$norm][] = ['id'=>$pid,'slug'=>$slug,'type'=>get_post_type($pid),'url'=>$url,'gsc'=>$gsc[$url]??['clicks'=>0,'impressions'=>0]];
        }
        foreach($map as $k=>$items) {
             if(count($items)>1) {
                 usort($items, function($a,$b){ return $b['gsc']['clicks'] - $a['gsc']['clicks']; });
                 for($i=1;$i<count($items);$i++) {
                     $conf[] = ['id1'=>$items[$i]['id'],'post1'=>$items[$i]['slug'],'type1'=>$items[$i]['type'],'gsc1'=>$items[$i]['gsc'],'post2'=>$items[0]['slug'],'type2'=>$items[0]['type'],'gsc2'=>$items[0]['gsc'],'url2'=>$items[0]['url']];
                 }
             }
        }
        wp_send_json_success(['total_posts'=>count($ps),'conflicts'=>$conf]);
    }

    public function ajax_resolve_issue() {
        update_post_meta((int)$_POST['post_from'],'_sts_seo_canonical',esc_url_raw($_POST['post_to_url']));
        wp_send_json_success();
    }
}
