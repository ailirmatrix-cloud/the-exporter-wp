<?php
/**
 * Dashboard view.
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

use TheExporter\Admin\Components\UI;
use TheExporter\Jobs\ExportOrchestrator;
use TheExporter\Settings;

$migration_id = Settings::get( 'active_migration_id' );
$components   = ExportOrchestrator::components();
?>
<div class="te-grid te-grid--2">
	<?php
	ob_start();
	?>
	<p class="te-text-muted"><?php esc_html_e( 'Verification-first migration for very large WordPress sites. Export chunked packages, verify locally, upload via SFTP, then import in a controlled order.', 'the-exporter' ); ?></p>
	<div class="te-workflow-rail">
		<span class="te-workflow-step"><?php esc_html_e( 'Export', 'the-exporter' ); ?></span>
		<span class="te-workflow-arrow">→</span>
		<span class="te-workflow-step"><?php esc_html_e( 'Download', 'the-exporter' ); ?></span>
		<span class="te-workflow-arrow">→</span>
		<span class="te-workflow-step"><?php esc_html_e( 'Verify', 'the-exporter' ); ?></span>
		<span class="te-workflow-arrow">→</span>
		<span class="te-workflow-step"><?php esc_html_e( 'Upload', 'the-exporter' ); ?></span>
		<span class="te-workflow-arrow">→</span>
		<span class="te-workflow-step"><?php esc_html_e( 'Import', 'the-exporter' ); ?></span>
	</div>
	<?php if ( ! $migration_id ) : ?>
		<div class="te-actions">
			<?php UI::button( __( 'Start New Export', 'the-exporter' ), 'primary', array( 'id' => 'te-init-export' ) ); ?>
		</div>
	<?php else : ?>
		<p><strong><?php esc_html_e( 'Active migration:', 'the-exporter' ); ?></strong> <code class="te-mono"><?php echo esc_html( $migration_id ); ?></code></p>
	<?php endif; ?>
	<?php
	UI::glass_card( __( 'Migration Overview', 'the-exporter' ), ob_get_clean() );
	?>
</div>

<div class="te-grid te-grid--4 te-mt-lg">
	<?php foreach ( $components as $component ) : ?>
		<?php
		ob_start();
		UI::status_badge( 'pending', __( 'Not started', 'the-exporter' ) );
		?>
		<p class="te-component-name"><?php echo esc_html( ucwords( str_replace( '-', ' ', $component ) ) ); ?></p>
		<?php
		UI::glass_card( '', ob_get_clean(), 'te-component-card' );
		?>
	<?php endforeach; ?>
</div>

<div class="te-grid te-grid--3 te-mt-lg" id="te-dashboard-stats">
	<?php
	UI::stat_tile( '—', __( 'Export path', 'the-exporter' ) );
	UI::stat_tile( '—', __( 'Free disk', 'the-exporter' ) );
	UI::stat_tile( '—', __( 'WP-CLI', 'the-exporter' ) );
	?>
</div>
