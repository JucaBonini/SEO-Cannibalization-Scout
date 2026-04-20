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
            .sts-scout-header p { color:rgba(255,255,255,0.8); margin:5px 0 0 0; font-size:13px; }
            .sts-help-trigger { background:rgba(255,255,255,0.2); color:#fff; width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; font-weight:bold; font-size:18px; border:1px solid rgba(255,255,255,0.3); }
            .sts-scout-content { padding:30px; }
            .sts-scout-tabs { display:flex; gap:5px; border-bottom:1px solid #ddd; margin-bottom:20px; }
            .sts-tab-link { padding:10px 20px; cursor:pointer; font-weight:600; color:#666; border:1px solid transparent; border-bottom:none; border-radius:5px 5px 0 0; margin-bottom:-1px; }
            .sts-tab-link.active { background:#fff; border-color:#ddd; color:#d63638; }
            .sts-tab-content { display:none; }
            .sts-tab-content.active { display:block; }
            .sts-conflict-item { background:#fff; border:1px solid #ccd0d4; padding:25px; margin-bottom:15px; border-radius:8px; border-left:6px solid #d63638; display:flex; justify-content:space-between; align-items:center; }
            .sts-conflict-grid { display:grid; grid-template-columns:1fr auto 1fr; gap:20px; align-items:center; width:100%; }
            .sts-url-unit { background:#f9fafa; padding:15px; border-radius:8px; border:1px solid #eee; }
            .sts-type-tag { font-size:10px; text-transform:uppercase; font-weight:bold; padding:2px 6px; border-radius:4px; margin-bottom:8px; display:inline-block; }
            .sts-type-post { background:#e0f2fe; color:#0369a1; }
            .sts-type-story { background:#fef3c7; color:#92400e; }
            .sts-vs-icon { background:#d63638; color:#fff; width:30px; height:30px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:bold; font-size:12px; }
            .sts-stat-box { background:#f6f7f7; padding:15px; border-radius:4px; flex:1; text-align:center; border:1px solid #dcdcde; }
            .sts-stat-num { display:block; font-size:28px; font-weight:700; color:#d63638; }
            .sts-loading-area { text-align:center; padding:50px; display:none; }
            .sts-spinner { border: 4px solid #f3f3f3; border-top: 4px solid #d63638; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 0 auto 20px; }
            @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
            .sts-step-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:15px; position:relative; }
            .sts-step-num { position:absolute; left:-12px; top:15px; background:#d63638; color:#fff; width:24px; height:24px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:bold; }
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
                        <div>
                            <h2>SEO Cannibalization Scout</h2>
                            <p>Ultimate SEO Authority Auditor</p>
                        </div>
                        <div class="sts-help-trigger" onclick="jQuery('#sts-help-modal').fadeIn()">?</div>
                    </div>
                </div>
                
                <div class="sts-scout-content">
                    <div class="sts-scout-tabs">
                        <div class="sts-tab-link active" data-tab="audit"><?php _e('Audit Dashboard','seo-cannibalization-scout');?></div>
                        <div class="sts-tab-link" data-tab="settings"><?php _e('GSC Integration','seo-cannibalization-scout');?> <?php echo $is_authed?'✅':'';?></div>
                        <div class="sts-tab-link" data-tab="support"><?php _e('Support','seo-cannibalization-scout');?></div>
                    </div>

                    <div id="tab-audit" class="sts-tab-content active">
                        <p><strong><?php _e('Select content types:','seo-cannibalization-scout');?></strong></p>
                        <div style="display:flex; gap:10px; margin-bottom:20px; flex-wrap:wrap;">
                            <?php
                            $pts = get_post_types(['public'=>true],'objects');
                            foreach ($pts as $t) : if(in_array($t->name,['attachment','revision','nav_menu_item'])) continue;
                                $check = in_array($t->name,['post','page']) ? 'checked':'';
                            ?>
                                <label style="background:#fff; padding:8px 15px; border:1px solid #ddd; border-radius:5px; cursor:pointer;">
                                    <input type="checkbox" name="post_types[]" value="<?php echo $t->name;?>" <?php echo $check;?>> <?php echo $t->label;?>
                                </label>
                            <?php endforeach;?>
                        </div>
                        <button class="button button-primary button-large" id="run-scout-btn">🚀 Start Surgical Scan</button>
                        
                        <div id="sts-loader" class="sts-loading-area">
                            <div class="sts-spinner"></div>
                            <h3 style="margin-bottom:5px;">Scout está trabalhando...</h3>
                            <p style="color:#666;">Varrendo seu conteúdo e cruzando dados com o Google.</p>
                        </div>

                        <div id="scout-results" style="margin-top:30px;"></div>
                    </div>

                    <div id="tab-settings" class="sts-tab-content">
                        <div style="display:grid; grid-template-columns: 1fr 380px; gap:40px;">
                            <div>
                                <h3 style="margin-top:0;">🛑 Guia Passo-a-Passo (Para Iniciantes)</h3>
                                <div class="sts-step-card"><div class="sts-step-num">1</div><strong>Acesse o Google Cloud</strong><br><span style="font-size:12px;">Vá em <a href="https://console.cloud.google.com/" target="_blank">Google Cloud Console</a> e crie o projeto <b>Scout SEO</b>.</span></div>
                                <div class="sts-step-card"><div class="sts-step-num">2</div><strong>Ative a API</strong><br><span style="font-size:12px;">Ative a <b>Google Search Console API</b> na biblioteca.</span></div>
                                <div class="sts-step-card" style="background:#fff9e6;"><div class="sts-step-num">3</div><strong>Redirect URI:</strong><br><code style="display:block; background:#fff; padding:10px; margin-top:10px; border:1px solid #ddd; font-size:11px; word-break:break-all;"><?php echo admin_url('admin.php?page=seo-cannibalization-scout');?></code></div>
                            </div>
                            <div style="background:#f6f7f7; padding:30px; border-radius:15px; border:1px solid #ddd;">
                                <label><strong>Client ID:</strong><br><input type="text" id="gsc-client-id" style="width:100%" value="<?php echo esc_attr(get_option('sts_scout_gsc_client_id'));?>"></label><br><br>
                                <label><strong>Client Secret:</strong><br><input type="password" id="gsc-client-secret" style="width:100%" value="********"></label><br><br>
                                <button class="button button-primary button-large" style="width:100%" id="save-gsc-btn">Salvar e Conectar</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="sts-help-modal" class="sts-audit-modal">
                <div class="sts-modal-content">
                    <h3 style="border-bottom:2px solid #d63638; padding-bottom:10px;">🛡️ Ajuda do Scout</h3>
                    <p><b>1. O que é Canibalização?</b> Duas URLs competindo pela mesma palavra-chave.</p>
                    <p><b>2. Como resolver?</b> Use os cliques do Google para decidir qual URL manter como 'Master'.</p>
                    <button onclick="jQuery('#sts-help-modal').fadeOut()" class="button button-primary" style="width:100%">Fechar</button>
                </div>
            </div>

            <script>
            jQuery(document).ready(function($) {
                $('.sts-tab-link').on('click', function() {
                    $('.sts-tab-link, .sts-tab-content').removeClass('active');
                    $(this).addClass('active'); $('#tab-'+$(this).data('tab')).addClass('active');
                });
                $('#run-scout-btn').on('click', function() {
                    const btn = $(this);
                    const loader = $('#sts-loader');
                    const results = $('#scout-results');
                    const types = $('input[name="post_types[]"]:checked').map(function(){ return $(this).val(); }).get();

                    btn.prop('disabled', true).text('⏳ Scaneando...');
                    results.fadeOut();
                    loader.fadeIn();

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
                                                <div class='sts-gsc-mini-badge'>📊 ${item.gsc1.clicks} clicks</div>
                                            </div>
                                            <div class='sts-vs-icon'>VS</div>
                                            <div class='sts-url-unit' style='${item.gsc2.clicks < item.gsc1.clicks ? 'opacity:0.6' : 'border:2px solid #d63638'}'>
                                                <span class='sts-type-tag sts-type-${item.type2}'>${item.type2}</span><br><strong>/${item.post2}/</strong>
                                                <div class='sts-gsc-mini-badge'>📊 ${item.gsc2.clicks} clicks</div>
                                            </div>
                                        </div>
                                        <button class='button resolve-btn' data-index='${index}'>Resolve</button>
                                    </div>`;
                                });
                                results.html(h).fadeIn(); window.audit_items=res.data.conflicts;
                            }
                        });
                    });
                });
                $(document).on('click','.resolve-btn',function(){ currentItem=window.audit_items[$(this).data('index')]; currentItem.dom_id='#item-'+$(this).data('index'); $('#sts-resolve-modal').fadeIn(); });
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
