<?php
namespace STSCannibal\Admin;

use STSCannibal\Core\DeepAnalyzer;
use STSCannibal\Core\Database;

class EditorScout {
    
    public function __construct() {
        add_action('add_meta_boxes', [$this, 'add_scout_meta_box']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_editor_assets']);
        add_action('wp_ajax_sts_scout_check_cannibal', [$this, 'ajax_check_cannibal']);
    }

    public function add_scout_meta_box() {
        $screens = ['post', 'receita']; // Target screens
        foreach ($screens as $screen) {
            add_meta_box(
                'sts-editor-scout',
                '🛡️ Scout de Canibalização [GOD MODE]',
                [$this, 'render_meta_box'],
                $screen,
                'side',
                'high'
            );
        }
    }

    public function enqueue_editor_assets($hook) {
        if (!in_array($hook, ['post.php', 'post-new.php'])) return;

        wp_add_inline_style('wp-admin', "
            #sts-scout-status { padding:15px; border-radius:8px; margin-top:10px; border:1px solid #e2e8f0; transition:0.3s; }
            .sts-scout-safe { background:#f0fff4; border-color:#9ae6b4!important; color:#22543d; }
            .sts-scout-danger { background:#fff5f5; border-color:#feb2b2!important; color:#822727; }
            .sts-scout-pulse { width:12px; height:12px; border-radius:50%; display:inline-block; margin-right:8px; vertical-align:middle; }
            .sts-pulse-green { background:#48bb78; box-shadow:0 0 0 rgba(72,187,120,0.4); animation: stsPulse 2s infinite; }
            .sts-pulse-red { background:#f56565; box-shadow:0 0 0 rgba(245,101,101,0.4); animation: stsPulseRed 2s infinite; }
            @keyframes stsPulse { 0% { box-shadow:0 0 0 0 rgba(72,187,120,0.4); } 70% { box-shadow:0 0 0 10px rgba(72,187,120,0); } 100% { box-shadow:0 0 0 0 rgba(72,187,120,0); } }
            @keyframes stsPulseRed { 0% { box-shadow:0 0 0 0 rgba(245,101,101,0.4); } 70% { box-shadow:0 0 0 10px rgba(245,101,101,0); } 100% { box-shadow:0 0 0 0 rgba(245,101,101,0); } }
            .sts-conflict-item { margin-top:10px; font-size:12px; padding-top:10px; border-top:1px dashed #feb2b2; }
            .sts-cpc-tag { display:inline-block; background:#2d3748; color:#fff; padding:2px 6px; border-radius:4px; font-size:10px; font-weight:bold; margin-top:5px; }
        ");

        $nonce = wp_create_nonce('sts_scout_nonce');
        
        $js = <<<JS
            jQuery(document).ready(function($) {
                let scoutTimer;
                
                const getTitle = () => {
                    if (window.wp && wp.data && wp.data.select('core/editor')) {
                        const title = wp.data.select('core/editor').getEditedPostAttribute('title');
                        if (title) return title;
                    }
                    return $('#title').val() || $('.editor-post-title__input').text() || $('.editor-post-title textarea').val() || '';
                };

                const checkCannibal = () => {
                    const title = getTitle();
                    const postId = $('#post_ID').val();
                    
                    if (!title || title.length < 3) {
                        $('#sts-scout-status').html('Aguardando título para análise...');
                        return;
                    }

                    $('#sts-scout-status').html('<span class="spinner is-active" style="float:none; margin:0 5px 0 0;"></span> Analisando...').css('opacity', 0.8);
                    
                    $.post(ajaxurl, {
                        action: 'sts_scout_check_cannibal',
                        title: title,
                        post_id: postId,
                        _ajax_nonce: '{$nonce}'
                    }, function(res) {
                        $('#sts-scout-status').css('opacity', 1);
                        if (res.success) {
                            const data = res.data;
                            let html = '';
                            if (data.conflict) {
                                $('#sts-scout-status').removeClass('sts-scout-safe').addClass('sts-scout-danger');
                                html = `<div><span class='sts-scout-pulse sts-pulse-red'></span><strong>ALERTA DE CONFLITO!</strong></div>`;
                                html += `<div class='sts-conflict-item'>Similaridade: <strong>\${data.similarity}%</strong><br>Com: <a href='\${data.url}' target='_blank'>\${data.title}</a></div>`;
                                if(data.cpc) html += `<div class='sts-cpc-tag'>💰 CPC Sugerido: \${data.cpc}</div>`;
                            } else {
                                $('#sts-scout-status').removeClass('sts-scout-danger').addClass('sts-scout-safe');
                                html = `<div><span class='sts-scout-pulse sts-pulse-green'></span><strong>URL BLINDADA</strong></div>`;
                                html += `<p style='font-size:11px; margin-top:5px;'>Estrutura de autoridade segura.</p>`;
                                if(data.cpc) html += `<div class='sts-cpc-tag'>💰 CPC Estimado: \${data.cpc}</div>`;
                            }
                            $('#sts-scout-status').html(html);
                        }
                    });
                };

                $('#title').on('blur change input', function() {
                    clearTimeout(scoutTimer);
                    scoutTimer = setTimeout(checkCannibal, 800);
                });

                if (window.wp && wp.data && wp.data.subscribe) {
                    wp.data.subscribe(() => {
                        const newTitle = getTitle();
                        if (newTitle && newTitle !== window.sts_last_title) {
                            window.sts_last_title = newTitle;
                            clearTimeout(scoutTimer);
                            scoutTimer = setTimeout(checkCannibal, 1200);
                        }
                    });
                }

                $(window).on('load', function() {
                    setTimeout(checkCannibal, 1500);
                });
                setTimeout(checkCannibal, 500);
            });
JS;
        wp_add_inline_script('wp-admin', $js);
    }

    public function render_meta_box($post) {
        echo '<div id="sts-scout-status">Aguardando título para análise...</div>';
    }

    public function ajax_check_cannibal() {
        check_ajax_referer('sts_scout_nonce');
        
        $title = sanitize_text_field($_POST['title']);
        $current_post_id = (int)$_POST['post_id'];
        
        $norm_title = DeepAnalyzer::normalize($title);
        
        global $wpdb;
        $table_name = Database::get_table_name();
        
        $candidates = $wpdb->get_results("SELECT post_id, norm_title FROM $table_name WHERE post_id != $current_post_id");
        
        $best_match = null;
        $highest_sim = 0;

        foreach ($candidates as $c) {
            $sim = DeepAnalyzer::get_similarity($norm_title, $c->norm_title);
            if ($sim > 82 && $sim > $highest_sim) {
                $highest_sim = $sim;
                $best_match = $c;
            }
        }

        $cpc_keywords = ['premium'=>'$0.85', 'gourmet'=>'$0.62', 'fit'=>'$0.45', 'vegano'=>'$0.55', 'saudavel'=>'$0.40', 'facil'=>'$0.12'];
        $estimated_cpc = '$0.08';
        foreach($cpc_keywords as $k => $v) {
            if (stripos($title, $k) !== false) { $estimated_cpc = $v; break; }
        }

        if ($best_match) {
            wp_send_json_success([
                'conflict' => true,
                'similarity' => round($highest_sim, 1),
                'title' => get_the_title($best_match->post_id),
                'url' => get_permalink($best_match->post_id),
                'cpc' => $estimated_cpc
            ]);
        } else {
            wp_send_json_success([
                'conflict' => false,
                'cpc' => $estimated_cpc
            ]);
        }
    }
}
