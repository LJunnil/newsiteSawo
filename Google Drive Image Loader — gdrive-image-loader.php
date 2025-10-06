<?php
/**
 * Plugin Name: Google Drive Image Loader
 * Plugin URI:  https://s.sawo.com/
 * Description: Load images from Google Drive (public share links or file IDs) and use them via shortcode or as background images. No OAuth required — admin must provide share URLs or file IDs.
 * Version:     1.0.0
 * Author:      Sawo Developer
 * Text Domain: gdrive-image-loader
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class GDrive_Image_Loader {
    const OPTION_KEY = 'gdrive_image_loader_images';
    const NONCE      = 'gdrive_image_loader_nonce';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'maybe_handle_post' ) );
        add_shortcode( 'gdrive_image', array( $this, 'shortcode_img' ) );
        add_shortcode( 'gdrive_image_url', array( $this, 'shortcode_img_url' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
    }

    public function admin_assets( $hook ) {
        if ( strpos( $hook, 'gdrive-images' ) === false ) return;
        wp_enqueue_style( 'gdrive-admin-style' );
        wp_enqueue_script( 'gdrive-admin-script' );
        // register minimal inline styles/scripts to avoid external files
        wp_add_inline_style( 'gdrive-admin-style', '.gdrive-table{width:100%;border-collapse:collapse}.gdrive-table th,.gdrive-table td{padding:8px;border:1px solid #ddd}.gdrive-form{max-width:800px}.button-delete{color:#a00}.gdrive-preview{max-width:200px;height:auto}' );
        wp_add_inline_script( 'gdrive-admin-script', "document.addEventListener('DOMContentLoaded',function(){const list=document.querySelectorAll('.gdrive-copy-url');list.forEach(btn=>btn.addEventListener('click',function(e){const id=this.dataset.key;const input=document.getElementById('gdrive-url-'+id);if(!input)return;input.select();document.execCommand('copy');this.textContent='Copied';setTimeout(()=>this.textContent='Copy URL',1200);}));});" );
    }

    public function register_admin_menu() {
        add_menu_page(
            'GDrive Images',
            'GDrive Images',
            'manage_options',
            'gdrive-images',
            array( $this, 'admin_page' ),
            'dashicons-googleplus',
            60
        );
    }

    private function get_images() {
        $images = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $images ) ) $images = array();
        return $images;
    }

    private function save_images( $images ) {
        update_option( self::OPTION_KEY, $images );
    }

    public function maybe_handle_post() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( empty( $_POST ) ) return;
        if ( empty( $_POST['_gdrive_action'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['_gdrive_nonce'] ?? '', self::NONCE ) ) return;

        $action = sanitize_text_field( $_POST['_gdrive_action'] );
        $images = $this->get_images();

        if ( $action === 'add' ) {
            $title = sanitize_text_field( $_POST['gdrive_title'] ?? '' );
            $raw   = sanitize_text_field( $_POST['gdrive_raw'] ?? '' );
            if ( empty( $raw ) ) {
                add_settings_error( 'gdrive_messages', 'empty', 'Image URL or File ID required', 'error' );
                return;
            }
            $direct = $this->convert_to_direct_url( $raw );
            $key = $this->generate_key( $title ?: $raw );
            // avoid collision
            $i = 1; $base = $key;
            while ( isset( $images[ $key ] ) ) { $key = $base . '-' . $i; $i++; }

            $images[ $key ] = array(
                'title' => $title ?: $raw,
                'raw' => $raw,
                'url' => $direct,
                'created' => current_time( 'mysql' ),
            );

            $this->save_images( $images );
            add_settings_error( 'gdrive_messages', 'added', 'Image added.', 'updated' );
        }

        if ( $action === 'delete' ) {
            $key = sanitize_text_field( $_POST['key'] ?? '' );
            if ( isset( $images[ $key ] ) ) {
                unset( $images[ $key ] );
                $this->save_images( $images );
                add_settings_error( 'gdrive_messages', 'deleted', 'Image removed.', 'updated' );
            }
        }

        if ( $action === 'import_multiple' ) {
            // paste multiple Drive share URLs or file IDs (one per line)
            $bulk = trim( $_POST['gdrive_bulk'] ?? '' );
            if ( $bulk !== '' ) {
                $lines = preg_split('/\r?\n/', $bulk);
                foreach ( $lines as $ln ) {
                    $ln = trim( $ln );
                    if ( empty( $ln ) ) continue;
                    $direct = $this->convert_to_direct_url( $ln );
                    $title = $ln;
                    $key = $this->generate_key( $title );
                    $i = 1; $base = $key;
                    while ( isset( $images[ $key ] ) ) { $key = $base . '-' . $i; $i++; }
                    $images[ $key ] = array(
                        'title' => $title,
                        'raw' => $ln,
                        'url' => $direct,
                        'created' => current_time( 'mysql' ),
                    );
                }
                $this->save_images( $images );
                add_settings_error( 'gdrive_messages', 'bulk', 'Imported images.', 'updated' );
            }
        }

        // redirect to avoid re-post
        wp_safe_redirect( menu_page_url( 'gdrive-images', false ) );
        exit;
    }

    private function generate_key( $str ) {
        $key = sanitize_title( mb_substr( $str, 0, 60 ) );
        if ( empty( $key ) ) $key = 'img-' . time();
        return $key;
    }

    /**
     * Convert various Google Drive share links or file IDs into a direct view URL.
     * If the input doesn't look like Drive, return as-is.
     */
    public function convert_to_direct_url( $input ) {
        $input = trim( $input );
        // If it already looks like a direct uc link, return
        if ( strpos( $input, 'drive.google.com/uc' ) !== false ) return $input;

        // If it's already a full URL that doesn't match Drive, return as-is
        if ( filter_var( $input, FILTER_VALIDATE_URL ) && strpos( $input, 'drive.google.com' ) === false ) {
            return $input;
        }

        // Patterns for Drive
        // 1) https://drive.google.com/file/d/FILEID/view?usp=sharing
        if ( preg_match('#/file/d/([a-zA-Z0-9_-]+)#', $input, $m) ) {
            $id = $m[1];
            return 'https://drive.google.com/uc?export=view&id=' . $id;
        }

        // 2) https://drive.google.com/open?id=FILEID
        if ( preg_match('#[?&]id=([a-zA-Z0-9_-]+)#', $input, $m) ) {
            $id = $m[1];
            return 'https://drive.google.com/uc?export=view&id=' . $id;
        }

        // 3) shareable link https://drive.google.com/drive/folders/FOLDERID or file direct link with id at end
        if ( preg_match('#/d/([a-zA-Z0-9_-]+)#', $input, $m) ) {
            $id = $m[1];
            return 'https://drive.google.com/uc?export=view&id=' . $id;
        }

        // 4) If input looks like a bare file ID
        if ( preg_match('#^[a-zA-Z0-9_-]{10,}$#', $input) ) {
            return 'https://drive.google.com/uc?export=view&id=' . $input;
        }

        // fallback: return input unchanged
        return $input;
    }

    public function admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        settings_errors( 'gdrive_messages' );
        $images = $this->get_images();
        ?>
        <div class="wrap">
            <h1>Google Drive Image Loader</h1>
            <p>Use public Google Drive image links or file IDs. The plugin converts them into direct view URLs you can use on the site.</p>

            <h2>Add single image</h2>
            <form method="post" class="gdrive-form">
                <?php wp_nonce_field( self::NONCE, '_gdrive_nonce' ); ?>
                <input type="hidden" name="_gdrive_action" value="add">
                <table class="form-table">
                    <tr>
                        <th><label for="gdrive_title">Title</label></th>
                        <td><input name="gdrive_title" id="gdrive_title" class="regular-text" placeholder="Optional friendly title"></td>
                    </tr>
                    <tr>
                        <th><label for="gdrive_raw">Drive share URL or File ID</label></th>
                        <td><input name="gdrive_raw" id="gdrive_raw" class="regular-text" placeholder="e.g. https://drive.google.com/file/d/FILEID/view?usp=sharing or FILEID" required></td>
                    </tr>
                </table>
                <p class="submit"><button class="button button-primary">Add Image</button></p>
            </form>

            <h2>Import multiple (one per line)</h2>
            <form method="post" class="gdrive-form">
                <?php wp_nonce_field( self::NONCE, '_gdrive_nonce' ); ?>
                <input type="hidden" name="_gdrive_action" value="import_multiple">
                <textarea name="gdrive_bulk" rows="6" class="large-text" placeholder="Paste Drive share links or file IDs, one per line"></textarea>
                <p class="submit"><button class="button">Import</button></p>
            </form>

            <h2>Stored images</h2>
            <table class="gdrive-table">
                <thead>
                    <tr><th>Key</th><th>Title</th><th>Preview</th><th>Direct URL</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php if ( empty( $images ) ) : ?>
                    <tr><td colspan="5">No images saved yet.</td></tr>
                <?php else : foreach ( $images as $key => $img ) : $esc_key = esc_attr( $key ); ?>
                    <tr>
                        <td><code><?php echo $esc_key; ?></code></td>
                        <td><?php echo esc_html( $img['title'] ); ?></td>
                        <td>
                            <?php if ( ! empty( $img['url'] ) ) : ?>
                                <img src="<?php echo esc_url( $img['url'] ); ?>" class="gdrive-preview" alt="<?php echo esc_attr( $img['title'] ); ?>">
                            <?php endif; ?>
                        </td>
                        <td>
                            <input type="text" readonly id="gdrive-url-<?php echo $esc_key; ?>" value="<?php echo esc_url( $img['url'] ); ?>" style="width:100%">
                            <button class="button gdrive-copy-url" data-key="<?php echo $esc_key; ?>">Copy URL</button>
                        </td>
                        <td>
                            <form method="post" style="display:inline">
                                <?php wp_nonce_field( self::NONCE, '_gdrive_nonce' ); ?>
                                <input type="hidden" name="_gdrive_action" value="delete">
                                <input type="hidden" name="key" value="<?php echo $esc_key; ?>">
                                <button class="button button-link-delete button-delete" onclick="return confirm('Delete this image?')">Delete</button>
                            </form>
                            <p style="margin-top:8px"><strong>Shortcodes</strong><br>
                            <code>[gdrive_image key="<?php echo $esc_key; ?>" alt="Optional alt"]</code><br>
                            <code>[gdrive_image_url key="<?php echo $esc_key; ?>"]</code></p>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>