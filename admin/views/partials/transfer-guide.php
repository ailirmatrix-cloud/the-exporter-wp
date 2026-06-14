<?php
/**
 * Beginner transfer guide (folder / FileZilla / cPanel).
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

use TheExporter\Settings;

$context      = isset( $context ) ? $context : 'export';
$migration_id = isset( $migration_id ) ? $migration_id : Settings::get( 'active_migration_id' );
$import_base  = Settings::get( 'import_base_path' );
$export_path  = $migration_id ? Settings::migration_path( $migration_id, 'export' ) : Settings::get( 'export_base_path' );
$import_path  = $migration_id ? Settings::migration_path( $migration_id, 'import' ) : $import_base;
$folder_name  = $migration_id ? 'migration-' . sanitize_file_name( $migration_id ) : 'migration-{id}';
?>
<details class="te-transfer-guide te-mt-md" id="te-transfer-guide" open>
	<summary class="te-transfer-guide__summary"><?php esc_html_e( 'How to transfer files (step-by-step)', 'the-exporter' ); ?></summary>
	<div class="te-transfer-guide__body">
		<?php if ( 'export' === $context ) : ?>
			<ol class="te-transfer-guide__steps">
				<li><?php esc_html_e( 'Copy the Migration ID to your import site.', 'the-exporter' ); ?></li>
				<li><?php esc_html_e( 'When export finishes, copy the entire export folder to your import host (methods below).', 'the-exporter' ); ?></li>
				<li><?php esc_html_e( 'On the import site: Scan import folder → Validate → Import via server.', 'the-exporter' ); ?></li>
			</ol>
			<p class="te-text-muted te-mb-sm"><?php esc_html_e( 'Export folder on this server:', 'the-exporter' ); ?></p>
			<code class="te-mono te-transfer-guide__path"><?php echo esc_html( $export_path ); ?></code>
		<?php else : ?>
			<ol class="te-transfer-guide__steps">
				<li><?php esc_html_e( 'Paste the Migration ID from your export site.', 'the-exporter' ); ?></li>
				<li><?php esc_html_e( 'Copy all package files into the import folder below (same folder name as export).', 'the-exporter' ); ?></li>
				<li><?php esc_html_e( 'Click Scan import folder, then Validate, then Import via server.', 'the-exporter' ); ?></li>
			</ol>
			<p class="te-text-muted te-mb-sm"><?php esc_html_e( 'Import folder on this server:', 'the-exporter' ); ?></p>
			<code class="te-mono te-transfer-guide__path" id="te-import-folder-path"><?php echo esc_html( $import_path ); ?></code>
		<?php endif; ?>

		<div class="te-transfer-guide__tabs te-mt-md" role="tablist">
			<button type="button" class="te-transfer-guide__tab te-transfer-guide__tab--active" data-tab="filezilla"><?php esc_html_e( 'FileZilla (recommended for large sites)', 'the-exporter' ); ?></button>
			<button type="button" class="te-transfer-guide__tab" data-tab="cpanel"><?php esc_html_e( 'cPanel File Manager (small tests)', 'the-exporter' ); ?></button>
			<button type="button" class="te-transfer-guide__tab" data-tab="advanced"><?php esc_html_e( 'Advanced (rsync)', 'the-exporter' ); ?></button>
		</div>

		<div class="te-transfer-guide__panel te-transfer-guide__panel--active" data-panel="filezilla">
			<ol class="te-transfer-guide__steps">
				<li><?php printf( esc_html__( 'Install %s (free).', 'the-exporter' ), '<a href="https://filezilla-project.org/" target="_blank" rel="noopener">FileZilla Client</a>' ); ?></li>
				<li><?php esc_html_e( 'Get SFTP credentials from your host (cPanel → FTP Accounts, or host docs): host, username, password, port 22.', 'the-exporter' ); ?></li>
				<?php if ( 'export' === $context ) : ?>
					<li><?php esc_html_e( 'Connect to your export host → open the export folder above → download the migration folder to your PC (or drag directly to import host).', 'the-exporter' ); ?></li>
					<li><?php printf( esc_html__( 'Connect to your import host → open %s → upload the folder %s.', 'the-exporter' ), '<code class="te-mono">' . esc_html( $import_base ) . '</code>', '<code class="te-mono">' . esc_html( $folder_name ) . '</code>' ); ?></li>
				<?php else : ?>
					<li><?php printf( esc_html__( 'Connect to your export host → download folder %s from the export path.', 'the-exporter' ), '<code class="te-mono">' . esc_html( $folder_name ) . '</code>' ); ?></li>
					<li><?php printf( esc_html__( 'Connect to this import host → upload into %s so files match the path above.', 'the-exporter' ), '<code class="te-mono">' . esc_html( $import_path ) . '</code>' ); ?></li>
				<?php endif; ?>
				<li><?php esc_html_e( 'Back in WordPress admin → Scan import folder.', 'the-exporter' ); ?></li>
			</ol>
		</div>

		<div class="te-transfer-guide__panel" data-panel="cpanel" hidden>
			<ol class="te-transfer-guide__steps">
				<li><?php esc_html_e( 'cPanel → File Manager on export host → open export folder → Compress → Download.', 'the-exporter' ); ?></li>
				<li><?php esc_html_e( 'On import host cPanel → upload zip into migration-imports → Extract.', 'the-exporter' ); ?></li>
				<li><?php esc_html_e( 'Not recommended for 50GB+ (slow and may hit limits). Use FileZilla or Connected site mode instead.', 'the-exporter' ); ?></li>
			</ol>
		</div>

		<div class="te-transfer-guide__panel" data-panel="advanced" hidden>
			<p class="te-text-muted"><?php esc_html_e( 'If you have SSH access on both hosts:', 'the-exporter' ); ?></p>
			<code class="te-mono te-transfer-guide__path">rsync -avz --progress <?php echo esc_html( trailingslashit( $export_path ) ); ?> user@import-host:<?php echo esc_html( trailingslashit( $import_path ) ); ?></code>
		</div>
	</div>
</details>
