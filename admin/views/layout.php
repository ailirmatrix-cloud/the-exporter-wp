<?php
/**
 * Admin layout shell.
 *
 * @package TheExporter
 *
 * @var string $title       Page title.
 * @var string $content     Page content.
 * @var string $active_page Active nav slug.
 */

defined( 'ABSPATH' ) || exit;

use TheExporter\Settings;

$migration_id = Settings::get( 'active_migration_id' );
$nav_items    = array(
	'migrate'  => array( 'label' => __( 'Migrate', 'the-exporter' ), 'url' => admin_url( 'admin.php?page=the-exporter-migrate' ) ),
	'activity' => array( 'label' => __( 'Activity', 'the-exporter' ), 'url' => admin_url( 'admin.php?page=the-exporter-activity' ) ),
	'advanced' => array( 'label' => __( 'Advanced', 'the-exporter' ), 'url' => admin_url( 'admin.php?page=the-exporter-advanced' ) ),
);
$layout_class = ! empty( $te_wizard_layout ) ? ' te-layout--wizard' : '';
?>
<div class="te-app" id="te-app">
	<header class="te-topbar te-glass-panel">
		<div class="te-topbar__brand">
			<svg class="te-topbar__logo" width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M12 3L3 9v12h6v-7h6v7h6V9L12 3z" stroke="currentColor" stroke-width="1.5"/></svg>
			<span class="te-topbar__title"><?php esc_html_e( 'The Exporter', 'the-exporter' ); ?></span>
		</div>
		<div class="te-topbar__meta">
			<?php if ( $migration_id ) : ?>
				<span class="te-chip"><?php esc_html_e( 'Migration', 'the-exporter' ); ?>: <code><?php echo esc_html( $migration_id ); ?></code></span>
			<?php endif; ?>
			<span class="te-chip" id="te-env-wp">WP <?php echo esc_html( get_bloginfo( 'version' ) ); ?></span>
			<span class="te-chip" id="te-env-php">PHP <?php echo esc_html( PHP_VERSION ); ?></span>
		</div>
	</header>

	<div class="te-layout<?php echo esc_attr( $layout_class ); ?>">
		<nav class="te-sidebar te-glass-panel" aria-label="<?php esc_attr_e( 'The Exporter navigation', 'the-exporter' ); ?>">
			<ul class="te-sidebar__nav">
				<?php foreach ( $nav_items as $slug => $item ) : ?>
					<li>
						<a href="<?php echo esc_url( $item['url'] ); ?>"
							class="te-sidebar__link<?php echo $active_page === $slug ? ' te-sidebar__link--active' : ''; ?>">
							<?php echo esc_html( $item['label'] ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>

		<main class="te-main">
			<div class="te-page-header">
				<h1 class="te-page-title"><?php echo esc_html( $title ); ?></h1>
			</div>
			<div class="te-content">
				<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>

			<aside class="te-activity te-glass-card" id="te-activity-panel" aria-label="<?php esc_attr_e( 'Activity log', 'the-exporter' ); ?>">
				<div class="te-activity__header">
					<div class="te-activity__title-row">
						<h2 class="te-activity__title"><?php esc_html_e( 'Activity Log', 'the-exporter' ); ?></h2>
						<span class="te-text-muted te-activity__hint"><?php esc_html_e( 'Every background action is recorded here.', 'the-exporter' ); ?></span>
					</div>
					<div class="te-activity__toolbar" role="toolbar" aria-label="<?php esc_attr_e( 'Activity log actions', 'the-exporter' ); ?>">
						<label class="te-activity__filter-label">
							<span class="screen-reader-text"><?php esc_html_e( 'Filter log', 'the-exporter' ); ?></span>
							<select id="te-activity-filter" class="te-activity__filter">
								<option value="all"><?php esc_html_e( 'All', 'the-exporter' ); ?></option>
								<option value="errors"><?php esc_html_e( 'Errors', 'the-exporter' ); ?></option>
								<option value="warnings"><?php esc_html_e( 'Warnings', 'the-exporter' ); ?></option>
								<option value="export"><?php esc_html_e( 'Export only', 'the-exporter' ); ?></option>
							</select>
						</label>
						<button type="button" class="te-btn te-btn--ghost te-btn--sm" id="te-activity-copy-all"><?php esc_html_e( 'Copy all', 'the-exporter' ); ?></button>
						<button type="button" class="te-btn te-btn--ghost te-btn--sm" id="te-activity-copy-errors"><?php esc_html_e( 'Copy errors', 'the-exporter' ); ?></button>
						<button type="button" class="te-btn te-btn--ghost te-btn--sm" id="te-activity-copy-last"><?php esc_html_e( 'Copy last', 'the-exporter' ); ?></button>
						<button type="button" class="te-btn te-btn--ghost te-btn--sm" id="te-activity-clear"><?php esc_html_e( 'Clear', 'the-exporter' ); ?></button>
					</div>
				</div>
				<div class="te-activity-log" id="te-activity-log">
					<p class="te-activity-log__empty"><?php esc_html_e( 'No activity yet. Export actions appear here in real time.', 'the-exporter' ); ?></p>
				</div>
			</aside>
		</main>
	</div>

	<footer class="te-footer te-glass-panel">
		<span id="te-footer-disk"><?php esc_html_e( 'Checking disk space…', 'the-exporter' ); ?></span>
		<span id="te-footer-cli"><?php esc_html_e( 'Checking CLI tools…', 'the-exporter' ); ?></span>
	</footer>

	<div class="te-live-region" aria-live="polite" id="te-live-region"></div>
</div>
<?php
\TheExporter\Admin\Components\UI::confirm_modal();
