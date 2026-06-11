<?php
/**
 * Plugin Name: MJC Films Portfolio
 * Description: Adds a cinematography film portfolio system with editable film details, ordered stills, a Work shortcode, and dynamic film detail pages.
 * Version: 1.0.0
 * Author: MJC
 */

if (!defined('ABSPATH')) {
    exit;
}

class MJC_Films_Portfolio {
    const CPT = 'mjc_film';
    const META_PREFIX = '_mjc_film_';
    const NONCE = 'mjc_film_details_nonce';

    public function __construct() {
        add_action('init', [$this, 'register_post_type']);
        add_action('add_meta_boxes', [$this, 'add_film_meta_boxes']);
        add_action('save_post_' . self::CPT, [$this, 'save_film_meta'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_shortcode('mjc_films_work', [$this, 'render_work_shortcode']);
        add_action('template_redirect', [$this, 'render_single_template_if_needed']);
        add_filter('manage_' . self::CPT . '_posts_columns', [$this, 'add_admin_columns']);
        add_action('manage_' . self::CPT . '_posts_custom_column', [$this, 'render_admin_columns'], 10, 2);
        add_action('pre_get_posts', [$this, 'default_admin_order']);
    }

    public static function activate() {
        $plugin = new self();
        $plugin->register_post_type();
        flush_rewrite_rules();
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }

    public function register_post_type() {
        register_post_type(self::CPT, [
            'labels' => [
                'name' => 'Films',
                'singular_name' => 'Film',
                'add_new_item' => 'Add New Film',
                'edit_item' => 'Edit Film',
                'new_item' => 'New Film',
                'view_item' => 'View Film',
                'search_items' => 'Search Films',
                'not_found' => 'No films found',
                'menu_name' => 'Films',
            ],
            'public' => true,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_position' => 25,
            'menu_icon' => 'dashicons-format-gallery',
            'supports' => ['title', 'page-attributes'],
            'has_archive' => false,
            'rewrite' => ['slug' => 'film', 'with_front' => false],
            'show_in_rest' => false,
        ]);
    }

    public function add_film_meta_boxes() {
        add_meta_box(
            'mjc_film_details',
            'Film Details',
            [$this, 'render_details_meta_box'],
            self::CPT,
            'normal',
            'high'
        );

        add_meta_box(
            'mjc_film_stills',
            'Film Stills',
            [$this, 'render_stills_meta_box'],
            self::CPT,
            'normal',
            'high'
        );
    }

    public function enqueue_admin_assets($hook) {
        global $post_type;

        if ($post_type !== self::CPT) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_script('jquery-ui-sortable');

        $css = '
            .mjc-film-grid { display:grid; grid-template-columns: 180px 1fr; gap:12px 18px; max-width: 900px; }
            .mjc-film-grid label { font-weight:600; padding-top:8px; }
            .mjc-film-grid input, .mjc-film-grid textarea { width:100%; }
            .mjc-stills-help { margin: 0 0 12px; color:#555; }
            .mjc-still-row { display:grid; grid-template-columns: 90px 1fr auto auto; gap:10px; align-items:center; padding:10px; border:1px solid #dcdcde; background:#fff; margin-bottom:8px; cursor:move; }
            .mjc-still-preview { width:80px; height:54px; object-fit:cover; background:#f0f0f1; border:1px solid #dcdcde; }
            .mjc-still-empty { width:80px; height:54px; display:flex; align-items:center; justify-content:center; background:#f0f0f1; border:1px solid #dcdcde; color:#777; font-size:11px; }
            .mjc-still-url { font-size:12px; color:#555; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        ';
        wp_add_inline_style('wp-admin', $css);

        $js = <<<'JS'
        jQuery(function($){
            var frame;

            $('.mjc-stills-list').sortable({
                handle: '.mjc-still-row',
                items: '.mjc-still-row',
                update: function(){
                    $('.mjc-still-row').each(function(i){
                        $(this).find('.mjc-still-number').text(i + 1);
                    });
                }
            });

            $(document).on('click', '.mjc-select-still', function(e){
                e.preventDefault();

                var $row = $(this).closest('.mjc-still-row');

                frame = wp.media({
                    title: 'Select Film Still',
                    button: { text: 'Use this still' },
                    multiple: false
                });

                frame.on('select', function(){
                    var attachment = frame.state().get('selection').first().toJSON();
                    var preview = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;

                    $row.find('.mjc-still-id').val(attachment.id);
                    $row.find('.mjc-still-preview-wrap').html('<img class="mjc-still-preview" src="' + preview + '" alt="">');
                    $row.find('.mjc-still-url').text(attachment.filename || attachment.url);
                    $row.find('.mjc-remove-still').prop('disabled', false);
                });

                frame.open();
            });

            $(document).on('click', '.mjc-remove-still', function(e){
                e.preventDefault();

                var $row = $(this).closest('.mjc-still-row');

                $row.find('.mjc-still-id').val('');
                $row.find('.mjc-still-preview-wrap').html('<div class="mjc-still-empty">No image</div>');
                $row.find('.mjc-still-url').text('');
                $(this).prop('disabled', true);
            });
        });
JS;
        wp_add_inline_script('jquery-ui-sortable', $js);
    }

    private function detail_fields() {
        return [
            'year' => ['Year', 'text'],
            'genre' => ['Genre', 'text'],
            'length' => ['Length', 'text'],
            'director' => ['Director', 'text'],
            'cinematographer' => ['Cinematographer', 'text'],
            'production_designer' => ['Production Designer', 'text'],
            'editor' => ['Editor', 'text'],
            'colorist' => ['Colorist', 'text'],
            'actors' => ['Actors', 'textarea'],
            'logline' => ['Logline', 'textarea'],
        ];
    }

    public function render_details_meta_box($post) {
        wp_nonce_field(self::NONCE, self::NONCE);

        echo '<div class="mjc-film-grid">';

        foreach ($this->detail_fields() as $key => $field) {
            [$label, $type] = $field;
            $value = get_post_meta($post->ID, self::META_PREFIX . $key, true);

            echo '<label for="mjc_' . esc_attr($key) . '">' . esc_html($label) . '</label>';

            if ($type === 'textarea') {
                echo '<textarea id="mjc_' . esc_attr($key) . '" name="mjc_film[' . esc_attr($key) . ']" rows="4">' . esc_textarea($value) . '</textarea>';
            } else {
                echo '<input id="mjc_' . esc_attr($key) . '" type="text" name="mjc_film[' . esc_attr($key) . ']" value="' . esc_attr($value) . '">';
            }
        }

        echo '</div>';
    }

    public function render_stills_meta_box($post) {
        $stills = get_post_meta($post->ID, self::META_PREFIX . 'stills', true);

        if (!is_array($stills)) {
            $stills = [];
        }

        echo '<p class="mjc-stills-help">Add up to 20 stills. Drag rows to change the still order. The Work page displays each film’s stills in this order.</p>';
        echo '<div class="mjc-stills-list">';

        for ($i = 0; $i < 20; $i++) {
            $attachment_id = isset($stills[$i]) ? absint($stills[$i]) : 0;
            $thumb = $attachment_id ? wp_get_attachment_image_url($attachment_id, 'medium') : '';
            $filename = $attachment_id ? basename(get_attached_file($attachment_id)) : '';

            echo '<div class="mjc-still-row">';
            echo '<strong class="mjc-still-number">' . esc_html($i + 1) . '</strong>';

            echo '<div class="mjc-still-preview-wrap">';
            if ($thumb) {
                echo '<img class="mjc-still-preview" src="' . esc_url($thumb) . '" alt="">';
            } else {
                echo '<div class="mjc-still-empty">No image</div>';
            }
            echo '</div>';

            echo '<input class="mjc-still-id" type="hidden" name="mjc_stills[]" value="' . esc_attr($attachment_id) . '">';
            echo '<div class="mjc-still-url">' . esc_html($filename) . '</div>';
            echo '<button type="button" class="button mjc-select-still">Select</button>';
            echo '<button type="button" class="button mjc-remove-still" ' . disabled(!$attachment_id, true, false) . '>Remove</button>';
            echo '</div>';
        }

        echo '</div>';
    }

    public function save_film_meta($post_id, $post) {
        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE])), self::NONCE)) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $submitted = isset($_POST['mjc_film']) && is_array($_POST['mjc_film']) ? wp_unslash($_POST['mjc_film']) : [];

        foreach ($this->detail_fields() as $key => $field) {
            $value = isset($submitted[$key]) ? $submitted[$key] : '';

            if ($field[1] === 'textarea') {
                $clean = sanitize_textarea_field($value);
            } else {
                $clean = sanitize_text_field($value);
            }

            update_post_meta($post_id, self::META_PREFIX . $key, $clean);
        }

        $stills = isset($_POST['mjc_stills']) && is_array($_POST['mjc_stills'])
            ? array_map('absint', wp_unslash($_POST['mjc_stills']))
            : [];

        $stills = array_values(array_filter($stills));
        $stills = array_slice($stills, 0, 20);

        update_post_meta($post_id, self::META_PREFIX . 'stills', $stills);
    }

    private function get_film_stills($post_id) {
        $stills = get_post_meta($post_id, self::META_PREFIX . 'stills', true);

        return is_array($stills) ? array_values(array_filter(array_map('absint', $stills))) : [];
    }

    private function get_film_meta_value($post_id, $key) {
        return get_post_meta($post_id, self::META_PREFIX . $key, true);
    }

    public function render_work_shortcode($atts) {
        $films = new WP_Query([
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => ['menu_order' => 'ASC', 'date' => 'DESC'],
        ]);

        ob_start();

        $this->print_frontend_styles();

        echo '<section class="mjc-work-gallery">';

        if (!$films->have_posts()) {
            echo '<p class="mjc-empty-films">No films have been added yet.</p>';
        }

        while ($films->have_posts()) {
            $films->the_post();

            $film_id = get_the_ID();
            $title = get_the_title();
            $year = $this->get_film_meta_value($film_id, 'year');
            $stills = $this->get_film_stills($film_id);

            if (empty($stills)) {
                continue;
            }

            echo '<div class="mjc-film-group" id="film-' . esc_attr($film_id) . '">';
            echo '<div class="mjc-film-stills-grid">';

            foreach ($stills as $index => $attachment_id) {
                $image = wp_get_attachment_image_url($attachment_id, 'large');

                if (!$image) {
                    continue;
                }

                $url = add_query_arg('still', $index + 1, get_permalink($film_id));

                echo '<a class="mjc-film-still-card" href="' . esc_url($url) . '">';
                echo '<img src="' . esc_url($image) . '" alt="' . esc_attr($title) . ' still ' . esc_attr($index + 1) . '">';

                echo '<span class="mjc-film-hover">';
                echo '<span class="mjc-film-hover-title">' . esc_html($title) . '</span>';

                if ($year) {
                    echo '<span class="mjc-film-hover-year">' . esc_html($year) . '</span>';
                }

                echo '</span>';
                echo '</a>';
            }

            echo '</div>';
            echo '</div>';
        }

        wp_reset_postdata();

        echo '</section>';

        return ob_get_clean();
    }

    public function render_single_template_if_needed() {
        if (!is_singular(self::CPT)) {
            return;
        }

        get_header();

        while (have_posts()) {
            the_post();
            self::render_single_film_page(get_the_ID());
        }

        get_footer();

        exit;
    }

    public static function render_single_film_page($film_id) {
        $plugin = new self();

        $plugin->print_frontend_styles();

        $stills = $plugin->get_film_stills($film_id);

        $selected = isset($_GET['still']) ? absint($_GET['still']) : 1;

        if ($selected < 1) {
            $selected = 1;
        }

        if (!empty($stills) && $selected > count($stills)) {
            $selected = count($stills);
        }

        $selected_index = max(0, $selected - 1);
        $selected_id = isset($stills[$selected_index]) ? $stills[$selected_index] : 0;
        $selected_image = $selected_id ? wp_get_attachment_image_url($selected_id, 'large') : '';

        $previous_still = $selected <= 1 ? count($stills) : $selected - 1;
        $next_still = $selected >= count($stills) ? 1 : $selected + 1;

        $previous_url = add_query_arg('still', $previous_still, get_permalink($film_id));
        $next_url = add_query_arg('still', $next_still, get_permalink($film_id));

        echo '<main class="mjc-film-detail-page">';
        echo '<div class="mjc-film-detail-layout">';

        echo '<section class="mjc-film-detail-media">';

        if ($selected_image) {
            echo '<div class="mjc-film-detail-image-wrap">';
            echo '<img src="' . esc_url($selected_image) . '" alt="' . esc_attr(get_the_title($film_id)) . ' still">';

            if (count($stills) > 1) {
                echo '<a class="mjc-film-arrow mjc-film-arrow-left" href="' . esc_url($previous_url) . '" aria-label="Previous still">&#8592;</a>';
                echo '<a class="mjc-film-arrow mjc-film-arrow-right" href="' . esc_url($next_url) . '" aria-label="Next still">&#8594;</a>';
            }

            echo '</div>';
        } else {
            echo '<div class="mjc-film-no-image">No stills have been added for this film.</div>';
        }

        echo '</section>';

        echo '<aside class="mjc-film-detail-info">';
        echo '<dl>';

        $rows = [
            'Title' => get_the_title($film_id),
            'Genre' => $plugin->get_film_meta_value($film_id, 'genre'),
            'Length' => $plugin->get_film_meta_value($film_id, 'length'),
            'Director' => $plugin->get_film_meta_value($film_id, 'director'),
            'Cinematographer' => $plugin->get_film_meta_value($film_id, 'cinematographer'),
            'Production Designer' => $plugin->get_film_meta_value($film_id, 'production_designer'),
            'Editor' => $plugin->get_film_meta_value($film_id, 'editor'),
            'Colorist' => $plugin->get_film_meta_value($film_id, 'colorist'),
            'Actors' => $plugin->get_film_meta_value($film_id, 'actors'),
        ];

        foreach ($rows as $label => $value) {
            if ($value === '') {
                continue;
            }

            echo '<div class="mjc-film-info-row">';
            echo '<dt>' . esc_html($label) . '</dt>';
            echo '<dd>' . nl2br(esc_html($value)) . '</dd>';
            echo '</div>';
        }

        echo '</dl>';
        echo '</aside>';

        echo '</div>';

        $logline = $plugin->get_film_meta_value($film_id, 'logline');

        if ($logline) {
            echo '<section class="mjc-film-logline">' . nl2br(esc_html($logline)) . '</section>';
        }

        echo '</main>';
    }

    public function print_frontend_styles() {
        static $printed = false;

        if ($printed) {
            return;
        }

        $printed = true;
        ?>
        <style>
            .mjc-work-gallery,
            .mjc-film-detail-page {
                width: min(1200px, calc(100% - 40px));
                margin: 0 auto;
            }

            .mjc-film-group {
                margin: 0 0 34px;
            }

            .mjc-film-stills-grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 18px;
            }

            .mjc-film-still-card {
                position: relative;
                display: block;
                overflow: hidden;
                background: #111;
                aspect-ratio: 16 / 9;
                text-decoration: none;
            }

            .mjc-film-still-card img {
                width: 100%;
                height: 100%;
                object-fit: cover;
                display: block;
                transition: transform .35s ease, opacity .35s ease;
            }

            .mjc-film-hover {
                position: absolute;
                inset: 0;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                text-align: center;
                gap: 5px;
                background: rgba(0,0,0,.56);
                color: #fff;
                opacity: 0;
                transition: opacity .25s ease;
                padding: 24px;
            }

            .mjc-film-still-card:hover img,
            .mjc-film-still-card:focus img {
                transform: scale(1.035);
                opacity: .82;
            }

            .mjc-film-still-card:hover .mjc-film-hover,
            .mjc-film-still-card:focus .mjc-film-hover {
                opacity: 1;
            }

            .mjc-film-hover-title {
                font-size: clamp(18px, 2vw, 28px);
                line-height: 1.1;
                letter-spacing: .03em;
                text-transform: uppercase;
            }

            .mjc-film-hover-year {
                font-size: 14px;
                letter-spacing: .12em;
                opacity: .88;
            }

            .mjc-empty-films {
                text-align: center;
                padding: 40px 0;
            }

            .mjc-film-detail-page {
                padding: 46px 0;
            }

            .mjc-film-detail-layout {
                display: grid;
                grid-template-columns: minmax(0, 1.08fr) minmax(260px, .92fr);
                gap: 34px;
                align-items: start;
            }

            .mjc-film-detail-media {
                min-width: 0;
            }

            .mjc-film-detail-image-wrap {
                position: relative;
                background: #111;
                overflow: hidden;
            }

            .mjc-film-detail-image-wrap img {
                width: 100%;
                height: auto;
                display: block;
            }

            .mjc-film-arrow {
                position: absolute;
                top: 50%;
                transform: translateY(-50%);
                width: 38px;
                height: 38px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 999px;
                background: rgba(255,255,255,.88);
                color: #111;
                text-decoration: none;
                font-size: 22px;
                line-height: 1;
            }

            .mjc-film-arrow:hover,
            .mjc-film-arrow:focus {
                background: #fff;
                color: #000;
            }

            .mjc-film-arrow-left {
                left: 14px;
            }

            .mjc-film-arrow-right {
                right: 14px;
            }

            .mjc-film-detail-info {
                font-size: 12px;
                line-height: 1.42;
                letter-spacing: .02em;
            }

            .mjc-film-detail-info dl {
                margin: 0;
            }

            .mjc-film-info-row {
                display: grid;
                grid-template-columns: 140px 1fr;
                gap: 12px;
                padding: 0 0 8px;
            }

            .mjc-film-info-row dt {
                font-weight: 700;
                text-transform: uppercase;
                color: #111;
            }

            .mjc-film-info-row dd {
                margin: 0;
                color: #333;
            }

            .mjc-film-logline {
                max-width: 820px;
                margin: 42px auto 0;
                text-align: center;
                font-size: 16px;
                line-height: 1.7;
            }

            .mjc-film-no-image {
                background: #f4f4f4;
                padding: 60px 20px;
                text-align: center;
            }

            @media (max-width: 800px) {
                .mjc-work-gallery,
                .mjc-film-detail-page {
                    width: min(100% - 28px, 1200px);
                }

                .mjc-film-stills-grid,
                .mjc-film-detail-layout {
                    grid-template-columns: 1fr;
                }

                .mjc-film-info-row {
                    grid-template-columns: 1fr;
                    gap: 2px;
                }
            }
        </style>
        <?php
    }

    public function add_admin_columns($columns) {
        $new = [];

        foreach ($columns as $key => $label) {
            $new[$key] = $label;

            if ($key === 'title') {
                $new['mjc_year'] = 'Year';
                $new['mjc_stills'] = 'Stills';
                $new['mjc_order'] = 'Order';
            }
        }

        return $new;
    }

    public function render_admin_columns($column, $post_id) {
        if ($column === 'mjc_year') {
            echo esc_html($this->get_film_meta_value($post_id, 'year'));
        }

        if ($column === 'mjc_stills') {
            echo esc_html(count($this->get_film_stills($post_id)));
        }

        if ($column === 'mjc_order') {
            echo esc_html(get_post_field('menu_order', $post_id));
        }
    }

    public function default_admin_order($query) {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        if ($query->get('post_type') === self::CPT && !$query->get('orderby')) {
            $query->set('orderby', 'menu_order');
            $query->set('order', 'ASC');
        }
    }
}

new MJC_Films_Portfolio();

register_activation_hook(__FILE__, ['MJC_Films_Portfolio', 'activate']);
register_deactivation_hook(__FILE__, ['MJC_Films_Portfolio', 'deactivate']);
