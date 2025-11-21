<?php
/**
 * Plugin Name: Simple TOC
 * Description: Automatically generates a Table of Contents for single posts based on H2 and H3 headings, with configurable position.
 * Version:     1.0.1
 * Author:      Lanang Bayu S
 * Text Domain: simple-toc
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Simple_TOC {

    const OPTION_POSITION = 'simple_toc_position';

    public function __construct() {
        // Settings page
        add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );

        // Inject TOC ke konten
        add_filter( 'the_content', [ $this, 'inject_toc_into_content' ], 20 );
    }

    public function add_settings_page() {
        add_options_page(
            __( 'Simple TOC', 'simple-toc' ),
            __( 'Simple TOC', 'simple-toc' ),
            'manage_options',
            'simple-toc',
            [ $this, 'render_settings_page' ]
        );
    }

    public function register_settings() {
        register_setting(
            'simple_toc_settings_group',
            self::OPTION_POSITION,
            [
                'type'              => 'string',
                'sanitize_callback' => [ $this, 'sanitize_position' ],
                'default'           => 'top',
            ]
        );
    }

    public function sanitize_position( $value ) {
        $allowed = [ 'top', 'middle', 'bottom' ];
        return in_array( $value, $allowed, true ) ? $value : 'top';
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $position = get_option( self::OPTION_POSITION, 'top' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Simple TOC Settings', 'simple-toc' ); ?></h1>

            <form method="post" action="options.php">
                <?php settings_fields( 'simple_toc_settings_group' ); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="simple_toc_position">
                                <?php esc_html_e( 'TOC Position', 'simple-toc' ); ?>
                            </label>
                        </th>
                        <td>
                            <select id="simple_toc_position" name="<?php echo esc_attr( self::OPTION_POSITION ); ?>">
                                <option value="top" <?php selected( $position, 'top' ); ?>>
                                    <?php esc_html_e( 'Top (before content)', 'simple-toc' ); ?>
                                </option>
                                <option value="middle" <?php selected( $position, 'middle' ); ?>>
                                    <?php esc_html_e( 'Middle (around 50% of content)', 'simple-toc' ); ?>
                                </option>
                                <option value="bottom" <?php selected( $position, 'bottom' ); ?>>
                                    <?php esc_html_e( 'Bottom (after content)', 'simple-toc' ); ?>
                                </option>
                            </select>
                            <p class="description">
                                <?php esc_html_e( 'Choose where the Table of Contents will be inserted in single posts.', 'simple-toc' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function inject_toc_into_content( $content ) {
        // Hanya di single post utama (front-end)
        if ( is_admin() ) {
            return $content;
        }

        if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }

        // Cari heading H2 & H3
        $pattern = '/<h([2-3])([^>]*)>(.*?)<\/h\1>/i';
        if ( ! preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
            return $content; // tidak ada heading
        }

        if ( count( $matches ) < 2 ) {
            // minimal 2 heading baru tampilkan TOC
            return $content;
        }

        // Buat TOC + inject id ke heading
        $index     = 0;
        $toc_items = [];
        $new_content = $content;

        $new_content = preg_replace_callback(
            $pattern,
            function ( $match ) use ( &$index, &$toc_items ) {
                $index++;

                $level      = $match[1]; // 2 atau 3
                $attributes = $match[2];
                $title_html = $match[3];

                // Ambil text plain dari heading (strip tag)
                $title_text = wp_strip_all_tags( $title_html );

                // Buat slug id
                $slug_base = sanitize_title( $title_text );
                if ( empty( $slug_base ) ) {
                    $slug_base = 'toc-' . $index;
                }
                $id = 'simple-toc-' . $slug_base . '-' . $index;

                // Simpan untuk TOC
                $toc_items[] = [
                    'id'    => $id,
                    'title' => $title_text,
                    'level' => (int) $level,
                ];

                // Tambah id ke heading
                // Pastikan tidak dobel id
                if ( strpos( $attributes, 'id=' ) === false ) {
                    $attributes .= ' id="' . esc_attr( $id ) . '"';
                }

                return sprintf(
                    '<h%1$d%2$s>%3$s</h%1$d>',
                    $level,
                    $attributes,
                    $title_html
                );
            },
            $content
        );

        if ( empty( $toc_items ) ) {
            return $content;
        }

        // Build HTML TOC
        $toc_html  = '<nav class="simple-toc">';
        $toc_html .= '<div class="simple-toc-title">' . esc_html__( 'Table of Contents', 'simple-toc' ) . '</div>';
        $toc_html .= '<ul class="simple-toc-list">';

        foreach ( $toc_items as $item ) {
            $indent_class = $item['level'] === 3 ? ' simple-toc-item--child' : '';
            $toc_html     .= sprintf(
                '<li class="simple-toc-item%1$s"><a href="#%2$s">%3$s</a></li>',
                $indent_class,
                esc_attr( $item['id'] ),
                esc_html( $item['title'] )
            );
        }

        $toc_html .= '</ul>';
        $toc_html .= '</nav>';

        // Sisipkan sesuai posisi
        $position = get_option( self::OPTION_POSITION, 'top' );

        switch ( $position ) {
            case 'bottom':
                $output = $new_content . $toc_html;
                break;

            case 'middle':
                $len      = strlen( $new_content );
                $midpoint = (int) floor( $len / 2 );
                $output   = substr( $new_content, 0, $midpoint ) . $toc_html . substr( $new_content, $midpoint );
                break;

            case 'top':
            default:
                $output = $toc_html . $new_content;
                break;
        }

        return $output;
    }
}

new Simple_TOC();
