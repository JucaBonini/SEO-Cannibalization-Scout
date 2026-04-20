<?php
namespace STSCannibal\Admin;

class Dashboard {
    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_sts_cannibal_run_audit', [$this, 'ajax_run_audit']);
        add_action('wp_ajax_sts_cannibal_resolve_issue', [$this, 'ajax_resolve_issue']);
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
            .sts-scout-container { max-width: 1000px; margin-top: 20px; }
            .sts-scout-card { border-radius: 8px; position:relative; background:#fff; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid #ccd0d4; overflow:hidden; }
            .sts-scout-header { background: #d63638; color: #fff; padding: 30px; display:flex; justify-content:space-between; align-items:center; }
            .sts-scout-header h2 { color: #fff !important; margin: 0 !important; font-size: 24px; line-height:1.2; }
            .sts-scout-header p { color: rgba(255,255,255,0.8); margin: 5px 0 0 0; }
            .sts-scout-content { padding: 20px; }
            
            .sts-help-trigger { background:rgba(255,255,255,0.2); color:#fff; width:30px; height:30px; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; font-weight:bold; font-size:18px; border:1px solid rgba(255,255,255,0.3); transition:0.3s; }
            .sts-help-trigger:hover { background:#fff; color:#d63638; }
            
            .sts-side-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 15px; }
            .sts-side-table th { text-align: left; background: #f6f7f7; padding: 10px; border: 1px solid #dcdcde; }
            .sts-side-table td { padding: 10px; border: 1px solid #dcdcde; vertical-align: top; }
            .sts-side-tag { display: block; font-weight: 700; margin-bottom: 3px; color: #2271b1; }

            .sts-conflict-item { background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin-bottom: 12px; border-radius: 4px; display: flex; justify-content: space-between; align-items: center; border-left: 5px solid #d63638; }
            .sts-conflict-item.warning { border-left-color: #ffb900; }
            .sts-slug-tag { font-family: monospace; background: #f0f0f1; padding: 2px 6px; border-radius: 3px; font-size: 11px; color: #50575e; }
            .sts-stat-box { background: #f6f7f7; padding: 15px; border-radius: 4px; flex: 1; text-align: center; border: 1px solid #dcdcde; }
            .sts-stat-num { display: block; font-size: 28px; font-weight: 700; color: #d63638; }
            .sts-audit-modal { display:none; position:fixed; z-index:10000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.6); }
            .sts-modal-content { background:#fff; margin:10% auto; padding:30px; border-radius:12px; width:500px; box-shadow:0 20px 50px rgba(0,0,0,0.2); }
            .sts-modal-actions { display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-top:25px; }
            .sts-modal-btn { padding:15px; border:1px solid #eee; border-radius:10px; cursor:pointer; text-align:center; transition:0.3s; }
            .sts-modal-btn:hover { background:#f6f7f7; border-color:#2271b1; }
            .sts-modal-btn h4 { margin:0 0 5px 0; color:#2271b1; }
            .sts-modal-btn p { margin:0; font-size:12px; color:#666; }
            .sts-pt-label { background:#fff; padding:5px 12px; border:1px solid #ccd0d4; border-radius:4px; font-size:13px; cursor:pointer; display:inline-block; margin:0 5px 5px 0; }
        ");
    }

    public function render_page() {
        ?>
        <div class="wrap">
            <div class="sts-scout-container">
                <div class="sts-scout-card card">
                    <div class="sts-scout-header">
                        <div>
                            <h2><?php _e('SEO Cannibalization Scout', 'seo-cannibalization-scout'); ?></h2>
                            <p><?php _e('Professional URL Conflict and Content Cannibalization Detector.', 'seo-cannibalization-scout'); ?></p>
                        </div>
                        <div class="sts-help-trigger" id="open-help-modal" title="<?php _e('Comparative Summary', 'seo-cannibalization-scout'); ?>">?</div>
                    </div>
                    
                    <div class="sts-scout-content">
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
                </div>
            </div>

            <!-- Modal de Ajuda -->
            <div id="sts-help-modal" class="sts-audit-modal">
                <div class="sts-modal-content" style="width:600px;">
                    <h3><?php _e('Resumo Comparativo', 'seo-cannibalization-scout'); ?></h3>
                    <table class="sts-side-table">
                        <tr>
                            <th><?php _e('Característica', 'seo-cannibalization-scout'); ?></th>
                            <th>Canonical (Autoridade)</th>
                            <th>Redirect 301 (Mudança)</th>
                        </tr>
                        <tr>
                            <td><strong><?php _e('Post Antigo', 'seo-cannibalization-scout'); ?></strong></td>
                            <td><?php _e('Fica online (Visível)', 'seo-cannibalization-scout'); ?></td>
                            <td><?php _e('Fica Offline (Draft)', 'seo-cannibalization-scout'); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('Poder de SEO', 'seo-cannibalization-scout'); ?></strong></td>
                            <td><?php _e('Flui lentamente', 'seo-cannibalization-scout'); ?></td>
                            <td><?php _e('Transfere instantaneamente', 'seo-cannibalization-scout'); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('Qual escolher?', 'seo-cannibalization-scout'); ?></strong></td>
                            <td><?php _e('Limpar briga sem deletar nada.', 'seo-cannibalization-scout'); ?></td>
                            <td><?php _e('Exterminar repetidos e ser o #1.', 'seo-cannibalization-scout'); ?></td>
                        </tr>
                    </table>
                    <button onclick="jQuery('#sts-help-modal').fadeOut()" class="button button-primary" style="margin-top:30px; width:100%"><?php _e('Entendi!', 'seo-cannibalization-scout'); ?></button>
                </div>
            </div>

            <!-- Modal -->
            <div id="sts-resolve-modal" class="sts-audit-modal">
                <div class="sts-modal-content">
                    <h3><?php _e('How to resolve?', 'seo-cannibalization-scout'); ?></h3>
                    <p><?php _e('Choose an action to consolidate authority.', 'seo-cannibalization-scout'); ?></p>
                    <div class="sts-modal-actions">
                        <div class="sts-modal-btn" data-action="canonical">
                            <h4><?php _e('Authority (Canonical)', 'seo-cannibalization-scout'); ?></h4>
                            <p><?php _e('Keep both URLs online, but point SEO power to master.', 'seo-cannibalization-scout'); ?></p>
                        </div>
                        <div class="sts-modal-btn" data-action="redirect">
                            <h4><?php _e('Redirect (301)', 'seo-cannibalization-scout'); ?></h4>
                            <p><?php _e('Remove access to this URL and send users to master.', 'seo-cannibalization-scout'); ?></p>
                        </div>
                    </div>
                    <?php wp_nonce_field('cannibal_resolve_nonce', 'cannibal_nonce'); ?>
                    <button onclick="jQuery('#sts-resolve-modal').fadeOut()" class="button" style="margin-top:20px; width:100%"><?php _e('Cancel', 'seo-cannibalization-scout'); ?></button>
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

                $('#open-help-modal').on('click', function() {
                    $('#sts-help-modal').fadeIn();
                });

                let currentItem = null;
                $('#run-scout-btn').on('click', function() {
                    const btn = $(this);
                    const spinner = $('#scout-spinner');
                    const results = $('#scout-results');

                    const selectedTypes = $('input[name="post_types[]"]:checked').map(function(){ return $(this).val(); }).get();
                    if (selectedTypes.length === 0) { alert('Selecione pelo menos um tipo!'); return; }

                    btn.prop('disabled', true); spinner.addClass('is-active'); results.fadeOut();

                    $.post(ajaxurl, { action: 'sts_cannibal_run_audit', types: selectedTypes, _ajax_nonce: nonce_audit }, function(response) {
                        btn.prop('disabled', false); spinner.removeClass('is-active');
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
                                            </div>
                                            <button class="button button-secondary resolve-btn" data-index="${index}">${i18n.resolve}</button>
                                        </div>
                                    `;
                                });
                                window.audit_items = response.data.conflicts;
                            } else {
                                html += '<div class="notice notice-success is-dismissible" style="margin-top:20px;"><p>' + i18n.none + '</p></div>';
                            }
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
                    if (!currentItem) return;
                    $(this).css('opacity', '0.5');
                    $.post(ajaxurl, {
                        action: 'sts_cannibal_resolve_issue',
                        type: action,
                        post_from: currentItem.id1,
                        post_to_url: currentItem.url2,
                        slug_from: currentItem.post1,
                        _ajax_nonce: $('#cannibal_nonce').val()
                    }, function(res) {
                        if (res.success) { $(currentItem.dom_id).css('background', '#f0fff4').fadeOut(); }
                        $('#sts-resolve-modal').fadeOut(); $('.sts-modal-btn').css('opacity', '1');
                    });
                });
            });
            </script>
        </div>
        <?php
    }

    public function ajax_resolve_issue() {
        check_ajax_referer('cannibal_resolve_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error();
        $type = sanitize_text_field($_POST['type']);
        $post_from = (int) $_POST['post_from'];
        $post_to_url = esc_url_raw($_POST['post_to_url']);
        $slug_from = sanitize_title($_POST['slug_from']);

        if ($type === 'canonical') {
            update_post_meta($post_from, '_sts_seo_canonical', $post_to_url);
        } elseif ($type === 'redirect') {
            if (class_exists('\STSRedirect\Core\RedirectStorage')) {
                $storage = new \STSRedirect\Core\RedirectStorage();
                $storage->add('/' . $slug_from, $post_to_url, 301);
                wp_update_post(['ID' => $post_from, 'post_status' => 'draft']);
            }
        }
        wp_send_json_success();
    }

    public function ajax_save_lang() {
        if (!current_user_can('manage_options')) wp_send_json_error();
        $lang = sanitize_text_field($_POST['lang']);
        update_user_meta(get_current_user_id(), 'cannibal_audit_lang', $lang);
        wp_send_json_success();
    }

    public function ajax_run_audit() {
        check_ajax_referer('cannibal_audit_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error();

        $types = isset($_POST['types']) ? array_map('sanitize_text_field', $_POST['types']) : ['post'];
        
        $posts = get_posts([
            'post_type' => $types,
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'fields' => 'ids'
        ]);

        $conflicts = [];
        $slugs_data = [];

        // Lista de sufixos comuns que "diluem" a palavra-chave principal
        $suffixes = [
            'receita', 'facil', 'passo-a-passo', 'caseiro', 'fofinho', 'simples', 'rapido', 
            'melhor', 'tradicional', 'cremoso', 'delicioso', 'completo', 'original'
        ];
        $suffix_pattern = '/-(' . implode('|', $suffixes) . ')$/';

        foreach ($posts as $post_id) {
            $has_canonical = get_post_meta($post_id, '_sts_seo_canonical', true);
            if (!empty($has_canonical)) continue;

            $slug = get_post_field('post_name', $post_id);
            $type = get_post_type($post_id);
            
            // Normalização Avançada
            $norm = preg_replace($suffix_pattern, '', $slug);
            $norm = preg_replace($suffix_pattern, '', $norm); // Rodar 2x para casos como "-facil-receita"
            
            // Remover plurais básicos (simplista mas eficaz para slugs)
            $norm = preg_replace('/(s|es)$/', '', $norm);

            $slugs_data[$post_id] = [
                'id' => $post_id,
                'slug' => $slug,
                'type' => $type,
                'url' => get_permalink($post_id),
                'norm' => $norm
            ];
        }

        $ids = array_keys($slugs_data);
        for ($i = 0; $i < count($ids); $i++) {
            for ($j = $i + 1; $j < count($ids); $j++) {
                $a = $slugs_data[$ids[$i]];
                $b = $slugs_data[$ids[$j]];

                $is_direct_match = ($a['norm'] === $b['norm']);
                $is_partial_match = (strlen($a['norm']) > 5 && (strpos($b['norm'], $a['norm']) !== false || strpos($a['norm'], $b['norm']) !== false));

                if ($is_direct_match || $is_partial_match) {
                    $conflicts[] = [
                        'keyword' => $a['norm'],
                        'post1' => $a['slug'],
                        'post2' => $b['slug'],
                        'type1' => $a['type'],
                        'type2' => $b['type'],
                        'id1' => $a['id'],
                        'url2' => $b['url'],
                        'status' => $is_direct_match ? 'CRÍTICO' : 'ATENÇÃO'
                    ];
                }
            }
        }
        wp_send_json_success(['total_posts' => count($posts), 'conflicts' => $conflicts]);
    }
}
