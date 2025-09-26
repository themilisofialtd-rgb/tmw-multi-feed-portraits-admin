<?php
/**
 * Plugin Name: TMW Multi-Feed Portraits — Admin
 * Description: Appends performerId to your AWE feed URLs using actor names from your site (auto-updating). Tools include front/back overrides, LIVE horizontal alignment + zoom per side, and manual uploads.
 * Version:     4.0.0
 * Author:      TMW
 * License:     GPL-2.0+
 */

if (!defined('ABSPATH')) exit;

/**
 * Ultra-safe bootstrap:
 * - No namespaces
 * - No PHP 7-only syntax (no ??, etc.)
 * - Unique function prefixes (tmw147d_) to avoid collisions
 * - Early exit if another copy already loaded
 */
if (defined('TMW20_LOADED')) return;
define('TMW20_LOADED', '2.0.0');

define('TMW147D_OPT', 'tmw_mf_settings');
define('TMW147D_OPT_GROUP', 'tmw_mf_options');
define('TMW147D_SLUG', 'tmw-mf');
define('TMW147D_CRON', 'tmw_mf_daily_sync_147d');

function tmw147d_get_settings() {
    $defaults = array(
        'safe_base' => '',
        'expl_base' => '',
        'compiled_safe' => '',
        'compiled_expl' => '',
        'auto_append_performers' => 1,
        'performers' => array(),
        'front_overrides' => array(),
        'back_overrides'  => array(),
        'object_pos_front' => array(),
        'object_pos_back'  => array(),
        'zoom_front' => array(),
        'zoom_back'  => array(),
        'uploads'    => array(),
    );
    $opt = get_option(TMW147D_OPT, array());
    if (!is_array($opt)) $opt = array();
    return array_merge($defaults, $opt);
}
function tmw147d_update_settings($new){
    $cur = tmw147d_get_settings();
    $merged = array_merge($cur, is_array($new)?$new:array());
    update_option(TMW147D_OPT, $merged);
    return $merged;
}
function tmw147d_get_feed_urls(){
    $s = tmw147d_get_settings();
    $safe = !empty($s['compiled_safe']) ? $s['compiled_safe'] : $s['safe_base'];
    $expl = !empty($s['compiled_expl']) ? $s['compiled_expl'] : $s['expl_base'];
    return array($safe, $expl);
}

register_activation_hook(__FILE__, 'tmw147d_on_activate');
function tmw147d_on_activate(){
    if (!wp_next_scheduled(TMW147D_CRON)) {
        wp_schedule_event(time()+3600, 'twicedaily', TMW147D_CRON);
    }
}
register_deactivation_hook(__FILE__, 'tmw147d_on_deactivate');
function tmw147d_on_deactivate(){
    $t = wp_next_scheduled(TMW147D_CRON);
    if ($t) wp_unschedule_event($t, TMW147D_CRON);
}
add_action(TMW147D_CRON, 'tmw147d_cron_sync');
function tmw147d_cron_sync(){
    $s = tmw147d_get_settings();
    $performers = tmw147d_collect_performers();
    $s['performers'] = $performers;
    if (!empty($s['auto_append_performers'])){
        list($safe,$expl) = tmw147d_compile_urls($s['safe_base'],$s['expl_base'],$performers);
        $s['compiled_safe'] = $safe;
        $s['compiled_expl'] = $expl;
    }
    tmw147d_update_settings($s);
}

add_action('plugins_loaded', 'tmw147d_define_back_compat_constants');
function tmw147d_define_back_compat_constants(){
    list($safe,$expl) = tmw147d_get_feed_urls();
    if (!defined('AWEMPIRE_FEED_URL_SAFE')) define('AWEMPIRE_FEED_URL_SAFE', $safe);
    if (!defined('AWEMPIRE_FEED_URL_EXPL')) define('AWEMPIRE_FEED_URL_EXPL', $expl);
}

add_action('admin_init', function(){ register_setting(TMW147D_OPT_GROUP, TMW147D_OPT); });
add_action('admin_menu', function(){
    add_menu_page('Flipbox (TMW)', 'Flipbox (TMW)', 'manage_options', TMW147D_SLUG, 'tmw147d_page_settings', 'dashicons-images-alt2', 56);
    add_submenu_page(TMW147D_SLUG, 'Settings', 'Settings', 'manage_options', TMW147D_SLUG, 'tmw147d_page_settings');
    add_submenu_page(TMW147D_SLUG, 'Tools', 'Tools', 'manage_options', TMW147D_SLUG.'-tools', 'tmw147d_page_tools');
});

/** Build query but keep commas in performerId */
function tmw147d_build_query($q){
    $out = array();
    foreach($q as $k=>$v){
        $ek = rawurlencode($k);
        $ev = rawurlencode($v);
        if ($k === 'performerId') $ev = str_replace('%2C', ',', $ev);
        $out[] = $ek.'='.$ev;
    }
    return implode('&', $out);
}
function tmw147d_with_query($url, $args){
    $p = wp_parse_url($url);
    $q = array();
    if (!empty($p['query'])) parse_str($p['query'], $q);
    foreach($args as $k=>$v){
        if ($v===null) unset($q[$k]); else $q[$k] = $v;
    }
    $scheme = isset($p['scheme']) ? $p['scheme'].'://' : (isset($p['host']) ? '//' : '');
    $host = isset($p['host']) ? $p['host'] : '';
    $port = isset($p['port']) ? ':'.$p['port'] : '';
    $path = isset($p['path']) ? $p['path'] : '';
    $query = !empty($q) ? '?'.tmw147d_build_query($q) : '';
    $fragment = isset($p['fragment']) ? '#'.$p['fragment'] : '';
    return $scheme.$host.$port.$path.$query.$fragment;
}
function tmw147d_compile_urls($safe_base,$expl_base,$performers){
    $list = implode(',', array_unique(array_filter(array_map('trim',$performers))));
    $safe = $safe_base ? tmw147d_with_query($safe_base, array('performerId'=>$list)) : '';
    $expl = $expl_base ? tmw147d_with_query($expl_base, array('performerId'=>$list)) : '';
    return array($safe,$expl);
}
function tmw147d_collect_performers($limit=10000,$timeout=6){
    $performers = array();
    $terms = get_terms(array('taxonomy'=>'actors','hide_empty'=>false,'number'=>$limit));
    if (is_wp_error($terms)) return array();
    foreach($terms as $t){
        $nick = get_post_meta($t->term_id, 'tmw_lj_nick', true);
        if (!$nick){
            $link = get_permalink($t, 'actors');
            if (!is_wp_error($link)){
                $res = wp_remote_get($link, array('timeout'=>$timeout));
                if (!is_wp_error($res) && isset($res['body'])){
                    if (preg_match('~livejasmin\.com/(?:[^"\']*/)?chat/([A-Za-z0-9_-]+)~i', $res['body'], $m)){
                        $nick = $m[1];
                        update_post_meta($t->term_id, 'tmw_lj_nick', $nick);
                    }
                }
            }
        }
        if (!$nick){
            $name = $t->name;
            $nick = remove_accents($name);
            $nick = preg_replace('~[^A-Za-z0-9]+~','', $nick);
        }
        if ($nick) $performers[] = $nick;
    }
    return array_values(array_unique($performers));
}

/** Settings page */
function tmw147d_page_settings(){
    if (!current_user_can('manage_options')) return;
    $s = tmw147d_get_settings();
    if ($_SERVER['REQUEST_METHOD']==='POST' && check_admin_referer('tmw_mf_save','tmw_mf_nonce')){
        $s['safe_base'] = esc_url_raw(isset($_POST['safe_base'])?$_POST['safe_base']:'');
        $s['expl_base'] = esc_url_raw(isset($_POST['expl_base'])?$_POST['expl_base']:'');
        $s['auto_append_performers'] = isset($_POST['auto_append_performers']) ? 1 : 0;
        if (isset($_POST['compile_now'])){
            list($safe,$expl) = tmw147d_compile_urls($s['safe_base'],$s['expl_base'],$s['performers']);
            $s['compiled_safe'] = $safe; $s['compiled_expl'] = $expl;
            add_settings_error('tmw_mf','compiled','Compiled URLs updated with performerId list.','updated');
        }
        tmw147d_update_settings($s);
        add_settings_error('tmw_mf','saved','Settings saved.','updated');
    }
    if (isset($_GET['tmw_mf_refresh'])){
        $performers = tmw147d_collect_performers();
        $s['performers'] = $performers;
        if (!empty($s['auto_append_performers'])){
            list($safe,$expl) = tmw147d_compile_urls($s['safe_base'],$s['expl_base'],$performers);
            $s['compiled_safe'] = $safe; $s['compiled_expl'] = $expl;
        }
        tmw147d_update_settings($s);
        add_settings_error('tmw_mf','tmw_mf_refreshed', sprintf('Performer list refreshed. Found %d names.', count($performers)), 'updated');
    }
    settings_errors('tmw_mf');
    $urls = tmw147d_get_feed_urls(); $safe=$urls[0]; $expl=$urls[1];
    ?>
    <div class="wrap"><h1>Flipbox (TMW) — Settings</h1>
    <form method="post" action=""><?php wp_nonce_field('tmw_mf_save','tmw_mf_nonce'); ?>
    <table class="form-table">
      <tr><th><label for="safe_base">AWE SAFE feed URL (base)</label></th><td><input name="safe_base" id="safe_base" type="url" class="regular-text code" style="width:100%" value="<?php echo esc_attr($s['safe_base']); ?>"><p class="description">Paste your Non-Explicit base link EXACTLY as AWE gives it. This plugin will only change <code>performerId</code>.</p></td></tr>
      <tr><th><label for="expl_base">AWE EXPLICIT feed URL (base)</label></th><td><input name="expl_base" id="expl_base" type="url" class="regular-text code" style="width:100%" value="<?php echo esc_attr($s['expl_base']); ?>"><p class="description">Paste your Explicit base link EXACTLY as AWE gives it. Nothing else will be modified.</p></td></tr>
      <tr><th>Auto-append performers</th><td><label><input type="checkbox" name="auto_append_performers" value="1" <?php checked($s['auto_append_performers']); ?>> Keep the compiled URLs updated when actors are added/removed.</label></td></tr>
    </table>
    <p class="submit"><button class="button button-primary" name="save">Save Settings</button> <a class="button" href="<?php echo esc_url(admin_url('admin.php?page='.TMW147D_SLUG.'&tmw_mf_refresh=1')); ?>">Refresh performer list now</a> <button class="button" name="compile_now">Compile URLs now</button></p>
    </form>
    <h2>Current feed URLs</h2>
    <p><strong>SAFE (Non-Explicit):</strong><br><code style="display:block;word-wrap:anywhere;"><?php echo esc_html($safe); ?></code></p>
    <p><strong>EXPLICIT:</strong><br><code style="display:block;word-wrap:anywhere;"><?php echo esc_html($expl); ?></code></p>
    </div><?php
}

/** Tools page */
function tmw147d_json_collect_images($node,&$out){
    if (is_array($node)) { foreach($node as $v) tmw147d_json_collect_images($v,$out); }
    elseif (is_object($node)) { foreach(get_object_vars($node) as $v) tmw147d_json_collect_images($v,$out); }
    elseif (is_string($node)) { if (preg_match('~^https?://[^\s"]+\.(?:jpg|jpeg|png|webp|gif)(?:\?[^"\s]*)?$~i',$node)) $out[]=$node; }
}
function tmw147d_style_for_nick($nick,$side){
    $s = tmw147d_get_settings();
    $pos = ($side==='back') ? (isset($s['object_pos_back'][$nick])?floatval($s['object_pos_back'][$nick]):50) : (isset($s['object_pos_front'][$nick])?floatval($s['object_pos_front'][$nick]):50);
    $zoom = ($side==='back') ? (isset($s['zoom_back'][$nick])?floatval($s['zoom_back'][$nick]):1.0) : (isset($s['zoom_front'][$nick])?floatval($s['zoom_front'][$nick]):1.0);
    $pos = max(0,min(100,$pos)); $zoom=max(1.0,min(2.5,$zoom));
    $style = sprintf('object-position: %.2f%% 50%%;', $pos);
    if ($zoom>1.0) $style .= sprintf('transform: scale(%.3f); transform-origin: %.2f%% 50%%;', $zoom, $pos);
    return $style;
}
function tmw147d_page_tools(){
    if (!current_user_can('manage_options')) return;
    $s = tmw147d_get_settings();

    if ($_SERVER['REQUEST_METHOD']==='POST' && check_admin_referer('tmw_mf_tools','tmw_mf_tools_nonce')){
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/media.php';
        require_once ABSPATH.'wp-admin/includes/image.php';

        $nick = sanitize_text_field(isset($_POST['nick'])?$_POST['nick']:'');
        if ($nick){
            if (isset($_POST['front_url'])) $s['front_overrides'][$nick] = esc_url_raw($_POST['front_url']);
            if (isset($_POST['back_url']))  $s['back_overrides'][$nick]  = esc_url_raw($_POST['back_url']);
            if (isset($_POST['object_pos_front'])) $s['object_pos_front'][$nick] = floatval($_POST['object_pos_front']);
            if (isset($_POST['object_pos_back']))  $s['object_pos_back'][$nick]  = floatval($_POST['object_pos_back']);
            if (isset($_POST['zoom_front'])) $s['zoom_front'][$nick] = floatval($_POST['zoom_front']);
            if (isset($_POST['zoom_back']))  $s['zoom_back'][$nick]  = floatval($_POST['zoom_back']);
            if (!empty($_FILES['front_upload']['name'])){
                $id = media_handle_upload('front_upload',0);
                if (!is_wp_error($id)){ $s['uploads'][$nick]['front']=$id; $s['front_overrides'][$nick]=wp_get_attachment_url($id); }
            }
            if (!empty($_FILES['back_upload']['name'])){
                $id = media_handle_upload('back_upload',0);
                if (!is_wp_error($id)){ $s['uploads'][$nick]['back']=$id; $s['back_overrides'][$nick]=wp_get_attachment_url($id); }
            }
            tmw147d_update_settings($s);
            add_settings_error('tmw_mf','saved_tools','Overrides updated for '.$nick,'updated');
        }
    }
    settings_errors('tmw_mf');

    $test_nick = isset($_GET['test_nick']) ? sanitize_text_field($_GET['test_nick']) : '';
    $images = array();
    if ($test_nick){
        $base_safe = $s['safe_base']; $base_expl = $s['expl_base'];
        $test_safe = $base_safe ? tmw147d_with_query($base_safe, array('performerId'=>$test_nick)) : '';
        $test_expl = $base_expl ? tmw147d_with_query($base_expl, array('performerId'=>$test_nick)) : '';
        foreach(array($test_safe,$test_expl) as $u){
            if (!$u) continue;
            $res = wp_remote_get($u, array('timeout'=>15,'headers'=>array('Accept'=>'application/json')));
            if (!is_wp_error($res) && isset($res['body'])){
                $json = json_decode($res['body']);
                $arr = array();
                if (isset($json->data->models[0]->images)) $arr = $json->data->models[0]->images;
                elseif (isset($json->data->models[0]->image)) $arr = $json->data->models[0]->image;
                elseif (isset($json->models[0]->images)) $arr = $json->models[0]->images;
                elseif (isset($json->models[0]->image)) $arr = $json->models[0]->image;
                if (!empty($arr)){ if (is_string($arr)) $images[]=$arr; elseif (is_array($arr)) foreach($arr as $img) $images[]=$img; }
                $extra = array(); tmw147d_json_collect_images($json,$extra); foreach($extra as $e) $images[]=$e;
            }
        }
        // de-dup by path
        $seen=array(); $uniq=array();
        foreach($images as $img){
            $u = preg_replace('~/(?:\d{3,4}x\d{3,4})/~','/',$img);
            $u = preg_replace('~([-_]\d{3,4}x\d{3,4})(?=\.[a-z]+$)~i','',$u);
            $u = strtolower(parse_url($u, PHP_URL_PATH));
            if (!isset($seen[$u])){ $seen[$u]=1; $uniq[]=$img; }
        }
        $images=$uniq;
    }

    $pos_front = isset($s['object_pos_front'][$test_nick]) ? $s['object_pos_front'][$test_nick] : 50;
    $pos_back  = isset($s['object_pos_back'][$test_nick]) ? $s['object_pos_back'][$test_nick] : 50;
    $zoom_front= isset($s['zoom_front'][$test_nick]) ? $s['zoom_front'][$test_nick] : 1.0;
    $zoom_back = isset($s['zoom_back'][$test_nick]) ? $s['zoom_back'][$test_nick] : 1.0;

    ?>
    <div class="wrap"><h1>Flipbox (TMW) — Tools</h1>
    <form method="get" action=""><input type="hidden" name="page" value="<?php echo esc_attr(TMW147D_SLUG.'-tools'); ?>"><p><label>Test nickname:&nbsp;<input type="text" name="test_nick" value="<?php echo esc_attr($test_nick); ?>" placeholder="e.g. Anisyia"></label> <button class="button">Fetch images</button></p></form>
    <?php if ($test_nick): ?>
      <h2><?php echo esc_html($test_nick); ?></h2>
      <div style="display:flex;gap:30px;align-items:flex-start;">
        <div>
          <h3>Preview</h3>
          <div style="display:flex;gap:20px;">
            <div><div class="tmw-card" style="width:300px;height:450px;background:#111;border-radius:8px;overflow:hidden;display:flex;align-items:center;justify-content:center;"><img id="tmw-front" src="<?php echo esc_url(isset($s['front_overrides'][$test_nick])?$s['front_overrides'][$test_nick]:(isset($images[0])?$images[0]:'')); ?>" style="width:100%;height:100%;object-fit:cover;<?php echo esc_attr(tmw147d_style_for_nick($test_nick,'front')); ?>"></div><p style="text-align:center;margin-top:6px;">Front</p></div>
            <div><div class="tmw-card" style="width:300px;height:450px;background:#111;border-radius:8px;overflow:hidden;display:flex;align-items:center;justify-content:center;"><img id="tmw-back" src="<?php echo esc_url(isset($s['back_overrides'][$test_nick])?$s['back_overrides'][$test_nick]:(isset($images[1])?$images[1]:'')); ?>" style="width:100%;height:100%;object-fit:cover;<?php echo esc_attr(tmw147d_style_for_nick($test_nick,'back')); ?>"></div><p style="text-align:center;margin-top:6px;">Back</p></div>
          </div>
        </div>
        <div>
          <h3>Set overrides / alignment (LIVE)</h3>
          <form method="post" enctype="multipart/form-data" id="tmw-tools-form"><?php wp_nonce_field('tmw_mf_tools','tmw_mf_tools_nonce'); ?><input type="hidden" name="nick" value="<?php echo esc_attr($test_nick); ?>">
            <p><label>Front URL<br><input id="front_url" type="url" name="front_url" class="large-text code" value="<?php echo esc_attr(isset($s['front_overrides'][$test_nick])?$s['front_overrides'][$test_nick]:''); ?>"></label></p>
            <p><label>Back URL<br><input id="back_url" type="url" name="back_url" class="large-text code" value="<?php echo esc_attr(isset($s['back_overrides'][$test_nick])?$s['back_overrides'][$test_nick]:''); ?>"></label></p>
            <fieldset style="border:1px solid #ccd0d4;padding:10px;margin-top:10px;"><legend><strong>Front controls</strong></legend>
              <p><label>Horizontal position<br><input id="pos_front" type="range" min="0" max="100" step="1" name="object_pos_front" value="<?php echo esc_attr($pos_front); ?>"></label></p>
              <p><label>Zoom (1.0–2.5)<br><input id="zoom_front" type="number" min="1" max="2.5" step="0.05" name="zoom_front" value="<?php echo esc_attr($zoom_front); ?>"></label></p>
            </fieldset>
            <fieldset style="border:1px solid #ccd0d4;padding:10px;margin-top:10px;"><legend><strong>Back controls</strong></legend>
              <p><label>Horizontal position<br><input id="pos_back" type="range" min="0" max="100" step="1" name="object_pos_back" value="<?php echo esc_attr($pos_back); ?>"></label></p>
              <p><label>Zoom (1.0–2.5)<br><input id="zoom_back" type="number" min="1" max="2.5" step="0.05" name="zoom_back" value="<?php echo esc_attr($zoom_back); ?>"></label></p>
            </fieldset>
            <p style="margin-top:10px;"><strong>Or upload images</strong></p>
            <p><label>Front upload: <input type="file" name="front_upload" accept="image/*"></label></p>
            <p><label>Back upload: <input type="file" name="back_upload" accept="image/*"></label></p>
            <p><button class="button button-primary">Save overrides</button></p>
          </form>
        </div>
      </div>
      <?php if (!empty($images)): ?>
        <h3 style="margin-top:30px;">All distinct images found</h3>
        <div style="display:flex;flex-wrap:wrap;gap:16px;"><?php foreach($images as $img): ?><div style="width:200px;"><div style="width:200px;height:300px;border-radius:10px;overflow:hidden;background:#111;display:flex;align-items:center;justify-content:center;"><img src="<?php echo esc_url($img); ?>" style="width:100%;height:100%;object-fit:cover;"></div>
        <form method="post" style="display:flex;gap:6px;margin-top:6px;"><?php wp_nonce_field('tmw_mf_tools','tmw_mf_tools_nonce'); ?><input type="hidden" name="nick" value="<?php echo esc_attr($test_nick); ?>"><input type="hidden" name="front_url" value="<?php echo esc_attr($img); ?>"><button class="button">Set Front</button></form>
        <form method="post" style="display:flex;gap:6px;margin-top:6px;"><?php wp_nonce_field('tmw_mf_tools','tmw_mf_tools_nonce'); ?><input type="hidden" name="nick" value="<?php echo esc_attr($test_nick); ?>"><input type="hidden" name="back_url" value="<?php echo esc_attr($img); ?>"><button class="button">Set Back</button></form>
        </div><?php endforeach; ?></div>
      <?php else: ?><p><em>No images came from the feed for this nickname. Open your base URLs directly with <code>&amp;performerId=<?php echo esc_html($test_nick); ?></code> to confirm.</em></p><?php endif; ?>
      <script>(function(){function A(s){var p=parseFloat(document.getElementById('pos_'+s).value||50);var z=parseFloat(document.getElementById('zoom_'+s).value||1);var img=document.getElementById('tmw-'+s);if(!img) return; img.style.objectPosition=p.toFixed(2)+'% 50%'; img.style.transform='scale('+z.toFixed(3)+')'; img.style.transformOrigin=p.toFixed(2)+'% 50%';} ['front','back'].forEach(function(s){var r=document.getElementById('pos_'+s);var z=document.getElementById('zoom_'+s); if(r){r.addEventListener('input',function(){A(s);});} if(z){z.addEventListener('input',function(){A(s);});} A(s); }); var fu=document.getElementById('front_url'), bu=document.getElementById('back_url'); if(fu){fu.addEventListener('change',function(){var img=document.getElementById('tmw-front'); if(img&&fu.value) img.src=fu.value;});} if(bu){bu.addEventListener('change',function(){var img=document.getElementById('tmw-back'); if(img&&bu.value) img.src=bu.value;});} })();</script>
    <?php endif; ?>
    <hr><h2>Performer list</h2><p>Total: <?php echo number_format_i18n(count($s['performers'])); ?> names.</p><textarea class="large-text code" rows="5" readonly><?php echo esc_textarea(implode(',', $s['performers'])); ?></textarea><p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page='.TMW147D_SLUG.'&tmw_mf_refresh=1')); ?>">Refresh performer list now</a></p>
    </div><?php
}
