<?php
/*
Plugin Name: Epilogue Reveal with MailerLite
Description: Hides content of selected post types until user subscribes via MailerLite, based on product_author taxonomy.
Author: Byron Iniotakis
Version: 1.1.000
*/

// 1. Admin Settings
add_action('admin_menu', function() {
    add_options_page('Epilogue Reveal Settings', 'Epilogue Reveal', 'manage_options', 'epilogue-reveal-settings', 'ep_reveal_settings_page');
});

function ep_reveal_settings_page() {
    if (isset($_POST['ep_reveal_save'])) {
        update_option('ep_reveal_post_types', isset($_POST['post_types']) ? $_POST['post_types'] : []);
        $raw_forms = [];
if (isset($_POST['author_forms'])) {
    foreach ($_POST['author_forms'] as $slug => $html) {
        // Unescape quotes and preserve scripts
        $raw_forms[$slug] = wp_unslash($html);
    }
}
update_option('ep_reveal_author_forms', wp_json_encode($raw_forms));
        echo '<div class="updated"><p>Settings saved.</p></div>';
    }

    $post_types = get_post_types(['public' => true], 'objects');
    $selected = get_option('ep_reveal_post_types', []);
    $author_forms = json_decode(get_option('ep_reveal_author_forms', '{}'), true);

    echo '<div class="wrap"><h1>Epilogue Reveal Settings</h1>';
    echo '<form method="post">';

    // Debug toggle: delete cookie
    if (isset($_POST['ep_reveal_debug_cookie_all'])) {
        echo '<div class="updated"><p>All ep_reveal_* cookies cleared (browser only).</p></div>';
        echo '<script>
        document.cookie.split(";").forEach(function(cookie) {
            if (cookie.trim().startsWith("ep_reveal_")) {
                document.cookie = cookie.split("=")[0] + "=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
            }
        });
        </script>';
    }

    echo '<h3>Debug Tools</h3>';
    echo '<p><input type="submit" name="ep_reveal_debug_cookie_all" class="button" value="Clear All Unlock Cookies"></p>';

    echo '<h3>Select Post Types</h3><ul>';
    foreach ($post_types as $type) {
        $checked = in_array($type->name, $selected) ? 'checked' : '';
        echo "<li><label><input type='checkbox' name='post_types[]' value='{$type->name}' $checked> {$type->label}</label></li>";
    }
    echo '</ul>';

    echo '<h3>Author Form Mapping</h3>';
    echo '<div id="ep-author-mapping">';
    $all_authors = get_terms(['taxonomy' => 'product_author', 'hide_empty' => false]);
    foreach ($all_authors as $author_term) {
        $slug = $author_term->slug;
        $form_html = isset($author_forms[$slug]) ? $author_forms[$slug] : '';
        echo "<div><input type='text' name='author_forms_keys[]' value='$slug' readonly> ";
        echo "<textarea name='author_forms[$slug]' rows='3' cols='70' placeholder='MailerLite HTML'>" . esc_textarea($form_html) . "</textarea></div><br>";
    }

    echo '<input type="submit" name="ep_reveal_save" class="button-primary" value="Save Settings">';
    echo '</form></div>';
}

// 2. Shortcode
function ep_reveal_shortcode($atts, $content = null) {
    global $post;
    if (!is_singular()) return '';

    $allowed = get_option('ep_reveal_post_types', []);
    if (!in_array($post->post_type, $allowed)) return do_shortcode($content);

    $unlocked = isset($_COOKIE['ep_reveal_' . $post->ID]);
    if ($unlocked) return do_shortcode($content);

    $terms = get_the_terms($post->ID, 'product_author');
    $author = !empty($terms) && !is_wp_error($terms) ? $terms[0]->slug : 'unknown';

    $forms = json_decode(get_option('ep_reveal_author_forms', '{}'), true);
    $form = $forms[$author] ?? '';

    ob_start();
    if (empty($form)) {
        return '<div class="ep-reveal-form"><p><strong>No form is configured for author: ' . esc_html($author) . '.</strong></p></div>';
    }

    echo '<div class="ep-reveal-form-wrapper">';
    echo $form; // raw MailerLite embed code (old or new)
    echo '</div>';

    echo <<<EOD
    <script>
    document.addEventListener("DOMContentLoaded", function () {
        function unlockEpilogue() {
            document.cookie = "ep_reveal_{$post->ID}=1; path=/; max-age=86400";
            location.reload();
        }

        // Intercept all forms (old and new MailerLite included)
        document.querySelectorAll(".ep-reveal-form-wrapper form").forEach(form => {
            form.addEventListener("submit", async function(e) {
                e.preventDefault();
                const formData = new FormData(form);
                const action = form.getAttribute("action");

                try {
                    const res = await fetch(action, {
                        method: "POST",
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });

                    const text = await res.text();
                    let success = false;

                    // Try JSON parse (for new MailerLite)
                    try {
                        const json = JSON.parse(text);
                        if (json && json.success === true) {
                            success = true;
                        }
                    } catch (err) {
                        // Old MailerLite returns HTML; assume success if form didn't error
                        if (res.ok && text.includes("ml-form-successContent")) {
                            success = true;
                        }
                    }

                    if (success) {
                        unlockEpilogue();
                    } else {
                        alert("❌ Subscription failed. Please try again.");
                    }

                } catch (err) {
                    alert("❌ Network error. Please try again.");
                }
            });
        });
    });
    </script>
    EOD;

    return do_shortcode(ob_get_clean());
}
add_shortcode('epilogue_reveal', 'ep_reveal_shortcode');
