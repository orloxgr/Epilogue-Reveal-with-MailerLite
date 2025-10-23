<?php
/*
Plugin Name: Epilogue Reveal with MailerLite
Description: Hides content of selected post types until user subscribes via MailerLite, based on product_author taxonomy.
Author: Byron Iniotakis
Version: 1.251023.02
*/

// ============================
// 1) Admin Settings
// ============================
add_action('admin_menu', function () {
    add_options_page(
        'Epilogue Reveal Settings',
        'Epilogue Reveal',
        'manage_options',
        'epilogue-reveal-settings',
        'ep_reveal_settings_page'
    );
});

function ep_reveal_settings_page()
{
    if (isset($_POST['ep_reveal_save'])) {
        update_option('ep_reveal_post_types', isset($_POST['post_types']) ? (array) $_POST['post_types'] : []);

        $raw_forms = [];
        if (isset($_POST['author_forms'])) {
            foreach ((array) $_POST['author_forms'] as $slug => $html) {
                // Unescape quotes and preserve scripts exactly as pasted
                $raw_forms[$slug] = wp_unslash($html);
            }
        }
        update_option('ep_reveal_author_forms', wp_json_encode($raw_forms));

        echo '<div class="updated"><p>Settings saved.</p></div>';
    }

    $post_types   = get_post_types(['public' => true], 'objects');
    $selected     = (array) get_option('ep_reveal_post_types', []);
    $author_forms = json_decode(get_option('ep_reveal_author_forms', '{}'), true);

    echo '<div class="wrap"><h1>Epilogue Reveal Settings</h1>';
    echo '<form method="post">';

    // Debug tool: clear unlock cookies in current browser
    if (isset($_POST['ep_reveal_debug_cookie_all'])) {
        echo '<div class="updated"><p>All ep_reveal_* cookies cleared (browser only).</p></div>';
        echo '<script>
            document.cookie.split(";").forEach(function(cookie) {
                var n = cookie.split("=")[0].trim();
                if (n.indexOf("ep_reveal_") === 0) {
                    document.cookie = n + "=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
                }
            });
        </script>';
    }

    echo '<h3>Debug Tools</h3>';
    echo '<p><input type="submit" name="ep_reveal_debug_cookie_all" class="button" value="Clear All Unlock Cookies"></p>';

    echo '<h3>Select Post Types</h3><ul style="columns:2; -webkit-columns:2; -moz-columns:2;">';
    foreach ($post_types as $type) {
        $checked = in_array($type->name, $selected, true) ? 'checked' : '';
        echo "<li><label><input type='checkbox' name='post_types[]' value='" . esc_attr($type->name) . "' $checked> " . esc_html($type->label) . "</label></li>";
    }
    echo '</ul>';

    echo '<h3>Author Form Mapping</h3>';
    echo '<p>Paste the MailerLite form HTML for each author.</p>';
    echo '<div id="ep-author-mapping">';
    $all_authors = get_terms(['taxonomy' => 'product_author', 'hide_empty' => false]);
    if (!is_wp_error($all_authors)) {
        foreach ($all_authors as $author_term) {
            $slug      = $author_term->slug;
            $form_html = isset($author_forms[$slug]) ? $author_forms[$slug] : '';
            echo "<div style='margin:8px 0;padding:8px;border:1px solid #ddd;background:#fff;'>";
            echo "<label style='display:inline-block;width:220px;font-weight:bold;'>Slug:</label><input type='text' value='" . esc_attr($slug) . "' readonly style='width:260px;'> ";
            echo "<div style='margin-top:6px;'><textarea name='author_forms[" . esc_attr($slug) . "]' rows='6' cols='90' placeholder='MailerLite HTML'>" . esc_textarea($form_html) . "</textarea></div>";
            echo "</div>";
        }
    } else {
        echo '<p><em>No product_author terms found.</em></p>';
    }
    echo '</div>';

    echo '<p><input type="submit" name="ep_reveal_save" class="button-primary" value="Save Settings"></p>';
    echo '</form></div>';
}

// ============================
// 2) Shortcode
// ============================
function ep_reveal_shortcode($atts, $content = null)
{
    global $post;
    if (!is_singular()) return '';

    $allowed = (array) get_option('ep_reveal_post_types', []);
    if (!$post || !in_array($post->post_type, $allowed, true)) {
        return do_shortcode($content);
    }

    // Find author slug early (used by both PHP and JS)
    $terms  = get_the_terms($post->ID, 'product_author');
    $author = (!empty($terms) && !is_wp_error($terms)) ? $terms[0]->slug : 'unknown';

    // Accept either cookie (post-based OR author-based)
    $unlocked = (
        isset($_COOKIE['ep_reveal_' . $post->ID]) ||
        isset($_COOKIE['ep_reveal_author_' . $author])
    );
    if ($unlocked) {
        return do_shortcode($content);
    }

    // Fetch the author-specific form
    $forms = json_decode(get_option('ep_reveal_author_forms', '{}'), true);
    $form  = isset($forms[$author]) ? $forms[$author] : '';

    if (empty($form)) {
        return '<div class="ep-reveal-form"><p><strong>No form is configured for author: ' . esc_html($author) . '.</strong></p></div>';
    }

    ob_start();
    echo '<div class="ep-reveal-form-wrapper">';
    // RAW embed code (allow script tags)
    echo $form;
    echo '</div>';

    // Prepare safe variables for JS
    $ajax      = admin_url('admin-ajax.php');
    $nonce     = wp_create_nonce('ep_reveal_proxy');
    $js_author = esc_js($author);
    $js_ajax   = esc_url($ajax);
    $js_postid = (int) $post->ID;

    // Inline JS (proxy-aware)
    echo <<<EOD
<script>
document.addEventListener("DOMContentLoaded", function () {
  var DEBUG = /[?&]epilogue_debug=1/.test(location.search);
  function dbg(){ if (DEBUG) console.log.apply(console, ['[EpilogueReveal]'].concat([].slice.call(arguments))); }

  var EP = {
    ajaxUrl:  "{$js_ajax}",
    nonce:    "{$nonce}",
    postId:   {$js_postid},
    author:   "{$js_author}"
  };

  function setUnlockCookies(){
    var domain = location.hostname.replace(/^www\\./,'');
    var base = "; path=/; max-age=" + (60*60*24*365) + "; domain=." + domain + "; SameSite=Lax";
    if (location.protocol === 'https:') base += "; Secure";
    // by post id
    document.cookie = "ep_reveal_" + EP.postId + "=1" + base;
    // by author (helps when Elementor swaps IDs/templates)
    if (EP.author) document.cookie = "ep_reveal_author_" + EP.author + "=1" + base;
    dbg('Cookies set for post & author');
  }

  function unlock(){
    setUnlockCookies();
    location.reload();
  }

  var forms = document.querySelectorAll(".ep-reveal-form-wrapper form");
  if (!forms.length) {
    dbg('No <form> found inside .ep-reveal-form-wrapper');
  }

  forms.forEach(function(form){
    form.addEventListener("submit", async function(e){
      e.preventDefault(); // stay on page
      var target = form.getAttribute('action') || '';
      if (!target) {
        dbg('No action URL found on form; cannot submit.');
        alert("❌ Subscription form is misconfigured (no action URL). Please try again later.");
        return;
      }
      dbg('Proxy submit ->', target);

      var fd = new FormData(form);
      fd.append('action', 'ep_reveal_proxy');
      fd.append('nonce',  EP.nonce);
      fd.append('target', target);

      try {
        var res  = await fetch(EP.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
        if (!res.ok) throw new Error('AJAX ' + res.status);
        var data = await res.json();
        dbg('Proxy response:', data);

        if (!data || !data.success) throw new Error((data && data.data && data.data.message) || 'Proxy failed');

        var status = data.data.status || 0;
        var ct     = (data.data.content_type || '').toLowerCase();
        var body   = data.data.body || '';

        var ok = false;
        if (ct.indexOf('json') !== -1) {
          try {
            var json = JSON.parse(body);
            dbg('Downstream JSON:', json);
            ok = !!(json && (json.success === true || json.status === 'success'));
          } catch(err) {
            dbg('Parse JSON fail', err);
          }
        } else {
          // HTML success markers (covers localized variants)
          ok = (status >= 200 && status < 300) && /ml-form-successBody|thank you|ευχαριστούμε/i.test(body);
        }

        if (ok) {
          dbg('Subscription success via proxy');
          unlock();
        } else {
          dbg('Subscription not confirmed; status=' + status);
          alert("❌ Subscription failed. Please try again.");
        }
      } catch (err) {
        dbg('Network/Proxy error', err);
        alert("❌ Network error. Please try again.");
      }
    }, { passive:false });
  });

  dbg('Init complete');
});
</script>
EOD;

    return do_shortcode(ob_get_clean());
}
add_shortcode('epilogue_reveal', 'ep_reveal_shortcode');

// ============================
// 3) Cache Bypass / Headers
// ============================

// Disable cache/minify on any singular of the selected post types (W3TC respects if Late Initialization is ON)
add_action('wp', function () {
    if (is_admin() || wp_doing_ajax() || !is_singular()) return;
    global $post;
    if (!$post) return;

    $allowed = (array) get_option('ep_reveal_post_types', []);
    if (in_array($post->post_type, $allowed, true)) {
        if (!defined('DONOTCACHEPAGE'))   define('DONOTCACHEPAGE', true);
        if (!defined('DONOTCACHEOBJECT')) define('DONOTCACHEOBJECT', true);
        if (!defined('DONOTMINIFY'))      define('DONOTMINIFY', true);
    }
});

// Send no-cache headers for selected post types (helps proxies/CDNs)
add_action('template_redirect', function () {
    if (!is_singular()) return;
    global $post;
    if (!$post) return;

    $allowed = (array) get_option('ep_reveal_post_types', []);
    if (in_array($post->post_type, $allowed, true)) {
        nocache_headers();
    }
});

// ============================
// 4) Server-side proxy (avoids CORS/redirect issues)
// ============================
add_action('wp_ajax_nopriv_ep_reveal_proxy', 'ep_reveal_proxy');
add_action('wp_ajax_ep_reveal_proxy', 'ep_reveal_proxy');
function ep_reveal_proxy()
{
    // Nonce
    $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : '';
    if (!wp_verify_nonce($nonce, 'ep_reveal_proxy')) {
        wp_send_json_error(['message' => 'Bad nonce'], 400);
    }

    $target = isset($_POST['target']) ? esc_url_raw($_POST['target']) : '';
    if (!$target) {
        wp_send_json_error(['message' => 'Missing target'], 400);
    }

    // Build body from posted fields, excluding our control params
    $body = $_POST;
    unset($body['action'], $body['nonce'], $body['target']);

    // Forward to MailerLite and follow redirects
    $args = [
        'method'      => 'POST',
        'timeout'     => 20,
        'redirection' => 5,
        'headers'     => [
            'Referer'    => home_url('/'),
            'User-Agent' => 'WP-EpilogueReveal/1.1',
        ],
        'body'        => $body,
    ];
    $resp = wp_remote_request($target, $args);

    if (is_wp_error($resp)) {
        wp_send_json_error(['message' => $resp->get_error_message()], 502);
    }

    $status = wp_remote_retrieve_response_code($resp);
    $ct     = wp_remote_retrieve_header($resp, 'content-type');
    $raw    = wp_remote_retrieve_body($resp);

    wp_send_json_success([
        'status'       => $status,
        'content_type' => $ct,
        'body'         => $raw,
    ]);
}
