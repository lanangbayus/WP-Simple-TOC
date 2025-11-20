<?php
/**
 * Plugin Name: WP Simple TOC
 * Description: Automatically generates a Table of Contents (TOC) from headings in the post, with live preview in the editor and position control (top, middle, bottom).
 * Version:     1.0.0
 * Author:      Lanang Bayu S
 * Text Domain: wp-simple-toc
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Simple_TOC {

    const META_ENABLE   = '_wptoc_enable';
    const META_POSITION = '_wptoc_position';

    public function __construct() {
        // Meta box untuk pengaturan per post
        add_action( 'add_meta_boxes', [ $this, 'add_toc_metabox' ] );
        add_action( 'save_post', [ $this, 'save_toc_meta' ] );

        // Inject TOC ke content front-end
        add_filter( 'the_content', [ $this, 'inject_toc_into_content' ] );

        // Admin assets untuk preview TOC
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
    }

    public function add_toc_metabox() {
        add_meta_box(
            'wptoc_metabox',
            __( 'Table of Contents (TOC)', 'wp-simple-toc' ),
            [ $this, 'render_toc_metabox' ],
            [ 'post', 'page' ], // bisa kamu tambah CPT kalau mau
            'side',
            'default'
        );
    }

    public function render_toc_metabox( $post ) {
        $enabled  = get_post_meta( $post->ID, self::META_ENABLE, true );
        $position = get_post_meta( $post->ID, self::META_POSITION, true );

        if ( $position === '' ) {
            $position = 'top'; // default
        }

        wp_nonce_field( 'wptoc_save_meta_' . $post->ID, 'wptoc_meta_nonce' );
        ?>
        <p>
            <label>
                <input type="checkbox" name="wptoc_enable" value="1" <?php checked( $enabled, '1' ); ?> />
                <?php esc_html_e( 'Enable TOC for this post', 'wp-simple-toc' ); ?>
            </label>
        </p>

        <p><strong><?php esc_html_e( 'TOC Position', 'wp-simple-toc' ); ?></strong></p>
        <p>
            <label>
                <input type="radio" name="wptoc_position" value="top" <?php checked( $position, 'top' ); ?> />
                <?php esc_html_e( 'Top (before content)', 'wp-simple-toc' ); ?>
            </label><br>
            <label>
                <input type="radio" name="wptoc_position" value="middle" <?php checked( $position, 'middle' ); ?> />
                <?php esc_html_e( 'Middle (inserted in the middle of content)', 'wp-simple-toc' ); ?>
            </label><br>
            <label>
                <input type="radio" name="wptoc_position" value="bottom" <?php checked( $position, 'bottom' ); ?> />
                <?php esc_html_e( 'Bottom (after content)', 'wp-simple-toc' ); ?>
            </label>
        </p>

        <hr>

        <p><strong><?php esc_html_e( 'Live TOC Preview', 'wp-simple-toc' ); ?></strong></p>
        <p class="description">
            <?php esc_html_e( 'The preview below is generated from the current content (H2 & H3 headings).', 'wp-simple-toc' ); ?>
        </p>
        <div id="wptoc-preview" style="max-height:200px; overflow:auto; border:1px solid #ddd; padding:8px; background:#fafafa; font-size:12px;">
            <em><?php esc_html_e( 'Start writing headings (H2, H3) in your content to see the TOC preview here.', 'wp-simple-toc' ); ?></em>
        </div>
        <?php
    }

    public function save_toc_meta( $post_id ) {
        // Auto-save / revisions skip
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! isset( $_POST['wptoc_meta_nonce'] ) || ! wp_verify_nonce( $_POST['wptoc_meta_nonce'], 'wptoc_save_meta_' . $post_id ) ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $enabled  = isset( $_POST['wptoc_enable'] ) ? '1' : '0';
        $position = isset( $_POST['wptoc_position'] ) ? sanitize_text_field( $_POST['wptoc_position'] ) : 'top';

        if ( ! in_array( $position, [ 'top', 'middle', 'bottom' ], true ) ) {
            $position = 'top';
        }

        update_post_meta( $post_id, self::META_ENABLE, $enabled );
        update_post_meta( $post_id, self::META_POSITION, $position );
    }

    public function inject_toc_into_content( $content ) {
        if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }

        global $post;

        $enabled = get_post_meta( $post->ID, self::META_ENABLE, true );
        if ( $enabled !== '1' ) {
            return $content;
        }

        $position = get_post_meta( $post->ID, self::META_POSITION, true );
        if ( ! in_array( $position, [ 'top', 'middle', 'bottom' ], true ) ) {
            $position = 'top';
        }

        $toc_html = $this->generate_toc_html( $content );

        // Kalau H2/H3 kurang dari 2, jangan tampilkan TOC (optional, good UX)
        if ( empty( $toc_html ) ) {
            return $content;
        }

        switch ( $position ) {
            case 'top':
                return $toc_html . $content;

            case 'bottom':
                return $content . $toc_html;

            case 'middle':
                return $this->inject_toc_middle( $content, $toc_html );

            default:
                return $toc_html . $content;
        }
    }

    protected function generate_toc_html( $content ) {
        $headings = $this->extract_headings( $content );

        if ( count( $headings ) < 2 ) {
            return '';
        }

        $items = '';
        foreach ( $headings as $h ) {
            $text = esc_html( $h['text'] );
            $id   = esc_attr( $h['id'] );
            $indent_class = $h['level'] === 'h3' ? 'wptoc-item--child' : 'wptoc-item--parent';

            $items .= sprintf(
                '<li class="wptoc-item %s"><a href="#%s">%s</a></li>',
                $indent_class,
                $id,
                $text
            );
        }

        if ( empty( $items ) ) {
            return '';
        }

        $html  = '<div class="wptoc-container">';
        $html .= '<div class="wptoc-title">Table of Contents</div>';
        $html .= '<ul class="wptoc-list">';
        $html .= $items;
        $html .= '</ul>';
        $html .= '</div>';

        // Tambah CSS minimal inline (biar plugin tetap tanpa file CSS kalau mau)
        $html .= '<style>
            .wptoc-container{border:1px solid #ddd;background:#fafafa;padding:10px 12px;margin:16px 0;font-size:14px;}
            .wptoc-title{font-weight:600;margin-bottom:6px;}
            .wptoc-list{list-style:none;margin:0;padding-left:0;}
            .wptoc-item{margin:2px 0;}
            .wptoc-item--child{margin-left:16px;font-size:13px;}
            .wptoc-list a{text-decoration:none;color:#0073aa;}
            .wptoc-list a:hover{text-decoration:underline;}
        </style>';

        // Insert anchor id ke heading di konten asli
        return $html;
    }

    protected function extract_headings( $content ) {
        $headings = [];

        // Pakai DOMDocument biar parsing lebih rapi
        libxml_use_internal_errors( true );
        $doc = new DOMDocument();
        $doc->loadHTML(
            '<?xml encoding="utf-8" ?>' . $content,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        foreach ( [ 'h2', 'h3' ] as $tag ) {
            $nodes = $doc->getElementsByTagName( $tag );
            foreach ( $nodes as $node ) {
                $text = trim( $node->textContent );
                if ( $text === '' ) {
                    continue;
                }

                $id = $node->getAttribute( 'id' );
                if ( ! $id ) {
                    $id = $this->slugify( $text );
                    $node->setAttribute( 'id', $id );
                }

                $headings[] = [
                    'level' => $tag,
                    'text'  => $text,
                    'id'    => $id,
                ];
            }
        }

        // Update konten global dengan id heading baru
        global $post;
        if ( $post instanceof WP_Post ) {
            $new_content = $doc->saveHTML();
            // buang prefix XML
            $new_content = preg_replace( '/^<\?xml.+?\?>/','', $new_content );
            // update konten untuk filter yang sedang jalan
            // WARNING: ini hanya untuk lifecycle filter, tidak menyimpan ke DB
            add_filter( 'the_content', function( $c ) use ( $new_content ) {
                return $new_content;
            }, 9999 );
        }

        // Urutkan berdasarkan urutan kemunculan (sudah by default)
        return $headings;
    }

    protected function inject_toc_middle( $content, $toc_html ) {
        // Cara simple: pecah berdasarkan </p> dan sisipkan di tengah
        $parts = preg_split( '/(<\/p>)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE );

        if ( count( $parts ) <= 2 ) {
            // Konten terlalu pendek, fallback ke top
            return $toc_html . $content;
        }

        $total_chunks = count( $parts );
        $middle_index = (int) floor( $total_chunks / 2 );

        $new = '';
        foreach ( $parts as $index => $chunk ) {
            $new .= $chunk;
            if ( $index === $middle_index ) {
                $new .= $toc_html;
            }
        }

        return $new;
    }

    protected function slugify( $text ) {
        $text = strtolower( trim( $text ) );
        $text = preg_replace( '/[^a-z0-9]+/i', '-', $text );
        $text = trim( $text, '-' );
        if ( $text === '' ) {
            $text = 'section-' . wp_rand( 1000, 9999 );
        }
        return $text;
    }

    public function enqueue_admin_assets( $hook ) {
        // Hanya di post.php & post-new.php
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            return;
        }

        wp_enqueue_script(
            'wptoc-admin-preview',
            plugin_dir_url( __FILE__ ) . 'assets/js/admin-toc-preview.js',
            [ 'jquery' ],
            '1.0.0',
            true
        );
    }
}

new WP_Simple_TOC();
