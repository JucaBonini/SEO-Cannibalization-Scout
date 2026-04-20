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

        $client_id = get_option('sts_scout_gsc_client_id');
        $client_secret = get_option('sts_scout_gsc_client_secret');
        $auth_code = get_option('sts_scout_gsc_auth_code');

        if (!$auth_code || !$client_id || !$client_secret) return [];

        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'body' => [
                'code'          => $auth_code,
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri'  => admin_url('admin.php?page=seo-cannibalization-scout'),
                'grant_type'    => 'authorization_code',
            ]
        ]);

        if (is_wp_error($response)) return [];
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $access_token = isset($data['access_token']) ? $data['access_token'] : '';
        if (!$access_token) return [];

        $site_url = urlencode(trailingslashit(home_url()));
        $perf_response = wp_remote_post("https://www.googleapis.com/webmasters/v3/sites/{$site_url}/searchAnalytics/query", [
            'headers' => ['Authorization' => 'Bearer ' . $access_token, 'Content-Type' => 'application/json'],
            'body' => json_encode(['startDate' => date('Y-m-d', strtotime('-30 days')), 'endDate' => date('Y-m-d'), 'dimensions' => ['page'], 'rowLimit' => 10000])
        ]);

        if (is_wp_error($perf_response)) return [];
        $rows = json_decode(wp_remote_retrieve_body($perf_response), true);
        $final_map = [];
        if (isset($rows['rows'])) {
            foreach ($rows['rows'] as $row) { $final_map[$row['keys'][0]] = ['clicks' => $row['clicks'], 'impressions' => $row['impressions']]; }
        }
        set_transient('sts_scout_gsc_data', $final_map, DAY_IN_SECONDS);
        return $final_map;
    }

    public function force_plugin_locale($locale, $domain) {
        if ($domain === 'seo-cannibalization-scout') {
            $user_lang = get_user_meta(get_current_user_id(), 'sts_scout_lang', true);
            if ($user_lang) return $user_lang;
        }
        return $locale;
    }

    public function add_menu_page() {
        add_menu_page(__('Cannibal Audit', 'seo-cannibalization-scout'), __('Cannibal Scout 🔴', 'seo-cannibalization-scout'), 'manage_options', 'seo-cannibalization-scout', [$this, 'render_page'], 'dashicons-performance', 30);
    }

    public function enqueue_assets($hook) {
        if ('toplevel_page_seo-cannibalization-scout' !== $hook) return;
        wp_add_inline_style('wp-admin', "
            .sts-scout-card { max-width: 1300px; margin: 20px auto; border-radius: 8px; background:#fff; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid #ccd0d4; overflow:hidden; }
            .sts-scout-header { background: #d63638; color: #fff; padding: 40px; display:flex; justify-content:space-between; align-items:center; }
            .sts-scout-header h2 { color: #fff !important; margin: 0 !important; font-size: 28px; line-height:1.2; }
            .sts-scout-header p { color: rgba(255,255,255,0.8); margin: 5px 0 0 0; }
            .sts-lang-selector { background:rgba(255,255,255,0.2); border:1px solid rgba(255,255,255,0.3); color:#fff; padding:5px 10px; border-radius:5px; cursor:pointer; }
            .sts-scout-content { padding: 30px; }
            .sts-scout-tabs { display:flex; gap:5px; margin-bottom:20px; border-bottom:1px solid #ddd; overflow-x: auto; }
            .sts-tab-link { padding:10px 20px; cursor:pointer; border:1px solid transparent; border-bottom:none; margin-bottom:-1px; border-radius:5px 5px 0 0; font-weight:600; color:#666; }
            .sts-tab-link.active { background:#fff; border-color:#ddd; color:#d63638; }
            .sts-tab-content { display:none; }
            .sts-tab-content.active { display:block; }
            .sts-conflict-item { background: #fff; border: 1px solid #ccd0d4; padding: 25px; margin-bottom: 15px; border-radius: 8px; border-left: 6px solid #d63638; display:flex; justify-content:space-between; align-items:center; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
            .sts-conflict-grid { display:grid; grid-template-columns: 1fr auto 1fr; gap:20px; align-items:center; width: 100%; }
            .sts-url-unit { background:#f9fafa; padding:15px; border-radius:8px; border:1px solid #eee; }
            .sts-type-tag { font-size:10px; text-transform:uppercase; font-weight:bold; padding:2px 6px; border-radius:4px; margin-bottom:8px; display:inline-block; }
            .sts-type-post { background:#e0f2fe; color:#0369a1; }
            .sts-type-story { background:#fef3c7; color:#92400e; }
            .sts-type-page { background:#f3f4f6; color:#374151; }
            .sts-gsc-mini-badge { background:#fff; border:1px solid #ddd; padding:4px 8px; border-radius:4px; font-size:11px; margin-top:8px; display:inline-flex; align-items:center; gap:5px; }
            .sts-vs-icon { background:#d63638; color:#fff; width:30px; height:30px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:bold; font-size:12px; }
            .sts-stat-box { background: #f6f7f7; padding: 15px; border-radius: 4px; flex: 1; text-align: center; border: 1px solid #dcdcde; }
            .sts-stat-num { display: block; font-size: 28px; font-weight: 700; color: #d63638; }
            .sts-audit-modal { display:none; position:fixed; z-index:10000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.6); }
            .sts-modal-content { background:#fff; margin:10% auto; padding:30px; border-radius:12px; width:550px; }
        ");
    }

    public function render_page() {
        $is_authed = get_option('sts_scout_gsc_auth_code', false);
        ?>
        <div class="wrap" style="max-width: 1300px; margin: 20px auto;">
            <div class="sts-scout-card card">
                <div class="sts-scout-header">
                    <h2>SEO Cannibalization Scout</h2>
                    <div class="sts-header-actions">
                        <select class="sts-lang-selector" id="sts-scout-lang-switch">
                            <option value="pt_BR">🇧🇷 PT</option>
                            <option value="en_US">🇺🇸 EN</option>
                        </select>
                    </div>
                </div>
                
                <div class="sts-scout-content">
                    <div class="sts-scout-tabs">
                        <div class="sts-tab-link active" data-tab="audit">Audit</div>
                        <div class="sts-tab-link" data-tab="settings">GSC Integration <?php echo $is_authed ? '✅' : ''; ?></div>
                        <div class="sts-tab-link" data-tab="support">Review</div>
                    </div>

                    <div id="tab-audit" class="sts-tab-content active">
                        <p><strong>Select content types:</strong></p>
                        <div style="display:flex; gap:10px; margin-bottom:20px; flex-wrap:wrap;">
                            <?php
                            $types = get_post_types(['public' => true], 'objects');
                            foreach ($types as $t) : if(in_array($t->name, ['attachment','revision','nav_menu_item'])) continue;
                            ?>
                                <label style="background:#fff; padding:8px 15px; border:1px solid #ddd; border-radius:5px; cursor:pointer;">
                                    <input type="checkbox" name="post_types[]" value="<?php echo $t->name; ?>" checked> <?php echo $t->label; ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <button class="button button-primary button-large" id="run-scout-btn">Start Surgical Scan</button>
                        <div id="scout-results" style="margin-top:30px;"></div>
                    </div>

                    <div id="tab-settings" class="sts-tab-content">
                        <h3>Google Connection</h3>
                        <p>Credentials required for live traffic analysis.</p>
                        <div style="background:#f6f7f7; padding:20px; border-radius:8px; max-width:400px;">
                            <input type="text" id="gsc-client-id" class="regular-text" style="width:100%" placeholder="Client ID"><br><br>
                            <input type="password" id="gsc-client-secret" class="regular-text" style="width:100%" placeholder="Client Secret"><br><br>
                            <button class="button button-secondary" id="save-gsc-btn">Save & Connect</button>
                        </div>
                    </div>
                </div>
            </div>

            <div id="sts-resolve-modal" class="sts-audit-modal">
                <div class="sts-modal-content">
                    <h3>Resolve Conflict</h3>
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-top:20px;">
                        <button class="sts-modal-btn" data-action="canonical">Canonical (Authority)</button>
                        <button class="sts-modal-btn" data-action="redirect">Redirect 301 (Move)</button>
                    </div>
                    <button onclick="jQuery('#sts-resolve-modal').fadeOut()" class="button" style="width:100%; margin-top:20px;">Cancel</button>
                </div>
            </div>

            <script>
            jQuery(document).ready(function($) {
                $('.sts-tab-link').on('click', function() {
                    $('.sts-tab-link, .sts-tab-content').removeClass('active');
                    $(this).addClass('active'); $('#tab-'+$(this).data('tab')).addClass('active');
                });

                $('#save-gsc-btn').on('click', function() {
                    $.post(ajaxurl, { action: 'sts_cannibal_save_gsc', client_id: $('#gsc-client-id').val(), client_secret: $('#gsc-client-secret').val() }, function(res) {
                        if(res.success) window.location.href = res.data.auth_url;
                    });
                });

                $('#run-scout-btn').on('click', function() {
                    let results = $('#scout-results');
                    const types = $('input[name="post_types[]"]:checked').map(function(){ return $(this).val(); }).get();
                    $.post(ajaxurl, { action: 'sts_cannibal_run_audit', types: types, _ajax_nonce: '<?php echo wp_create_nonce("cannibal_audit_nonce"); ?>' }, function(response) {
                        if(response.success) {
                            let html = `<div style="display:flex; gap:20px; margin-bottom:30px;">
                                <div class="sts-stat-box"><span class="sts-stat-num">${response.data.total_posts}</span> Items</div>
                                <div class="sts-stat-box"><span class="sts-stat-num">${response.data.conflicts.length}</span> Conflicts</div>
                            </div>`;
                            response.data.conflicts.forEach((item, index) => {
                                const g1 = item.gsc1; const g2 = item.gsc2;
                                html += `
                                    <div class="sts-conflict-item" id="item-${index}">
                                        <div class="sts-conflict-grid">
                                            <div class="sts-url-unit" style="${g1.clicks < g2.clicks ? 'opacity:0.6' : 'border-color:#d63638'}">
                                                <span class="sts-type-tag sts-type-${item.type1}">${item.type1}</span><br>
                                                <strong>/${item.post1}/</strong>
                                                <div class="sts-gsc-mini-badge">📊 ${g1.clicks} clicks</div>
                                            </div>
                                            <div class="sts-vs-icon">VS</div>
                                            <div class="sts-url-unit" style="${g2.clicks < g1.clicks ? 'opacity:0.6' : 'border-color:#d63638'}">
                                                <span class="sts-type-tag sts-type-${item.type2}">${item.type2}</span><br>
                                                <strong>/${item.post2}/</strong>
                                                <div class="sts-gsc-mini-badge">📊 ${g2.clicks} clicks</div>
                                            </div>
                                        </div>
                                        <button class="button button-secondary resolve-btn" data-index="${index}" style="margin-left:20px;">Resolve</button>
                                    </div>
                                `;
                            });
                            results.html(html);
                            window.audit_items = response.data.conflicts;
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

    public function ajax_save_gsc() {
        $id = sanitize_text_field($_POST['client_id']); $secret = sanitize_text_field($_POST['client_secret']);
        update_option('sts_scout_gsc_client_id', $id); update_option('sts_scout_gsc_client_secret', $secret);
        $redirect = urlencode(admin_url('admin.php?page=seo-cannibalization-scout'));
        $scope = urlencode('https://www.googleapis.com/auth/webmasters.readonly');
        $url = "https://accounts.google.com/o/oauth2/v2/auth?client_id={$id}&redirect_uri={$redirect}&response_type=code&scope={$scope}&access_type=offline&prompt=consent";
        wp_send_json_success(['auth_url' => $url]);
    }

    public function ajax_run_audit() {
        check_ajax_referer('cannibal_audit_nonce');
        $types = isset($_POST['types']) ? $_POST['types'] : ['post', 'story'];
        $posts = get_posts(['post_type' => $types, 'posts_per_page' => -1, 'post_status' => 'publish', 'fields' => 'ids']);
        $gsc_data = $this->get_gsc_performance_data();
        $conflicts = []; $map = [];
        $suffixes = ['receita', 'facil', 'passo-a-passo', 'caseiro'];
        $pattern = '/-(' . implode('|', $suffixes) . ')$/';

        foreach ($posts as $pid) {
            if (get_post_meta($pid, '_sts_seo_canonical', true)) continue;
            $slug = get_post_field('post_name', $pid);
            $norm = preg_replace($pattern, '', $slug);
            $norm = preg_replace('/(s|es)$/', '', $norm);
            $url = get_permalink($pid);
            $map[$norm][] = [
                'id' => $pid, 'slug' => $slug, 'type' => get_post_type($pid), 'url' => $url,
                'gsc' => isset($gsc_data[$url]) ? $gsc_data[$url] : ['clicks' => 0, 'impressions' => 0]
            ];
        }

        foreach ($map as $k => $items) {
            if (count($items) > 1) {
                // Ordenar por cliques para identificar quem tem mais força
                usort($items, function($a, $b) { return $b['gsc']['clicks'] - $a['gsc']['clicks']; });
                $master = $items[0];
                for ($i = 1; $i < count($items); $i++) {
                    $slave = $items[$i];
                    $conflicts[] = [
                        'keyword' => $k, 'post1' => $slave['slug'], 'post2' => $master['slug'],
                        'type1' => $slave['type'], 'type2' => $master['type'],
                        'id1' => $slave['id'], 'url2' => $master['url'],
                        'gsc1' => $slave['gsc'], 'gsc2' => $master['gsc']
                    ];
                }
            }
        }
        wp_send_json_success(['total_posts' => count($posts), 'conflicts' => $conflicts]);
    }

    public function ajax_resolve_issue() {
        if ($_POST['type'] === 'canonical') { update_post_meta((int)$_POST['post_from'], '_sts_seo_canonical', esc_url_raw($_POST['post_to_url'])); }
        wp_send_json_success();
    }
}
