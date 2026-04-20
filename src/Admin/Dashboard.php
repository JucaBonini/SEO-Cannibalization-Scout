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
        add_action('admin_init', [$this, 'handle_google_auth_callback']);
    }

    public function handle_google_auth_callback() {
        if (isset($_GET['page']) && $_GET['page'] === 'seo-cannibalization-scout' && isset($_GET['code'])) {
            $code = sanitize_text_field($_GET['code']);
            update_option('sts_scout_gsc_auth_code', $code);
            delete_transient('sts_scout_gsc_data'); // Limpa cache para forçar busca nova
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

        // 1. Trocar Code por Access Token
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

        // 2. Buscar dados de performance (Analytics)
        $site_url = urlencode(trailingslashit(home_url()));
        $query_url = "https://www.googleapis.com/webmasters/v3/sites/{$site_url}/searchAnalytics/query";
        
        $perf_response = wp_remote_post($query_url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json'
            ],
            'body' => json_encode([
                'startDate' => date('Y-m-d', strtotime('-30 days')),
                'endDate'   => date('Y-m-d'),
                'dimensions' => ['page'],
                'rowLimit'   => 5000
            ])
        ]);

        if (is_wp_error($perf_response)) return [];
        $rows = json_decode(wp_remote_retrieve_body($perf_response), true);
        
        $final_map = [];
        if (isset($rows['rows'])) {
            foreach ($rows['rows'] as $row) {
                $final_map[$row['keys'][0]] = [
                    'clicks' => $row['clicks'],
                    'impressions' => $row['impressions']
                ];
            }
        }

        set_transient('sts_scout_gsc_data', $final_map, DAY_IN_SECONDS);
        return $final_map;
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
            .sts-scout-card { max-width: 1300px; margin: 20px auto; border-radius: 8px; background:#fff; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid #ccd0d4; overflow:hidden; }
            .sts-scout-header { background: #d63638; color: #fff; padding: 40px; display:flex; justify-content:space-between; align-items:center; }
            .sts-scout-header h2 { color: #fff !important; margin: 0 !important; font-size: 28px; line-height:1.2; }
            .sts-scout-header p { color: rgba(255,255,255,0.8); margin: 5px 0 0 0; }
            .sts-lang-selector { background:rgba(255,255,255,0.2); border:1px solid rgba(255,255,255,0.3); color:#fff; padding:5px 10px; border-radius:5px; cursor:pointer; }
            .sts-help-trigger { background:rgba(255,255,255,0.2); color:#fff; width:35px; height:35px; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; font-weight:bold; }
            .sts-scout-content { padding: 30px; }
            .sts-scout-tabs { display:flex; gap:5px; margin-bottom:20px; border-bottom:1px solid #ddd; overflow-x: auto; }
            .sts-tab-link { padding:10px 20px; cursor:pointer; border:1px solid transparent; margin-bottom:-1px; border-radius:5px 5px 0 0; font-weight:600; color:#666; }
            .sts-tab-link.active { background:#fff; border-color:#ddd; color:#d63638; }
            .sts-tab-content { display:none; }
            .sts-tab-content.active { display:block; }
            .sts-conflict-item { background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin-bottom: 12px; border-radius: 4px; display: flex; justify-content: space-between; align-items: center; border-left: 5px solid #d63638; }
            .sts-gsc-badge { background: #f1f5f9; padding: 5px 12px; border-radius: 4px; font-size: 11px; display: inline-flex; align-items: center; gap: 5px; margin-top: 10px; color: #475569; border:1px solid #e2e8f0; }
            .sts-stat-box { background: #f6f7f7; padding: 15px; border-radius: 4px; flex: 1; text-align: center; border: 1px solid #dcdcde; }
            .sts-stat-num { display: block; font-size: 28px; font-weight: 700; color: #d63638; }
            .sts-slug-tag { font-family: monospace; background: #f0f0f1; padding: 2px 6px; border-radius: 3px; font-size: 11px; }
            .sts-audit-modal { display:none; position:fixed; z-index:10000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.6); }
            .sts-modal-content { background:#fff; margin:10% auto; padding:30px; border-radius:12px; width:550px; }
        ");
    }

    public function render_page() {
        $gsc_client_id = get_option('sts_scout_gsc_client_id', '');
        $is_authed = get_option('sts_scout_gsc_auth_code', false);
        ?>
        <div class="wrap">
            <div class="sts-scout-card card">
                <div class="sts-scout-header">
                    <h2>SEO Cannibalization Scout</h2>
                    <div class="sts-help-trigger" onclick="jQuery('#sts-help-modal').fadeIn()">?</div>
                </div>
                <div class="sts-scout-content">
                    <div class="sts-scout-tabs">
                        <div class="sts-tab-link active" data-tab="audit"><?php _e('Audit', 'seo-cannibalization-scout'); ?></div>
                        <div class="sts-tab-link" data-tab="settings"><?php _e('GSC Integration', 'seo-cannibalization-scout'); ?> <?php echo $is_authed ? '✅' : ''; ?></div>
                        <div class="sts-tab-link" data-tab="about"><?php _e('Support', 'seo-cannibalization-scout'); ?></div>
                    </div>

                    <div id="tab-audit" class="sts-tab-content active">
                        <button class="button button-primary" id="run-scout-btn"><?php _e('Start Scan', 'seo-cannibalization-scout'); ?></button>
                        <div id="scout-results" style="margin-top:20px;"></div>
                    </div>

                    <div id="tab-settings" class="sts-tab-content">
                        <h3>Connect GSC</h3>
                        <input type="text" id="gsc-client-id" value="<?php echo esc_attr($gsc_client_id); ?>" placeholder="Client ID"><br><br>
                        <input type="password" id="gsc-client-secret" value="********" placeholder="Client Secret"><br><br>
                        <button class="button button-primary" id="save-gsc-btn">Save & Authorize</button>
                    </div>

                    <div id="tab-about" class="sts-tab-content">
                        <h3>Support the Dev</h3>
                        <a href="https://wordpress.org/support/plugin/seo-cannibalization-scout/reviews/" class="button">Review 5 Stars</a>
                    </div>
                </div>
            </div>

            <div id="sts-help-modal" class="sts-audit-modal">
                <div class="sts-modal-content">
                    <h3>Help</h3><p>Manage your cannibalization issues.</p>
                    <button onclick="jQuery('#sts-help-modal').fadeOut()" class="button">Close</button>
                </div>
            </div>

            <script>
            jQuery(document).ready(function($) {
                $('.sts-tab-link').on('click', function() {
                    $('.sts-tab-link, .sts-tab-content').removeClass('active');
                    $(this).addClass('active');
                    $('#tab-' + $(this).data('tab')).addClass('active');
                });

                $('#save-gsc-btn').on('click', function() {
                    $.post(ajaxurl, { action: 'sts_cannibal_save_gsc', client_id: $('#gsc-client-id').val(), client_secret: $('#gsc-client-secret').val() }, function(res) {
                        if (res.success) window.location.href = res.data.auth_url;
                    });
                });

                $('#run-scout-btn').on('click', function() {
                    let results = $('#scout-results');
                    $.post(ajaxurl, { action: 'sts_cannibal_run_audit', types: ['post', 'page'], _ajax_nonce: '<?php echo wp_create_nonce("cannibal_audit_nonce"); ?>' }, function(response) {
                        if (response.success) {
                            let html = '';
                            response.data.conflicts.forEach((item, index) => {
                                const gsc = item.gsc || {clicks:0, impressions:0};
                                html += `<div class="sts-conflict-item"><div><strong>${item.keyword}</strong><br><span class="sts-slug-tag">${item.post1}</span> vs <span class="sts-slug-tag">${item.post2}</span><br><div class="sts-gsc-badge">📊 ${gsc.clicks} cliques / ${gsc.impressions} imp.</div></div><button class="button">Resolve</button></div>`;
                            });
                            results.html(html);
                        }
                    });
                });
            });
            </script>
        </div>
        <?php
    }

    public function ajax_save_gsc() {
        $client_id = sanitize_text_field($_POST['client_id']);
        update_option('sts_scout_gsc_client_id', $client_id);
        update_option('sts_scout_gsc_client_secret', sanitize_text_field($_POST['client_secret']));
        $redirect_uri = urlencode(admin_url('admin.php?page=seo-cannibalization-scout'));
        $scope = urlencode('https://www.googleapis.com/auth/webmasters.readonly');
        $auth_url = "https://accounts.google.com/o/oauth2/v2/auth?client_id={$client_id}&redirect_uri={$redirect_uri}&response_type=code&scope={$scope}&access_type=offline&prompt=consent";
        wp_send_json_success(['auth_url' => $auth_url]);
    }

    public function ajax_run_audit() {
        check_ajax_referer('cannibal_audit_nonce');
        $posts = get_posts(['post_type' => ['post', 'page'], 'posts_per_page' => -1, 'fields' => 'ids']);
        $gsc_data = $this->get_gsc_performance_data();
        $conflicts = []; $map = [];
        foreach ($posts as $pid) {
            $slug = get_post_field('post_name', $pid);
            $url = get_permalink($pid);
            $norm = preg_replace('/-(receita|facil|passo-a-passo)$/', '', $slug);
            $map[$norm][] = ['id' => $pid, 'slug' => $slug, 'url' => $url, 'gsc' => isset($gsc_data[$url]) ? $gsc_data[$url] : ['clicks'=>0,'impressions'=>0]];
        }
        foreach ($map as $k => $items) {
            if (count($items) > 1) {
                usort($items, function($a, $b) { return $b['gsc']['clicks'] - $a['gsc']['clicks']; });
                $conflicts[] = ['keyword' => $k, 'post1' => $items[1]['slug'], 'post2' => $items[0]['slug'], 'gsc' => $items[1]['gsc'], 'url2' => $items[0]['url']];
            }
        }
        wp_send_json_success(['conflicts' => $conflicts]);
    }
}
