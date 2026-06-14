<?php
/**
 * Import view — wizard upload, validate, import.
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

use TheExporter\Admin\Components\UI;
use TheExporter\Settings;

$php_limits      = Settings::php_upload_limits();
$transfer_max    = Settings::get( 'browser_transfer_max_bytes' );
$import_base     = Settings::get( 'import_base_path' );
$folder_mode     = Settings::is_sftp_transfer();
$connected_mode  = Settings::is_connected_transfer();
?>

<div class="te-wizard-shell" id="te-import-wizard" data-import-base="<?php echo esc_attr( $import_base ); ?>" data-transfer-mode="<?php echo esc_attr( Settings::transfer_mode() ); ?>">
	<?php
	UI::wizard_steps(
		array(
			__( 'Connect', 'the-exporter' ),
			__( 'Transfer', 'the-exporter' ),
			__( 'Verify', 'the-exporter' ),
			__( 'Apply', 'the-exporter' ),
			__( 'Done', 'the-exporter' ),
		),
		1,
		'te-import-wizard-steps'
	);
	?>
</div>

<div class="te-grid te-grid--1 te-mt-lg">
	<?php
	ob_start();
	?>

	<label class="te-label" for="te-import-migration-id"><?php esc_html_e( 'Migration ID (from export site)', 'the-exporter' ); ?></label>
	<input type="text" class="te-input" id="te-import-migration-id" value="" placeholder="<?php esc_attr_e( 'Paste ID, then choose files', 'the-exporter' ); ?>" autocomplete="off" />

	<?php if ( $connected_mode ) : ?>
		<div id="te-incoming-transfer" class="te-status-panel te-status-panel--info te-mt-md">
			<p class="te-text-muted"><?php esc_html_e( 'Connected site mode: files arrive automatically from your export site. Paste Migration ID, then wait for transfer to complete.', 'the-exporter' ); ?></p>
			<p id="te-incoming-transfer-progress" class="te-text-muted"></p>
		</div>
	<?php endif; ?>

	<?php if ( $transfer_max > min( $php_limits['upload_max_filesize'], $php_limits['post_max_size'] ) ) : ?>
		<p class="te-text-warning te-mt-md"><?php esc_html_e( 'PHP upload limits are lower than your browser transfer setting. Use folder transfer or Connected site mode for large files.', 'the-exporter' ); ?></p>
	<?php endif; ?>

	<div id="te-import-pipeline" class="te-mt-md te-pipeline-placeholder"></div>
	<div id="te-import-status" class="te-status-panel te-status-panel--info" hidden></div>

	<div class="te-import-progress-wrap te-mt-md">
		<p id="te-import-upload-progress" class="te-text-muted"></p>
		<div class="te-import-upload-ring" id="te-import-upload-ring" hidden></div>
	</div>

	<div class="te-mt-md">
		<label class="te-label" for="te-manifest-file"><?php esc_html_e( '1. manifest.json', 'the-exporter' ); ?></label>
		<div class="te-actions te-import-manifest-row">
			<input type="file" class="te-input" id="te-manifest-file" accept=".json,application/json" />
			<?php UI::button( __( 'Upload manifest', 'the-exporter' ), 'secondary', array( 'id' => 'te-upload-manifest', 'disabled' => 'disabled' ) ); ?>
		</div>
		<p class="te-text-muted te-mt-sm"><?php esc_html_e( 'Paste Migration ID first, then choose manifest.json and click Upload manifest.', 'the-exporter' ); ?></p>
	</div>

	<div class="te-actions te-mt-sm" id="te-import-scan-wrap">
		<?php UI::button( __( 'Scan import folder', 'the-exporter' ), 'secondary', array( 'id' => 'te-scan-import-folder' ) ); ?>
	</div>
	<p class="te-text-muted te-mt-sm" id="te-import-sftp-hint">
		<?php
		if ( $folder_mode ) {
			esc_html_e( 'Folder transfer: copy files into the import folder (see guide below), then click Scan import folder.', 'the-exporter' );
		} elseif ( $connected_mode ) {
			esc_html_e( 'When the export site sends files, click Scan import folder to refresh the checklist.', 'the-exporter' );
		} else {
			esc_html_e( 'Upload files via browser, or switch to Folder transfer / Connected site in Settings for large migrations.', 'the-exporter' );
		}
		?>
	</p>

	<?php if ( $folder_mode || $connected_mode ) : ?>
		<?php
		$context = 'import';
		$migration_id = '';
		include TE_PLUGIN_DIR . 'admin/views/partials/transfer-guide.php';
		?>
	<?php endif; ?>

	<div class="te-mt-md" id="te-package-upload-wrap" hidden>
		<label class="te-label" for="te-package-files"><?php esc_html_e( '2. All package files', 'the-exporter' ); ?></label>
		<input type="file" class="te-input" id="te-package-files" multiple />
		<p class="te-text-muted te-mt-md"><?php esc_html_e( 'Select every downloaded file. Uploads run automatically with retry.', 'the-exporter' ); ?></p>
		<div class="te-actions te-mt-sm">
			<?php UI::button( __( 'Upload missing files', 'the-exporter' ), 'secondary', array( 'id' => 'te-retry-missing', 'disabled' => 'disabled' ) ); ?>
			<input type="file" class="te-input te-sr-only" id="te-retry-missing-files" multiple />
		</div>
	</div>

	<div id="te-import-missing-panel" class="te-import-missing-panel te-mt-md" hidden></div>
	<div id="te-import-sftp-hints" class="te-import-sftp-hints te-mt-md" hidden></div>

	<ul id="te-import-file-list" class="te-expected-files__list te-import-checklist"></ul>

	<div class="te-actions te-mt-md">
		<?php UI::button( __( 'Validate migration', 'the-exporter' ), 'secondary', array( 'id' => 'te-import-validate', 'disabled' => 'disabled' ) ); ?>
		<?php UI::button( __( 'Import all', 'the-exporter' ), 'primary', array( 'id' => 'te-import-all', 'disabled' => 'disabled' ) ); ?>
		<?php UI::button( __( 'Import via server', 'the-exporter' ), 'secondary', array( 'id' => 'te-import-queue', 'disabled' => 'disabled', 'title' => __( 'Background import for large migrations (recommended for 50GB+)', 'the-exporter' ) ) ); ?>
	</div>

	<div id="te-import-report" class="te-mt-md"></div>
	<?php
	UI::glass_card( __( 'Import Migration', 'the-exporter' ), ob_get_clean() );
	?>
</div>
