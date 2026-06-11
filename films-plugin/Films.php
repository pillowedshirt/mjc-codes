<?php
/**
 * Plugin Name: MJC Films Portfolio
 * Description: Adds a cinematography film portfolio system with editable film details, ordered stills, a Work shortcode, and dynamic film detail pages.
 * Version: 1.1.0
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
        add_action('add_meta_boxes_' . self::CPT, [$this, 'remove_unwanted_meta_boxes'], 99);
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
            'supports' => ['title'],
            'has_archive' => false,
            'rewrite' => ['slug' => 'film', 'with_front' => false],
            'show_in_rest' => false,
        ]);
    }

    public function remove_unwanted_meta_boxes() {
        remove_meta_box('postcustom', self::CPT, 'normal');
        remove_meta_box('postcustom', self::CPT, 'advanced');
        remove_meta_box('pageparentdiv', self::CPT, 'side');

        $possible_astra_boxes = [
            'astra_settings_meta_box',
            'astra_meta_settings',
            'theme_settings_meta_box',
            'ast-hf-meta-box',
        ];

        foreach ($possible_astra_boxes as $box_id) {
            remove_meta_box($box_id, self::CPT, 'side');
            remove_meta_box($box_id, self::CPT, 'normal');
            remove_meta_box($box_id, self::CPT, 'advanced');
        }

        $possible_litespeed_boxes = [
            'litespeed_meta_boxes',
            'litespeed_meta_box',
            'litespeed_post_metabox',
            'litespeed_cache_settings',
        ];

        foreach ($possible_litespeed_boxes as $box_id) {
            remove_meta_box($box_id, self::CPT, 'side');
            remove_meta_box($box_id, self::CPT, 'normal');
            remove_meta_box($box_id, self::CPT, 'advanced');
        }
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
            .mjc-film-order-wrap { margin: 0 0 18px; max-width: 260px; }
            .mjc-film-order-wrap label { display:block; font-weight:600; margin-bottom:6px; }
            .mjc-film-order-wrap input { width:100%; }

            .mjc-film-details-table { width:100%; border-collapse:collapse; max-width:1100px; }
            .mjc-film-details-table th { text-align:left; font-weight:600; padding:8px; border-bottom:1px solid #dcdcde; }
            .mjc-film-details-table td { padding:8px; vertical-align:top; border-bottom:1px solid #f0f0f1; }
            .mjc-film-details-table input[type="text"], .mjc-film-details-table textarea { width:100%; }
            .mjc-film-details-label-col { width:170px; font-weight:600; }
            .mjc-film-details-check-col { width:92px; white-space:nowrap; }
            .mjc-film-custom-label { margin-top:6px; }
            .mjc-film-custom-label[hidden] { display:none !important; }

            .mjc-stills-help { margin: 0 0 12px; color:#555; }
            .mjc-still-row { display:grid; grid-template-columns: 34px 44px 32px 90px 1fr auto auto; gap:10px; align-items:center; padding:10px; border:1px solid #dcdcde; background:#fff; margin-bottom:8px; }
            .mjc-still-work-toggle { text-align:center; }
            .mjc-still-number { text-align:center; font-weight:700; }
            .mjc-still-drag-handle { cursor:move; color:#555; font-size:22px; line-height:1; text-align:center; user-select:none; }
            .mjc-still-preview { width:80px; height:54px; object-fit:cover; background:#f0f0f1; border:1px solid #dcdcde; }
            .mjc-still-empty { width:80px; height:54px; display:flex; align-items:center; justify-content:center; background:#f0f0f1; border:1px solid #dcdcde; color:#777; font-size:11px; }
            .mjc-still-url { font-size:12px; color:#555; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
            .mjc-still-work-label { display:block; font-size:10px; text-transform:uppercase; color:#555; margin-top:2px; }
        ';

        wp_add_inline_style('wp-admin', $css);

        $js = <<<'JS'
        jQuery(function($){
            var frame;

            $('.mjc-stills-list').sortable({
                handle: '.mjc-still-drag-handle',
                items: '.mjc-still-row',
                update: function(){
                    $('.mjc-still-row').each(function(i){
                        $(this).find('.mjc-still-number').text(i + 1);
                    });
                }
            });

            $(document).on('change', '.mjc-rename-toggle', function(){
                var target = $(this).data('target');
                $('#' + target).prop('hidden', !this.checked);
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
                    $row.find('.mjc-still-work-checkbox').prop('checked', true);
                });

                frame.open();
            });

            $(document).on('click', '.mjc-remove-still', function(e){
                e.preventDefault();

                var $row = $(this).closest('.mjc-still-row');

                $row.find('.mjc-still-id').val('');
                $row.find('.mjc-still-preview-wrap').html('<div class="mjc-still-empty">No image</div>');
                $row.find('.mjc-still-url').text('');
                $row.find('.mjc-still-work-checkbox').prop('checked', true);
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

    private function default_visible_fields() {
        return array_keys($this->detail_fields());
    }

    private function get_visible_fields($post_id) {
        $saved = get_post_meta($post_id, self::META_PREFIX . 'visible_fields', true);

        if (!is_array($saved)) {
            return $this->default_visible_fields();
        }

        return array_values(array_intersect(array_keys($this->detail_fields()), $saved));
    }

    private function field_is_visible($post_id, $key) {
        return in_array($key, $this->get_visible_fields($post_id), true);
    }

    private function get_field_label($post_id, $key) {
        $fields = $this->detail_fields();
        $default = isset($fields[$key]) ? $fields[$key][0] : $key;

        $rename_enabled = get_post_meta($post_id, self::META_PREFIX . 'rename_' . $key, true);
        $custom_label = get_post_meta($post_id, self::META_PREFIX . 'label_' . $key, true);

        if ($rename_enabled === '1' && $custom_label !== '') {
            return $custom_label;
        }

        return $default;
    }

    public function render_details_meta_box($post) {
        wp_nonce_field(self::NONCE, self::NONCE);

        $film_order = (int) get_post_field('menu_order', $post->ID);

        echo '<div class="mjc-film-order-wrap">';
        echo '<label for="mjc_film_order">Film Display Order</label>';
        echo '<input id="mjc_film_order" type="number" name="mjc_film_order" value="' . esc_attr($film_order) . '" step="1">';
        echo '<p class="description">Lower numbers display first on the Work page.</p>';
        echo '</div>';

        echo '<table class="mjc-film-details-table">';
        echo '<thead><tr>';
        echo '<th class="mjc-film-details-check-col">Display</th>';
        echo '<th class="mjc-film-details-check-col">Rename</th>';
        echo '<th class="mjc-film-details-label-col">Detail</th>';
        echo '<th>Content</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($this->detail_fields() as $key => $field) {
            [$label, $type] = $field;
            $value = get_post_meta($post->ID, self::META_PREFIX . $key, true);
            $is_visible = $this->field_is_visible($post->ID, $key);
            $rename_enabled = get_post_meta($post->ID, self::META_PREFIX . 'rename_' . $key, true) === '1';
            $custom_label = get_post_meta($post->ID, self::META_PREFIX . 'label_' . $key, true);
            $custom_label_id = 'mjc_custom_label_' . $key;

            echo '<tr>';

            echo '<td>';
            echo '<label><input type="checkbox" name="mjc_visible_fields[]" value="' . esc_attr($key) . '" ' . checked($is_visible, true, false) . '> Show</label>';
            echo '</td>';

            echo '<td>';
            echo '<label><input class="mjc-rename-toggle" data-target="' . esc_attr($custom_label_id) . '" type="checkbox" name="mjc_rename_fields[]" value="' . esc_attr($key) . '" ' . checked($rename_enabled, true, false) . '> Rename</label>';
            echo '</td>';

            echo '<td class="mjc-film-details-label-col">';
            echo esc_html($label);
            echo '<input id="' . esc_attr($custom_label_id) . '" class="mjc-film-custom-label" type="text" name="mjc_custom_labels[' . esc_attr($key) . ']" value="' . esc_attr($custom_label) . '" placeholder="Custom label" ' . ($rename_enabled ? '' : 'hidden') . '>';
            echo '</td>';

            echo '<td>';
            if ($type === 'textarea') {
                echo '<textarea id="mjc_' . esc_attr($key) . '" name="mjc_film[' . esc_attr($key) . ']" rows="4">' . esc_textarea($value) . '</textarea>';
            } else {
                echo '<input id="mjc_' . esc_attr($key) . '" type="text" name="mjc_film[' . esc_attr($key) . ']" value="' . esc_attr($value) . '">';
            }
            echo '</td>';

            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
    }

    public function render_stills_meta_box($post) {
        $stills = $this->get_still_rows($post->ID);

        echo '<p class="mjc-stills-help">Add up to 20 stills. Use the hamburger handle to drag stills into the correct order. The Work checkbox controls whether a still appears on the Work page. All selected stills still appear on the film detail carousel.</p>';
        echo '<div class="mjc-stills-list">';

        for ($i = 0; $i < 20; $i++) {
            $row = isset($stills[$i]) ? $stills[$i] : ['id' => 0, 'show_work' => 1];
            $attachment_id = isset($row['id']) ? absint($row['id']) : 0;
            $show_work = !isset($row['show_work']) || (int) $row['show_work'] === 1;
            $thumb = $attachment_id ? wp_get_attachment_image_url($attachment_id, 'medium') : '';
            $filename = $attachment_id ? basename(get_attached_file($attachment_id)) : '';

            echo '<div class="mjc-still-row">';

            echo '<div class="mjc-still-work-toggle">';
            echo '<input class="mjc-still-work-checkbox" type="checkbox" name="mjc_still_show_work[' . esc_attr($i) . ']" value="1" ' . checked($show_work, true, false) . '>';
            echo '<span class="mjc-still-work-label">Work</span>';
            echo '</div>';

            echo '<strong class="mjc-still-number">' . esc_html($i + 1) . '</strong>';
            echo '<span class="mjc-still-drag-handle" aria-label="Drag to reorder">&#9776;</span>';

            echo '<div class="mjc-still-preview-wrap">';
            if ($thumb) {
                echo '<img class="mjc-still-preview" src="' . esc_url($thumb) . '" alt="">';
            } else {
                echo '<div class="mjc-still-empty">No image</div>';
            }
            echo '</div>';

            echo '<input class="mjc-still-id" type="hidden" name="mjc_stills[' . esc_attr($i) . ']" value="' . esc_attr($attachment_id) . '">';
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
        $visible_fields = isset($_POST['mjc_visible_fields']) && is_array($_POST['mjc_visible_fields']) ? array_map('sanitize_key', wp_unslash($_POST['mjc_visible_fields'])) : [];
        $rename_fields = isset($_POST['mjc_rename_fields']) && is_array($_POST['mjc_rename_fields']) ? array_map('sanitize_key', wp_unslash($_POST['mjc_rename_fields'])) : [];
        $custom_labels = isset($_POST['mjc_custom_labels']) && is_array($_POST['mjc_custom_labels']) ? wp_unslash($_POST['mjc_custom_labels']) : [];

        $valid_field_keys = array_keys($this->detail_fields());
        $visible_fields = array_values(array_intersect($valid_field_keys, $visible_fields));

        update_post_meta($post_id, self::META_PREFIX . 'visible_fields', $visible_fields);

        foreach ($this->detail_fields() as $key => $field) {
            $value = isset($submitted[$key]) ? $submitted[$key] : '';

            if ($field[1] === 'textarea') {
                $clean = sanitize_textarea_field($value);
            } else {
                $clean = sanitize_text_field($value);
            }

            update_post_meta($post_id, self::META_PREFIX . $key, $clean);

            $rename_enabled = in_array($key, $rename_fields, true) ? '1' : '0';
            update_post_meta($post_id, self::META_PREFIX . 'rename_' . $key, $rename_enabled);

            $label_value = isset($custom_labels[$key]) ? sanitize_text_field($custom_labels[$key]) : '';
            update_post_meta($post_id, self::META_PREFIX . 'label_' . $key, $label_value);
        }

        $raw_stills = isset($_POST['mjc_stills']) && is_array($_POST['mjc_stills']) ? wp_unslash($_POST['mjc_stills']) : [];
        $raw_show_work = isset($_POST['mjc_still_show_work']) && is_array($_POST['mjc_still_show_work']) ? wp_unslash($_POST['mjc_still_show_work']) : [];

        $stills = [];

        foreach ($raw_stills as $index => $attachment_id) {
            $attachment_id = absint($attachment_id);

            if (!$attachment_id) {
                continue;
            }

            $stills[] = [
                'id' => $attachment_id,
                'show_work' => isset($raw_show_work[$index]) ? 1 : 0,
            ];
        }

        $stills = array_slice($stills, 0, 20);
        update_post_meta($post_id, self::META_PREFIX . 'stills', $stills);

        $new_order = isset($_POST['mjc_film_order']) ? intval(wp_unslash($_POST['mjc_film_order'])) : 0;

        if ((int) $post->menu_order !== $new_order) {
            remove_action('save_post_' . self::CPT, [$this, 'save_film_meta'], 10);
            wp_update_post([
                'ID' => $post_id,
                'menu_order' => $new_order,
            ]);
            add_action('save_post_' . self::CPT, [$this, 'save_film_meta'], 10, 2);
        }
    }

    private function get_still_rows($post_id) {
        $stored = get_post_meta($post_id, self::META_PREFIX . 'stills', true);

        if (!is_array($stored)) {
            return [];
        }

        $rows = [];

        foreach ($stored as $item) {
            if (is_array($item)) {
                $id = isset($item['id']) ? absint($item['id']) : 0;
                $show_work = !isset($item['show_work']) || (int) $item['show_work'] === 1 ? 1 : 0;
            } else {
                $id = absint($item);
                $show_work = 1;
            }

            if ($id) {
                $rows[] = [
                    'id' => $id,
                    'show_work' => $show_work,
                ];
            }
        }

        return array_slice($rows, 0, 20);
    }

    private function get_film_stills($post_id) {
        return array_values(array_map(function($row) {
            return absint($row['id']);
        }, $this->get_still_rows($post_id)));
    }

    private function get_work_stills($post_id) {
        $ids = [];

        foreach ($this->get_still_rows($post_id) as $row) {
            if ((int) $row['show_work'] === 1) {
                $ids[] = absint($row['id']);
            }
        }

        return $ids;
    }

    private function get_film_meta_value($post_id, $key) {
        return get_post_meta($post_id, self::META_PREFIX . $key, true);
    }

    private function get_work_page_url() {
        $work_page = get_page_by_path('work');

        if ($work_page) {
            return get_permalink($work_page);
        }

        return home_url('/work/');
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
            $stills = $this->get_work_stills($film_id);

            if (empty($stills)) {
                continue;
            }

            echo '<div class="mjc-film-group" id="film-' . esc_attr($film_id) . '">';
            echo '<div class="mjc-film-stills-grid">';

            foreach ($stills as $attachment_id) {
                $image = wp_get_attachment_image_url($attachment_id, 'large');

                if (!$image) {
                    continue;
                }

                $all_stills = $this->get_film_stills($film_id);
                $detail_index = array_search($attachment_id, $all_stills, true);
                $detail_index = $detail_index === false ? 0 : $detail_index;
                $url = add_query_arg('still', $detail_index + 1, get_permalink($film_id));

                echo '<a class="mjc-film-still-card" href="' . esc_url($url) . '">';
                echo '<img src="' . esc_url($image) . '" alt="' . esc_attr($title) . ' still">';

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

        echo '<a class="mjc-film-back-button" href="' . esc_url($plugin->get_work_page_url()) . '" aria-label="Back to Work page">&#8592;</a>';

        echo '<div class="mjc-film-detail-layout">';

        echo '<section class="mjc-film-detail-media">';

        if ($selected_image) {
            echo '<div class="mjc-film-detail-image-wrap">';
            echo '<img src="' . esc_url($selected_image) . '" alt="' . esc_attr(get_the_title($film_id)) . ' still">';

            if (count($stills) > 1) {
                echo '<a class="mjc-film-arrow mjc-film-arrow-left" href="' . esc_url($previous_url) . '" aria-label="Previous still"><span>&#8592;</span></a>';
                echo '<a class="mjc-film-arrow mjc-film-arrow-right" href="' . esc_url($next_url) . '" aria-label="Next still"><span>&#8594;</span></a>';
            }

            echo '</div>';

            if (count($stills) > 1) {
                echo '<nav class="mjc-film-dots" aria-label="Film still carousel position">';

                foreach ($stills as $index => $still_id) {
                    $dot_number = $index + 1;
                    $dot_url = add_query_arg('still', $dot_number, get_permalink($film_id));
                    $active = $dot_number === $selected;

                    echo '<a class="mjc-film-dot ' . ($active ? 'is-active' : '') . '" href="' . esc_url($dot_url) . '" aria-label="View still ' . esc_attr($dot_number) . ' of ' . esc_attr(count($stills)) . '" ' . ($active ? 'aria-current="true"' : '') . '></a>';
                }

                echo '</nav>';
            }
        } else {
            echo '<div class="mjc-film-no-image">No stills have been added for this film.</div>';
        }

        echo '</section>';

        echo '<aside class="mjc-film-detail-info">';
        echo '<dl>';

        $info_fields = [
            'title' => ['Title', get_the_title($film_id)],
            'genre' => [$plugin->get_field_label($film_id, 'genre'), $plugin->get_film_meta_value($film_id, 'genre')],
            'length' => [$plugin->get_field_label($film_id, 'length'), $plugin->get_film_meta_value($film_id, 'length')],
            'director' => [$plugin->get_field_label($film_id, 'director'), $plugin->get_film_meta_value($film_id, 'director')],
            'cinematographer' => [$plugin->get_field_label($film_id, 'cinematographer'), $plugin->get_film_meta_value($film_id, 'cinematographer')],
            'production_designer' => [$plugin->get_field_label($film_id, 'production_designer'), $plugin->get_film_meta_value($film_id, 'production_designer')],
            'editor' => [$plugin->get_field_label($film_id, 'editor'), $plugin->get_film_meta_value($film_id, 'editor')],
            'colorist' => [$plugin->get_field_label($film_id, 'colorist'), $plugin->get_film_meta_value($film_id, 'colorist')],
            'actors' => [$plugin->get_field_label($film_id, 'actors'), $plugin->get_film_meta_value($film_id, 'actors')],
        ];

        if ($plugin->field_is_visible($film_id, 'year')) {
            $info_fields = array_slice($info_fields, 0, 1, true)
                + ['year' => [$plugin->get_field_label($film_id, 'year'), $plugin->get_film_meta_value($film_id, 'year')]]
                + array_slice($info_fields, 1, null, true);
        }

        foreach ($info_fields as $key => $row) {
            if ($key !== 'title' && !$plugin->field_is_visible($film_id, $key)) {
                continue;
            }

            [$label, $value] = $row;

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

        if ($logline && $plugin->field_is_visible($film_id, 'logline')) {
            echo '<section class="mjc-film-logline">';
            echo '<h2>' . esc_html($plugin->get_field_label($film_id, 'logline')) . ':</h2>';
            echo '<div>' . nl2br(esc_html($logline)) . '</div>';
            echo '</section>';
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
                font-family: inherit;
                color: inherit;
            }

            .mjc-work-gallery *,
            .mjc-film-detail-page * {
                font-family: inherit;
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

            .mjc-film-back-button {
                display: inline-flex;
                width: 42px;
                height: 42px;
                align-items: center;
                justify-content: center;
                margin: 0 0 22px;
                border: 1px solid currentColor;
                border-radius: 999px;
                color: inherit;
                text-decoration: none;
                font-size: 24px;
                line-height: 1;
                transition: transform .2s ease, opacity .2s ease;
            }

            .mjc-film-back-button:hover,
            .mjc-film-back-button:focus {
                transform: translateX(-3px) scale(1.05);
                opacity: .72;
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
                width: 46px;
                height: 46px;
                display: flex;
                align-items: center;
                justify-content: center;
                border: 1px solid rgba(255,255,255,.48);
                border-radius: 999px;
                background: rgba(255,255,255,.18);
                color: #fff;
                text-decoration: none;
                font-size: 24px;
                line-height: 1;
                backdrop-filter: blur(10px);
                -webkit-backdrop-filter: blur(10px);
                box-shadow: 0 10px 28px rgba(0,0,0,.18);
                transition: transform .2s ease, background .2s ease, border-color .2s ease;
            }

            .mjc-film-arrow span {
                display: block;
                transform: translateY(-1px);
            }

            .mjc-film-arrow:hover,
            .mjc-film-arrow:focus {
                transform: translateY(-50%) scale(1.12);
                background: rgba(255,255,255,.28);
                border-color: rgba(255,255,255,.76);
                color: #fff;
            }

            .mjc-film-arrow-left {
                left: 16px;
            }

            .mjc-film-arrow-right {
                right: 16px;
            }

            .mjc-film-dots {
                display: flex;
                justify-content: center;
                align-items: center;
                gap: 9px;
                margin: 16px 0 0;
            }

            .mjc-film-dot {
                width: 9px;
                height: 9px;
                border-radius: 999px;
                display: block;
                background: var(--wp--preset--color--heading, var(--ast-global-color-2, currentColor));
                opacity: .32;
                text-decoration: none;
                transition: transform .2s ease, opacity .2s ease, background .2s ease;
            }

            .mjc-film-dot:hover,
            .mjc-film-dot:focus {
                transform: scale(1.22);
                opacity: .7;
            }

            .mjc-film-dot.is-active {
                background: var(--wp--preset--color--accent, var(--ast-global-color-0, currentColor));
                opacity: 1;
                transform: scale(1.3);
            }

            .mjc-film-detail-info {
                font-size: 12px;
                line-height: 1.42;
                letter-spacing: .02em;
                color: inherit;
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
                color: inherit;
            }

            .mjc-film-info-row dd {
                margin: 0;
                color: inherit;
            }

            .mjc-film-logline {
                max-width: 820px;
                margin: 42px auto 0;
                text-align: center;
                font-size: 16px;
                line-height: 1.7;
                color: inherit;
            }

            .mjc-film-logline h2 {
                margin: 0 0 12px;
                font-size: inherit;
                line-height: inherit;
                color: inherit;
                font-family: inherit;
                font-weight: 700;
            }

            .mjc-film-logline div {
                color: inherit;
            }

            .mjc-film-no-image {
                background: rgba(0,0,0,.04);
                padding: 60px 20px;
                text-align: center;
                color: inherit;
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
