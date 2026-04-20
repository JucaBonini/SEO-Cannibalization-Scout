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
            .sts-scout-card { max-width:1300px; margin:20px auto; border-radius:12px; background:#fff; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #e2e8f0; overflow:hidden; }
            .sts-scout-header { background:#d63638; color:#fff; padding:35px 45px; display:flex; justify-content:space-between; align-items:center; position:relative; }
            .sts-scout-header h2 { color:#fff!important; margin:0!important; font-size:28px; letter-spacing:-0.5px; }
            .sts-scout-header p { color:rgba(255,255,255,0.8); margin:5px 0 0 0; font-size:14px; }
            .sts-header-actions { display:flex; align-items:center; gap:15px; }
            .sts-lang-switch { background:rgba(255,255,255,0.15); border:1px solid rgba(255,255,255,0.2); color:#fff; border-radius:6px; padding:6px 12px; font-size:12px; cursor:pointer; }
            .sts-help-btn { background:rgba(255,255,255,0.15); color:#fff; width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; font-weight:bold; transition:0.3s; border:1px solid rgba(255,255,255,0.2); }
            .sts-help-btn:hover { background:#fff; color:#d63638; }
            .sts-scout-content { padding:40px; }
            .sts-scout-tabs { display:flex; gap:10px; border-bottom:1px solid #edf2f7; margin-bottom:30px; }
            .sts-tab-nav { padding:12px 25px; cursor:pointer; font-weight:600; color:#718096; border-bottom:3px solid transparent; transition:0.2s; font-size:15px; }
            .sts-tab-nav.active { color:#d63638; border-bottom-color:#d63638; }
            .sts-tab-panel { display:none; animation: fadeIn 0.3s ease; }
            .sts-tab-panel.active { display:block; }
            @keyframes fadeIn { from { opacity:0; transform:translateY(5px); } to { opacity:1; transform:translateY(0); } }
            .sts-type-pill { background:#fff; border:1px solid #e2e8f0; padding:10px 18px; border-radius:8px; cursor:pointer; display:flex; align-items:center; gap:10px; transition:0.2s; }
            .sts-type-pill:hover { border-color:#d63638; background:#fff5f5; }
            .sts-type-pill input { margin:0; }
            .sts-conflict-card { background:#fff; border:1px solid #e2e8f0; padding:30px; margin-bottom:20px; border-radius:12px; border-left:6px solid #d63638; display:flex; justify-content:space-between; align-items:center; }
            .sts-duel-grid { display:grid; grid-template-columns:1fr auto 1fr; gap:25px; align-items:center; width:100%; }
            .sts-url-box { background:#f8fafc; padding:20px; border-radius:10px; border:1px solid #edf2f7; position:relative; }
            .sts-badge { font-size:10px; text-transform:uppercase; font-weight:700; padding:3px 8px; border-radius:5px; margin-bottom:12px; display:inline-block; }
            .sts-badge-post { background:#e0f2fe; color:#0369a1; }
            .sts-badge-story { background:#fef3c7; color:#92400e; }
            .sts-badge-page { background:#f3e8ff; color:#7e22ce; }
            .sts-mini-metric { background:#fff; border:1px solid #e2e8f0; padding:4px 10px; border-radius:6px; font-size:12px; display:inline-flex; align-items:center; gap:6px; font-weight:600; }
            .sts-vs-circle { background:#d63638; color:#fff; width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:bold; font-size:11px; box-shadow:0 4px 10px rgba(214,54,56,0.2); }
            .sts-stat-grid { display:flex; gap:20px; margin-bottom:40px; }
            .sts-stat-card { background:#f8fafc; padding:20px; border-radius:10px; flex:1; text-align:center; border:1px solid #e2e8f0; }
            .sts-stat-val { display:block; font-size:32px; font-weight:800; color:#1a202c; }
            .sts-loading-overlay { text-align:center; padding:60px; display:none; }
            .sts-loader-spinner { border:4px solid #f3f3f3; border-top:4px solid #d63638; border-radius:50%; width:45px; height:45px; animation:spin 1s linear infinite; margin:0 auto 20px; }
            @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
            .sts-tuto-box { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:25px; margin-bottom:20px; }
            .sts-tuto-badge { background:#d63638; color:#fff; width:28px; height:28px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:bold; margin-bottom:15px; }
        ");
    }

    public function render_page() {
        $is_authed = get_option('sts_scout_gsc_auth_code', false);
        $current_lang = get_user_meta(get_current_user_id(), 'sts_scout_lang', true) ?: get_locale();
        ?>
        <div class="wrap" style="max-width:1300px; margin:20px auto;">
            <div class="sts-scout-card">
                <div class="sts-scout-header">
                    <div>
                        <h2>SEO Cannibalization Scout</h2>
                        <p>Enterprise Content Audit & Authority Detection</p>
                    </div>
                    <div class="sts-header-actions">
                        <select class="sts-lang-switch" id="sts-lang-selector">
                            <option value="pt_BR" <?php selected($current_lang,'pt_BR');?>>🇧🇷 Português</option>
                            <option value="en_US" <?php selected($current_lang,'en_US');?>>🇺🇸 English</option>
                        </select>
                        <div class="sts-help-btn" onclick="jQuery('#sts-help-modal').fadeIn()">?</div>
                    </div>
                </div>
                
                <div class="sts-scout-content">
                    <div class="sts-scout-tabs">
                        <div class="sts-tab-nav active" data-tab="audit">🔍 Audit Dashboard</div>
                        <div class="sts-tab-nav" data-tab="settings">⚙️ GSC Integration <?php echo $is_authed?'✅':'';?></div>
                        <div class="sts-tab-nav" data-tab="support">❤️ Support</div>
                    </div>

                    <div id="panel-audit" class="sts-tab-panel active">
                        <p style="font-weight:600; color:#4a5568; margin-bottom:15px;">Target Content Types:</p>
                        <div style="display:flex; gap:12px; margin-bottom:30px; flex-wrap:wrap;">
                            <?php
                            // DINÂMICO: Busca todos os tipos de post públicos do site
                            $pts = get_post_types(['public'=>true],'objects');
                            foreach ($pts as $t) : if(in_array($t->name,['attachment','revision','nav_menu_item'])) continue;
                                $check = in_array($t->name,['post','page','web-story','receita']) ? 'checked':'';
                            ?>
                                <label class="sts-type-pill">
                                    <input type="checkbox" name="post_types[]" value="<?php echo $t->name;?>" <?php echo $check;?>> <?php echo $t->label;?>
                                </label>
                            <?php endforeach;?>
                        </div>
                        <button class="button button-primary button-hero" id="run-audit-action" style="height:50px; padding:0 35px; border-radius:8px; font-weight:700;">🚀 START SURGICAL SCAN</button>
                        
                        <div id="sts-audit-loader" class="sts-loading-overlay">
                            <div class="sts-loader-spinner"></div>
                            <h3>Analyzing Content Ecosystem...</h3>
                            <p style="color:#718096;">Fetching Google metrics and detecting authority conflicts.</p>
                        </div>

                        <div id="sts-audit-results" style="margin-top:40px;"></div>
                    </div>

                    <!-- GSC TUTORIAL (Commercial Design) -->
                    <div id="panel-settings" class="sts-tab-panel">
                        <div style="display:grid; grid-template-columns: 1fr 380px; gap:40px;">
                            <div>
                                <h3 style="margin-top:0;">🛑 Professional Setup Guide</h3>
                                <div class="sts-tuto-box"><div class="sts-tuto-badge">1</div><h4>Google Cloud Project</h4><p>Create a project at <a href="https://console.cloud.google.com/" target="_blank">Cloud Console</a> named <b>"Scout SEO"</b>.</p></div>
                                <div class="sts-tuto-box"><div class="sts-tuto-badge">2</div><h4>API Activation</h4><p>Enable the <b>"Google Search Console API"</b> in the Library section.</p></div>
                                <div class="sts-tuto-box" style="background:#fffaf0; border-color:#fbd38d;"><div class="sts-tuto-badge" style="background:#f6ad55;">3</div><h4>OAuth Redirect URI</h4><p>Crucial: Add this precise link to your OAuth Credentials:</p><code style="display:block; background:#fff; padding:12px; margin-top:10px; border:1px solid #e2e8f0; font-size:12px;"><?php echo admin_url('admin.php?page=seo-cannibalization-scout');?></code></div>
                            </div>
                            <div style="background:#f8fafc; padding:35px; border-radius:15px; border:1px solid #e2e8f0; height:fit-content; position:sticky; top:30px;">
                                <h4 style="margin:0 0 20px 0;">Google Credentials:</h4>
                                <label style="display:block; margin-bottom:15px;"><strong>Client ID:</strong><br><input type="text" id="gsc-client-id" style="width:100%; padding:10px;" value="<?php echo esc_attr(get_option('sts_scout_gsc_client_id'));?>"></label>
                                <label style="display:block; margin-bottom:25px;"><strong>Client Secret:</strong><br><input type="password" id="gsc-client-secret" style="width:100%; padding:10px;" value="********"></label>
                                <button class="button button-primary button-large" style="width:100%; height:50px; font-weight:bold;" id="save-gsc-config">SAVE & AUTHORIZE</button>
                            </div>
                        </div>
                    </div>

                    <!-- SUPPORT TAB -->
                    <div id="panel-support" class="sts-tab-panel">
                        <div style="text-align:center; padding:60px 0;">
                             <h2 style="font-size:32px; margin-bottom:10px;">Support & Donations</h2>
                             <p style="color:#718096; font-size:16px; margin-bottom:45px;">Your support keeps this tool free and high-performance!</p>
                             <div style="display:flex; justify-content:center; gap:20px; margin-bottom:45px;">
                                 <a href="https://www.paypal.com/cgi-bin/webscr?cmd=_xclick&business=jucasouzabonini@gmail.com&item_name=Support+Scout&amount=2.00&currency_code=USD" target="_blank" class="button button-hero" style="min-width:140px;">$2.00</a>
                                 <a href="https://www.paypal.com/cgi-bin/webscr?cmd=_xclick&business=jucasouzabonini@gmail.com&item_name=Support+Scout&amount=5.00&currency_code=USD" target="_blank" class="button button-hero button-primary" style="min-width:140px;">$5.00</a>
                                 <a href="https://www.paypal.com/cgi-bin/webscr?cmd=_xclick&business=jucasouzabonini@gmail.com&item_name=Support+Scout&amount=10.00&currency_code=USD" target="_blank" class="button button-hero" style="min-width:140px; background:#1a202c!important; color:#fff!important;">$10.00</a>
                             </div>
                             <p><a href="https://wordpress.org/support/plugin/seo-cannibalization-scout/reviews/" target="_blank" style="font-weight:700; color:#d63638; font-size:16px;">★★★★★ Rate 5 Stars on WordPress.org</a></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- MODALS -->
            <div id="sts-help-modal" class="sts-audit-modal">
                <div class="sts-modal-content">
                    <h3 style="border-bottom:3px solid #d63638; padding-bottom:15px; margin-top:0;">🛡️ Scout Strategy Center</h3>
                    <p><b>1. Cannibalization:</b> When multiple URLs fight for the same keyword.</p>
                    <p><b>2. The Master:</b> The URL with the HIGHEST clicks/impressions should be your authority target.</p>
                    <p><b>3. Resolution:</b> Apply a Redirect or Canonical to the 'slave' URL pointing to the 'master'.</p>
                    <button onclick="jQuery('#sts-help-modal').fadeOut()" class="button button-primary button-large" style="width:100%; height:50px; margin-top:15px;">GOT IT, START AUDIT!</button>
                </div>
            </div>

            <script>
            jQuery(document).ready(function($) {
                $('.sts-tab-nav').on('click', function() {
                    $('.sts-tab-nav, .sts-tab-panel').removeClass('active');
                    $(this).addClass('active'); $('#panel-'+$(this).data('tab')).addClass('active');
                });
                $('#sts-lang-selector').on('change', function() {
                    $.post(ajaxurl, {action:'sts_cannibal_save_lang', lang:$(this).val()}, function(){ location.reload(); });
                });
                $('#save-gsc-config').on('click', function() {
                    $.post(ajaxurl,{action:'sts_cannibal_save_gsc',client_id:$('#gsc-client-id').val(),client_secret:$('#gsc-client-secret').val()},function(res){
                        if(res.success) window.location.href=res.data.auth_url;
                    });
                });
                $('#run-audit-action').on('click', function() {
                    const btn = $(this); const loader = $('#sts-audit-loader'); const res_div = $('#sts-audit-results');
                    const types = $('input[name="post_types[]"]:checked').map(function(){ return $(this).val(); }).get();
                    btn.prop('disabled', true).text('⏳ SURGICAL SCANNING...'); res_div.fadeOut(); loader.fadeIn();
                    $.post(ajaxurl, {action:'sts_cannibal_run_audit', types:types, _ajax_nonce:'<?php echo wp_create_nonce("cannibal_audit_nonce"); ?>'}, function(res) {
                        btn.prop('disabled', false).html('🚀 START SURGICAL SCAN');
                        loader.fadeOut(function() {
                            if(res.success) {
                                let h = `<div class='sts-stat-grid'><div class='sts-stat-card'><span class='sts-stat-val'>${res.data.total_posts}</span> Analyzed</div><div class='sts-stat-card'><span class='sts-stat-val'>${res.data.conflicts.length}</span> Conflicts Found</div></div>`;
                                res.data.conflicts.forEach((item, index) => {
                                    h += `<div class='sts-conflict-card' id='conflict-${index}'>
                                        <div class='sts-duel-grid'>
                                            <div class='sts-url-box' style='${item.gsc1.clicks < item.gsc2.clicks ? 'opacity:0.6' : 'border:2px solid #d63638'}'>
                                                <span class='sts-badge sts-badge-${item.type1}'>${item.type1}</span><br><strong>/${item.post1}/</strong>
                                                <div class='sts-gsc-stats'>
                                                    <div class='sts-mini-metric'>🖱️ ${item.gsc1.clicks} clicks</div>
                                                    <div class='sts-mini-metric'>👁️ ${item.gsc1.impressions} views</div>
                                                </div>
                                            </div>
                                            <div class='sts-vs-circle'>VS</div>
                                            <div class='sts-url-unit sts-url-box' style='${item.gsc2.clicks < item.gsc1.clicks ? 'opacity:0.6' : 'border:2px solid #d63638'}'>
                                                <span class='sts-badge sts-badge-${item.type2}'>${item.type2}</span><br><strong>/${item.post2}/</strong>
                                                <div class='sts-gsc-stats'>
                                                    <div class='sts-mini-metric'>🖱️ ${item.gsc2.clicks} clicks</div>
                                                    <div class='sts-mini-metric'>👁️ ${item.gsc2.impressions} views</div>
                                                </div>
                                            </div>
                                        </div>
                                        <button class='button button-primary resolve-btn' data-index='${index}' style='margin-left:25px;'>Resolve</button>
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
             $type = get_post_type($pid);
             $map[$norm][] = ['id'=>$pid,'slug'=>$slug,'type'=>$type,'url'=>$url,'gsc'=>$gsc[$url]??['clicks'=>0,'impressions'=>0]];
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
