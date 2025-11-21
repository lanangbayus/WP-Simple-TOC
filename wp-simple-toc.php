<?php
/**
 * Plugin Name: WP Simple TOC
 * Description: Generate a simple Table of Contents (TOC) from H2/H3 headings in post content, with per-post control and meta title.
 * Version:     2.0.1
 * Author:      Lanang Bayu S
 * Text Domain: wp-simple-toc
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Simple_TOC {

    const META_ENABLED     = '_simple_toc_enabled';
    const META_POSITION    = '_simple_toc_position';   // top | float_left | float_right
    const META_META_TITLE  = '_simple_toc_meta_title';

    public function __construct() {
        // Inject TOC ke konten
        add_filter( 'the_content', [ $this, 'inject_toc_into_content' ], 20 );

        // Shortcode [simple_toc]
        add_shortcode( 'simple_toc', [ $this, 'shortcode_simple_toc' ] );

        // Meta box per post (TOC + meta title + preview)
        add_action( 'add_meta_boxes', [ $this, 'add_toc_meta_box' ] );
        add_action( 'save_post', [ $this, 'save_toc_meta_box' ] );

        // CSS front-end
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_front_css' ] );

        // CSS di editor untuk preview TOC
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_css' ] );

        // Meta title override
        add_filter( 'document_title_parts', [ $this, 'filter_document_title' ] );
    }

    /**
     * Enqueue CSS front-end
     */
    public function enqueue_front_css() {
        wp_enqueue_style(
            'wp-simple-toc',
            plugin_dir_url( __FILE__ ) . 'assets/css/simple-toc.css',
            [],
            '2.0.1'
        );
    }

    /**
     * Enqueue CSS di editor untuk meta box preview.
     */
    public function enqueue_admin_css( $hook ) {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            return;
        }

        wp_enqueue_style(
            'wp-simple-toc-admin',
            plugin_dir_url( __FILE__ ) . 'assets/css/simple-toc.css',
            [],
            '2.0.1'
        );
    }

    /**
     * Parse H2/H3 dari konten.
     */
    private function extract_headings( $content ) {
        $pattern = '/<h([2-3])([^>]*)>(.*?)<\/h[2-3]>/i';

        if ( ! preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
            return [];
        }

        $items = [];

        foreach ( $matches as $match ) {
            $level   = intval( $match[1] );
            $attribs = $match[2];
            $text    = wp_strip_all_tags( $match[3] );

            // Coba ambil id dari <h2 id="...">
            $id = '';
            if ( preg_match( '/id=["\']([^"\']+)["\']/', $attribs, $id_match ) ) {
                $id = $id_match[1];
            } else {
                $id = sanitize_title( $text );
            }

            $items[] = [
                'level' => $level,
                'id'    => $id,
                'text'  => $text,
            ];
        }

        return $items;
    }

    /**
     * Build TOC box HTML dengan opsi posisi.
     */
    private function build_toc_box_html( array $items, $position = 'top' ) {
        if ( empty( $items ) ) {
            return '';
        }

        $extra_class = '';

        switch ( $position ) {
            case 'float_left':
                $extra_class = ' simple-toc-box--float-left';
                break;
            case 'float_right':
                $extra_class = ' simple-toc-box--float-right';
                break;
            default:
                // top (default)
                break;
        }

        $html  = '<nav class="simple-toc-box' . esc_attr( $extra_class ) . '">';
        $html .= '<div class="simple-toc">';
        $html .= '<div class="simple-toc__title">Table of Contents</div>';
        $html .= '<ul class="simple-toc__list">';

        foreach ( $items as $item ) {
            $class = ( $item['level'] === 3 )
                ? 'simple-toc__item simple-toc__item--child'
                : 'simple-toc__item';

            $html .= sprintf(
                '<li class="%s"><a href="#%s">%s</a></li>',
                esc_attr( $class ),
                esc_attr( $item['id'] ),
                esc_html( $item['text'] )
            );
        }

        $html .= '</ul>';
        $html .= '</div>';
        $html .= '</nav>';

        return $html;
    }

    /**
     * Inject TOC ke konten post.
     */
    public function inject_toc_into_content( $content ) {
        if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }

        global $post;

        // Jangan double kalau sudah ada shortcode manual.
        if ( strpos( $content, '[simple_toc' ) !== false ) {
            return $content;
        }

        // Per-post: enable/disable
        $enabled_meta = get_post_meta( $post->ID, self::META_ENABLED, true );
        $enabled      = ( $enabled_meta === '' ) ? '1' : $enabled_meta; // default ON

        if ( $enabled !== '1' ) {
            return $content;
        }

        // Posisi per post
        $position = get_post_meta( $post->ID, self::META_POSITION, true );
        if ( ! in_array( $position, [ 'top', 'float_left', 'float_right' ], true ) ) {
            $position = 'top';
        }

        $items = $this->extract_headings( $content );
        if ( empty( $items ) ) {
            return $content;
        }

        $toc_html = $this->build_toc_box_html( $items, $position );

        return $toc_html . $content;
    }

    /**
     * Shortcode [simple_toc] - manual insert.
     */
    public function shortcode_simple_toc() {
        if ( ! is_singular( 'post' ) ) {
            return '';
        }

        global $post;
        $content = $post->post_content;

        $items = $this->extract_headings( apply_filters( 'the_content', $content ) );

        if ( empty( $items ) ) {
            return '';
        }

        return $this->build_toc_box_html( $items, 'top' );
    }

    /**
     * Meta box di editor post:
     * - Enable TOC (checkbox)
     * - Posisi (top/float left/right)
     * - Meta title
     * - Preview TOC
     */
    public function add_toc_meta_box() {
        add_meta_box(
            'wp_simple_toc_meta',
            __( 'Simple TOC Settings', 'wp-simple-toc' ),
            [ $this, 'render_toc_meta_box' ],
            'post',
            'side',
            'default'
        );
    }

    public function render_toc_meta_box( $post ) {
        // NONCE konsisten dengan save_toc_meta_box
        wp_nonce_field( 'wp_simple_toc_meta_action', 'wp_simple_toc_meta_nonce_field' );

        $enabled    = get_post_meta( $post->ID, self::META_ENABLED, true );
        $enabled    = ( $enabled === '' ) ? '1' : $enabled; // default ON
        $position   = get_post_meta( $post->ID, self::META_POSITION, true );
        $meta_title = get_post_meta( $post->ID, self::META_META_TITLE, true );

        if ( ! in_array( $position, [ 'top', 'float_left', 'float_right' ], true ) ) {
            $position = 'top';
        }

        ?>
        <p>
            <label>
                <input type="checkbox" name="wp_simple_toc_enabled" value="1" <?php checked( $enabled, '1' ); ?> />
                <?php esc_html_e( 'Enable TOC for this post', 'wp-simple-toc' ); ?>
            </label>
        </p>

        <p>
            <label for="wp_simple_toc_position"><strong><?php esc_html_e( 'TOC Position', 'wp-simple-toc' ); ?></strong></label><br/>
            <select name="wp_simple_toc_position" id="wp_simple_toc_position">
                <option value="top" <?php selected( $position, 'top' ); ?>>
                    <?php esc_html_e( 'Top (above content)', 'wp-simple-toc' ); ?>
                </option>
                <option value="float_left" <?php selected( $position, 'float_left' ); ?>>
                    <?php esc_html_e( 'Floating Left (desktop)', 'wp-simple-toc' ); ?>
                </option>
                <option value="float_right" <?php selected( $position, 'float_right' ); ?>>
                    <?php esc_html_e( 'Floating Right (desktop)', 'wp-simple-toc' ); ?>
                </option>
            </select>
        </p>

        <p>
            <label for="wp_simple_toc_meta_title"><strong><?php esc_html_e( 'Custom Meta Title', 'wp-simple-toc' ); ?></strong></label><br/>
            <input
                type="text"
                id="wp_simple_toc_meta_title"
                name="wp_simple_toc_meta_title"
                class="widefat"
                value="<?php echo esc_attr( $meta_title ); ?>"
                placeholder="<?php esc_attr_e( 'Optional: override browser/SEO title', 'wp-simple-toc' ); ?>"
            />
        </p>

        <hr/>

        <p><strong><?php esc_html_e( 'TOC Preview', 'wp-simple-toc' ); ?></strong></p>
        <div class="simple-toc simple-toc--preview">
            <?php
            $items = $this->extract_headings( $post->post_content );
            if ( empty( $items ) ) {
                echo '<p><em>' . esc_html__( 'No H2/H3 headings found yet.', 'wp-simple-toc' ) . '</em></p>';
            } else {
                echo $this->build_toc_box_html( $items, 'top' );
            }
            ?>
        </div>
        <?php
    }

    public function save_toc_meta_box( $post_id ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if (
            ! isset( $_POST['wp_simple_toc_meta_nonce_field'] ) ||
            ! wp_verify_nonce( $_POST['wp_simple_toc_meta_nonce_field'], 'wp_simple_toc_meta_action' )
        ) {
            return;
        }

        // ENABLE / DISABLE
        $enabled = isset( $_POST['wp_simple_toc_enabled'] ) ? '1' : '0';
        update_post_meta( $post_id, self::META_ENABLED, $enabled );

        // POSITION
        if ( isset( $_POST['wp_simple_toc_position'] ) ) {
            $position = sanitize_text_field( $_POST['wp_simple_toc_position'] );
            if ( ! in_array( $position, [ 'top', 'float_left', 'float_right' ], true ) ) {
                $position = 'top';
            }
            update_post_meta( $post_id, self::META_POSITION, $position );
        }

        // META TITLE
        if ( isset( $_POST['wp_simple_toc_meta_title'] ) ) {
            $meta_title = sanitize_text_field( $_POST['wp_simple_toc_meta_title'] );
            update_post_meta( $post_id, self::META_META_TITLE, $meta_title );
        }
    }

    /**
     * Override document title jika meta title diisi.
     */
    public function filter_document_title( $parts ) {
        if ( ! is_singular( 'post' ) ) {
            return $parts;
        }

        $post_id    = get_queried_object_id();
        $meta_title = get_post_meta( $post_id, self::META_META_TITLE, true );

        if ( ! empty( $meta_title ) ) {
            $parts['title'] = $meta_title;
        }

        return $parts;
    }
}

new WP_Simple_TOC();
