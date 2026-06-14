<?php
/**
 * Jobs & Logs view.
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

use TheExporter\Admin\Components\UI;
use TheExporter\Jobs\JobRepository;
use TheExporter\Logging\AuditLogger;
use TheExporter\Restore\RestorePointManager;

$jobs   = JobRepository::list_jobs( 20 );
$logs   = AuditLogger::get_logs( array( 'limit' => 50 ) );
$points = RestorePointManager::list_points();
?>
<div class="te-split-view">
	<div class="te-split-view__left">
		<?php
		ob_start();
		if ( empty( $jobs ) ) {
			echo '<p class="te-text-muted">' . esc_html__( 'No jobs yet.', 'the-exporter' ) . '</p>';
		} else {
			echo '<table class="te-table"><thead><tr><th>ID</th><th>Type</th><th>Status</th><th>Migration</th></tr></thead><tbody>';
			foreach ( $jobs as $job ) {
				printf(
					'<tr><td>%d</td><td>%s</td><td>%s</td><td><code class="te-mono">%s</code></td></tr>',
					(int) $job['id'],
					esc_html( $job['type'] ),
					esc_html( $job['status'] ),
					esc_html( $job['migration_id'] )
				);
			}
			echo '</tbody></table>';
		}
		UI::glass_card( __( 'Recent Jobs', 'the-exporter' ), ob_get_clean() );

	ob_start();
	?>
	<p class="te-text-muted"><?php esc_html_e( 'If a migration is stuck on “locked”, release it here after confirming no job is running.', 'the-exporter' ); ?></p>
	<?php UI::button( __( 'Release migration lock', 'the-exporter' ), 'secondary', array( 'id' => 'te-release-lock', 'class' => 'te-mt-md' ) ); ?>
	<?php
	UI::glass_card( __( 'Lock Recovery', 'the-exporter' ), ob_get_clean() );
	?>
	</div>
	<div class="te-split-view__right">
		<?php
		ob_start();
		?>
		<div class="te-audit-toolbar te-mb-sm">
			<button type="button" class="te-btn te-btn--ghost te-btn--sm" id="te-audit-copy-all"><?php esc_html_e( 'Copy all audit lines', 'the-exporter' ); ?></button>
		</div>
		<div class="te-log-viewer" id="te-audit-log">
		<?php
		if ( empty( $logs ) ) {
			echo '<p class="te-text-muted">' . esc_html__( 'No audit log entries.', 'the-exporter' ) . '</p>';
		} else {
			foreach ( $logs as $log ) {
				$result = isset( $log['result'] ) ? $log['result'] : 'info';
				$class  = 'te-log-line te-log-line--' . esc_attr( $result );
				$context = ! empty( $log['context'] ) ? $log['context'] : null;
				echo '<div class="' . esc_attr( $class ) . '">';
				printf(
					'<span class="te-log-ts">%s</span> <span class="te-log-pill te-log-pill--%s">%s</span> <span class="te-log-action">%s</span> %s',
					esc_html( $log['created_at'] ),
					esc_attr( $result ),
					esc_html( ucfirst( $result ) ),
					esc_html( $log['action'] ),
					esc_html( $log['message'] )
				);
				if ( $context ) {
					echo '<details class="te-log-context"><summary>' . esc_html__( 'Context', 'the-exporter' ) . '</summary>';
					echo '<pre class="te-mono te-log-context__body">' . esc_html( wp_json_encode( $context, JSON_PRETTY_PRINT ) ) . '</pre></details>';
				}
				echo '</div>';
			}
		}
		echo '</div>';
		?>
		<script>
		( function () {
			const btn = document.getElementById( 'te-audit-copy-all' );
			const log = document.getElementById( 'te-audit-log' );
			if ( ! btn || ! log ) return;
			btn.addEventListener( 'click', async () => {
				const lines = Array.from( log.querySelectorAll( '.te-log-line' ) ).map( ( el ) => el.innerText.trim() );
				const text = lines.join( '\n' );
				try {
					await navigator.clipboard.writeText( text );
				} catch ( e ) {
					const ta = document.createElement( 'textarea' );
					ta.value = text;
					document.body.appendChild( ta );
					ta.select();
					document.execCommand( 'copy' );
					document.body.removeChild( ta );
				}
			} );
		} )();
		</script>
		<?php
		UI::glass_card( __( 'Audit Log', 'the-exporter' ), ob_get_clean() );
		?>
	</div>
</div>

<div class="te-mt-lg">
	<?php
	ob_start();
	if ( empty( $points ) ) {
		echo '<p class="te-text-muted">' . esc_html__( 'No restore points created yet.', 'the-exporter' ) . '</p>';
	} else {
		foreach ( $points as $point ) {
			$component = isset( $point['manifest']['component'] ) ? $point['manifest']['component'] : '';
			printf(
				'<div class="te-restore-point"><code class="te-mono">%s</code> — %s</div>',
				esc_html( $point['id'] ),
				esc_html( $component )
			);
		}
	}
	UI::glass_card( __( 'Restore Points', 'the-exporter' ), ob_get_clean() );
	?>
</div>
