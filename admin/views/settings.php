<?php
/**
 * Advanced settings view.
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

use TheExporter\Admin\Components\UI;
use TheExporter\Database\Dumper;
use TheExporter\Database\Importer;
use TheExporter\Settings;

$settings = Settings::get_all();
$migrate_url = admin_url( 'admin.php?page=the-exporter-migrate' );
?>
<p class="te-text-muted te-mb-lg">
	<?php
	printf(
		/* translators: %s: Migrate admin page URL */
		wp_kses_post( __( 'Site pairing and connected transfer are configured in the <a href="%s">Migrate wizard</a>. Use this page for paths, chunking, and performance tuning.', 'the-exporter' ) ),
		esc_url( $migrate_url )
	);
	?>
</p>

<form method="post" action="options.php" class="te-settings-form">
	<?php settings_fields( 'te_settings_group' ); ?>

	<div class="te-grid te-grid--2">
		<?php
		ob_start();
		?>
		<label class="te-label" for="te_transfer_mode"><?php esc_html_e( 'Transfer mode', 'the-exporter' ); ?></label>
		<select class="te-input" name="te_settings[transfer_mode]" id="te_transfer_mode">
			<option value="browser" <?php selected( $settings['transfer_mode'], 'browser' ); ?>><?php esc_html_e( 'Browser (dev / small sites)', 'the-exporter' ); ?></option>
			<option value="connected" <?php selected( $settings['transfer_mode'], 'connected' ); ?>><?php esc_html_e( 'Connected site (auto)', 'the-exporter' ); ?></option>
			<option value="sftp" <?php selected( $settings['transfer_mode'], 'sftp' ); ?>><?php esc_html_e( 'Folder transfer (manual SFTP / cPanel)', 'the-exporter' ); ?></option>
		</select>
		<p class="te-text-muted"><?php esc_html_e( 'Connected mode is set up via the Migrate wizard. Folder transfer uses FileZilla or cPanel.', 'the-exporter' ); ?></p>

		<label class="te-label">
			<input type="hidden" name="te_settings[large_segments_sftp]" value="0" />
			<input type="checkbox" name="te_settings[large_segments_sftp]" value="1" <?php checked( ! empty( $settings['large_segments_sftp'] ) || in_array( $settings['transfer_mode'], array( 'sftp', 'connected' ), true ) ); ?> <?php disabled( in_array( $settings['transfer_mode'], array( 'sftp', 'connected' ), true ) ); ?> />
			<?php esc_html_e( 'Large segments (500MB–2GB)', 'the-exporter' ); ?>
		</label>

		<label class="te-label" for="te_segment_compression"><?php esc_html_e( 'Segment compression', 'the-exporter' ); ?></label>
		<select class="te-input" name="te_settings[segment_compression]" id="te_segment_compression">
			<option value="auto" <?php selected( $settings['segment_compression'], 'auto' ); ?>><?php esc_html_e( 'Auto (host detection)', 'the-exporter' ); ?></option>
			<option value="store" <?php selected( $settings['segment_compression'], 'store' ); ?>><?php esc_html_e( 'Store (.tar, fastest on shared hosting)', 'the-exporter' ); ?></option>
			<option value="gzip_fast" <?php selected( $settings['segment_compression'], 'gzip_fast' ); ?>><?php esc_html_e( 'Gzip fast', 'the-exporter' ); ?></option>
			<option value="gzip" <?php selected( $settings['segment_compression'], 'gzip' ); ?>><?php esc_html_e( 'Gzip normal', 'the-exporter' ); ?></option>
		</select>

		<label class="te-label" for="te_export_base_path"><?php esc_html_e( 'Export base path', 'the-exporter' ); ?></label>
		<input type="text" class="te-input te-mono" name="te_settings[export_base_path]" id="te_export_base_path" value="<?php echo esc_attr( $settings['export_base_path'] ); ?>" />

		<label class="te-label" for="te_import_base_path"><?php esc_html_e( 'Import base path', 'the-exporter' ); ?></label>
		<input type="text" class="te-input te-mono" name="te_settings[import_base_path]" id="te_import_base_path" value="<?php echo esc_attr( $settings['import_base_path'] ); ?>" />

		<label class="te-label" for="te_restore_base_path"><?php esc_html_e( 'Restore points path', 'the-exporter' ); ?></label>
		<input type="text" class="te-input te-mono" name="te_settings[restore_base_path]" id="te_restore_base_path" value="<?php echo esc_attr( $settings['restore_base_path'] ); ?>" />

		<label class="te-label" for="te_chunk_size"><?php esc_html_e( 'Chunk size (bytes)', 'the-exporter' ); ?></label>
		<input type="number" class="te-input" name="te_settings[chunk_size_bytes]" id="te_chunk_size" value="<?php echo esc_attr( $settings['chunk_size_bytes'] ); ?>" min="<?php echo esc_attr( $settings['chunk_min_bytes'] ); ?>" max="<?php echo esc_attr( $settings['chunk_max_bytes'] ); ?>" />
		<p class="te-text-muted"><?php esc_html_e( 'Target: 500MB–2GB per segment for folder / connected transfer.', 'the-exporter' ); ?></p>

		<label class="te-label" for="te_browser_transfer_max"><?php esc_html_e( 'Browser transfer max (bytes)', 'the-exporter' ); ?></label>
		<input type="number" class="te-input" name="te_settings[browser_transfer_max_bytes]" id="te_browser_transfer_max" value="<?php echo esc_attr( $settings['browser_transfer_max_bytes'] ); ?>" min="<?php echo esc_attr( $settings['browser_transfer_min_bytes'] ); ?>" max="<?php echo esc_attr( $settings['browser_transfer_max_bytes_cap'] ); ?>" />
		<p class="te-text-muted"><?php esc_html_e( 'Max size per file for browser download/upload (default 64MB). Export segments are capped to this.', 'the-exporter' ); ?></p>
		<?php
		$limits = Settings::php_upload_limits();
		if ( $settings['browser_transfer_max_bytes'] > min( $limits['upload_max_filesize'], $limits['post_max_size'] ) ) :
			?>
			<p class="te-text-warning"><?php esc_html_e( 'Browser transfer max exceeds PHP upload_max_filesize or post_max_size.', 'the-exporter' ); ?></p>
		<?php endif; ?>

		<label class="te-label" for="te_exclude_patterns"><?php esc_html_e( 'Exclude patterns (one per line)', 'the-exporter' ); ?></label>
		<textarea class="te-input te-textarea" name="te_settings[exclude_patterns]" id="te_exclude_patterns" rows="5"><?php echo esc_textarea( implode( "\n", $settings['exclude_patterns'] ) ); ?></textarea>

		<label class="te-label">
			<input type="hidden" name="te_settings[fast_export]" value="0" />
			<input type="checkbox" name="te_settings[fast_export]" value="1" <?php checked( ! empty( $settings['fast_export'] ) ); ?> />
			<?php esc_html_e( 'Fast export (recommended)', 'the-exporter' ); ?>
		</label>
		<p class="te-text-muted"><?php esc_html_e( 'Skip per-file SHA256 during export; verify segment tar.gz checksums instead. Much faster for large themes.', 'the-exporter' ); ?></p>

		<label class="te-label" for="te_max_files_per_segment"><?php esc_html_e( 'Max files per segment', 'the-exporter' ); ?></label>
		<input type="number" class="te-input" name="te_settings[max_files_per_segment]" id="te_max_files_per_segment" value="<?php echo esc_attr( $settings['max_files_per_segment'] ); ?>" min="50" max="2000" />
		<p class="te-text-muted"><?php esc_html_e( 'Caps files per 64MB segment (default 400). Prevents long freezes when packing thousands of small theme files.', 'the-exporter' ); ?></p>

		<label class="te-label" for="te_compression_level"><?php esc_html_e( 'Legacy gzip level', 'the-exporter' ); ?></label>
		<select class="te-input" name="te_settings[compression_level]" id="te_compression_level">
			<option value="fast" <?php selected( $settings['compression_level'], 'fast' ); ?>><?php esc_html_e( 'Fast (gzip -1)', 'the-exporter' ); ?></option>
			<option value="normal" <?php selected( $settings['compression_level'], 'normal' ); ?>><?php esc_html_e( 'Normal (default gzip)', 'the-exporter' ); ?></option>
		</select>
		<?php
		UI::glass_card( __( 'Paths & Performance', 'the-exporter' ), ob_get_clean() );

		ob_start();
		?>
		<ul class="te-env-list">
			<li><?php esc_html_e( 'mysqldump:', 'the-exporter' ); ?> <?php UI::status_badge( Dumper::has_mysqldump() ? 'completed' : 'failed', Dumper::has_mysqldump() ? __( 'Available', 'the-exporter' ) : __( 'Not found', 'the-exporter' ) ); ?></li>
			<li><?php esc_html_e( 'mysql CLI:', 'the-exporter' ); ?> <?php UI::status_badge( Importer::has_mysql_cli() ? 'completed' : 'failed', Importer::has_mysql_cli() ? __( 'Available', 'the-exporter' ) : __( 'Not found', 'the-exporter' ) ); ?></li>
			<li><?php esc_html_e( 'WP-CLI:', 'the-exporter' ); ?> <?php UI::status_badge( ( defined( 'WP_CLI' ) && WP_CLI ) ? 'completed' : 'pending', ( defined( 'WP_CLI' ) && WP_CLI ) ? __( 'Yes', 'the-exporter' ) : __( 'Admin only', 'the-exporter' ) ); ?></li>
		</ul>
		<p class="te-text-muted te-mt-md"><?php esc_html_e( 'For 50GB+ sites, SSH + WP-CLI + mysqldump on production is strongly recommended.', 'the-exporter' ); ?></p>
		<?php
		UI::glass_card( __( 'Environment', 'the-exporter' ), ob_get_clean() );
		?>
	</div>

	<div class="te-actions te-mt-lg">
		<?php submit_button( __( 'Save Settings', 'the-exporter' ), 'primary te-btn te-btn--primary', 'submit', false ); ?>
	</div>
</form>
