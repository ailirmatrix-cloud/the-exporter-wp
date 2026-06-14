<?php
/**
 * Export view — wizard export and download.
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

use TheExporter\Admin\Components\UI;
use TheExporter\Settings;

$migration_id   = Settings::get( 'active_migration_id' );
$wizard_step    = $migration_id ? 2 : 1;
$folder_mode    = Settings::is_sftp_transfer();
$connected_mode = Settings::is_connected_transfer();
$server_mode    = $folder_mode || $connected_mode;
$export_path    = $migration_id ? Settings::migration_path( $migration_id, 'export' ) : Settings::get( 'export_base_path' );
?>

<div class="te-wizard-shell" id="te-export-wizard" data-transfer-mode="<?php echo esc_attr( Settings::transfer_mode() ); ?>">
	<?php
	UI::wizard_steps(
		array(
			__( 'Start', 'the-exporter' ),
			__( 'Build packages', 'the-exporter' ),
			__( 'Seal manifest', 'the-exporter' ),
			__( 'Download', 'the-exporter' ),
		),
		$wizard_step,
		'te-export-wizard-steps'
	);
	?>
</div>

<div class="te-grid te-grid--1 te-mt-lg">
	<?php
	ob_start();
	?>
	<?php if ( ! $migration_id ) : ?>
		<p class="te-text-muted"><?php esc_html_e( 'Create a new migration and export your site.', 'the-exporter' ); ?></p>
		<?php UI::button( __( 'Start Export', 'the-exporter' ), 'primary', array( 'id' => 'te-init-export' ) ); ?>
	<?php else : ?>
		<p class="te-text-muted"><?php esc_html_e( 'Copy this Migration ID — you need it on the import site.', 'the-exporter' ); ?></p>
		<div class="te-copy-field">
			<code class="te-mono" id="te-export-migration-id"><?php echo esc_html( $migration_id ); ?></code>
			<?php UI::button( __( 'Copy ID', 'the-exporter' ), 'ghost', array( 'id' => 'te-copy-migration-id', 'data-copy' => $migration_id ) ); ?>
		</div>
		<div id="te-export-pipeline" class="te-mt-md te-pipeline-placeholder">
			<?php if ( Settings::is_fast_export() ) : ?>
				<p><span class="te-badge te-badge--success"><?php esc_html_e( 'Fast mode ON', 'the-exporter' ); ?></span></p>
			<?php endif; ?>
			<?php if ( $connected_mode ) : ?>
				<p><span class="te-badge te-badge--info"><?php esc_html_e( 'Connected site mode', 'the-exporter' ); ?></span></p>
			<?php endif; ?>
			<p class="te-text-muted"><?php esc_html_e( 'Ready to build your migration package.', 'the-exporter' ); ?></p>
		</div>
		<div class="te-actions te-mt-md">
			<?php if ( $server_mode ) : ?>
				<?php UI::button( __( 'Export via server', 'the-exporter' ), 'primary', array( 'id' => 'te-export-all' ) ); ?>
				<?php if ( $connected_mode ) : ?>
					<?php UI::button( __( 'Send to import site', 'the-exporter' ), 'secondary', array( 'id' => 'te-send-to-import', 'title' => __( 'Push packages to your connected import site over HTTPS', 'the-exporter' ) ) ); ?>
					<div id="te-push-status" class="te-status-panel te-status-panel--info te-mt-sm" hidden></div>
				<?php endif; ?>
				<p class="te-text-muted te-mt-sm">
					<?php
					if ( $connected_mode ) {
						esc_html_e( 'Runs export on the server. When done, click Send to import site — or enable auto-push in Settings.', 'the-exporter' );
					} else {
						esc_html_e( 'Runs export in background on the server. Copy files using the guide below when done.', 'the-exporter' );
					}
					?>
				</p>
				<?php if ( $folder_mode ) : ?>
					<div class="te-export-path te-mt-md">
						<p class="te-text-muted te-mb-sm"><?php esc_html_e( 'Export folder:', 'the-exporter' ); ?></p>
						<code class="te-mono" id="te-export-path-hint"><?php echo esc_html( $export_path ); ?></code>
					</div>
				<?php endif; ?>
			<?php else : ?>
				<?php UI::button( __( 'Export All', 'the-exporter' ), 'primary', array( 'id' => 'te-export-all' ) ); ?>
			<?php endif; ?>
		</div>
		<?php if ( $folder_mode ) : ?>
			<?php
			$context = 'export';
			include TE_PLUGIN_DIR . 'admin/views/partials/transfer-guide.php';
			?>
		<?php endif; ?>
	<?php endif; ?>
	<?php
	UI::glass_card( __( 'Export', 'the-exporter' ), ob_get_clean() );
	?>
</div>

<?php if ( $migration_id ) : ?>
<div class="te-mt-lg" id="te-download-packages" data-migration-id="<?php echo esc_attr( $migration_id ); ?>">
	<?php
	ob_start();
	?>
	<p class="te-text-muted">
		<?php
		if ( $connected_mode ) {
			esc_html_e( 'After export finishes, use Send to import site. Browser download is optional.', 'the-exporter' );
		} elseif ( $folder_mode ) {
			esc_html_e( 'After export finishes, copy the folder using the transfer guide above.', 'the-exporter' );
		} else {
			esc_html_e( 'After export finishes, transfer files to your import site. Use “Save all to folder” (recommended) — one prompt for 100+ segments, no per-file OK clicks.', 'the-exporter' );
		}
		?>
	</p>
	<div id="te-packages-status" class="te-status-panel te-status-panel--info" hidden></div>
	<div id="te-download-progress" class="te-download-progress" hidden>
		<progress id="te-download-progress-bar" class="te-download-progress__bar" max="100" value="0"></progress>
		<span id="te-download-progress-label" class="te-download-progress__label"></span>
	</div>
	<div class="te-actions te-mt-md te-download-actions">
		<?php UI::button( __( 'Save all to folder…', 'the-exporter' ), 'primary', array( 'id' => 'te-save-to-folder', 'disabled' => 'disabled' ) ); ?>
		<?php UI::button( __( 'Download ZIP', 'the-exporter' ), 'secondary', array( 'id' => 'te-download-zip', 'disabled' => 'disabled', 'title' => __( 'Single ZIP when total size is under 1.5 GB', 'the-exporter' ) ) ); ?>
		<?php UI::button( __( 'Download manifest.json', 'the-exporter' ), 'secondary', array( 'id' => 'te-download-manifest', 'disabled' => 'disabled' ) ); ?>
		<?php UI::button( __( 'Download each file (legacy)', 'the-exporter' ), 'ghost', array( 'id' => 'te-download-all', 'disabled' => 'disabled', 'title' => __( 'Triggers one browser save dialog per segment', 'the-exporter' ) ) ); ?>
	</div>
	<div class="te-export-path te-mt-md" hidden>
		<p class="te-text-muted te-mb-sm"><?php esc_html_e( 'Or copy from server folder:', 'the-exporter' ); ?></p>
		<code class="te-mono" id="te-export-path-hint-dl"></code>
	</div>
	<div id="te-component-download-list" class="te-component-accordion te-mt-md">
		<p class="te-text-muted"><?php esc_html_e( 'Run Export All first.', 'the-exporter' ); ?></p>
	</div>
	<?php
	UI::glass_card( __( 'Download for Import', 'the-exporter' ), ob_get_clean() );
	?>
</div>
<?php endif; ?>
