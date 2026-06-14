<?php
/**
 * Validation view — per-component validation.
 *
 * @package TheExporter
 */

defined( 'ABSPATH' ) || exit;

use TheExporter\Admin\Components\UI;
use TheExporter\Transfer\PackageIndex;
use TheExporter\Settings;

$migration_id = Settings::get( 'active_migration_id' );
$components   = PackageIndex::component_order();
?>
<div class="te-grid te-grid--1">
	<?php
	ob_start();
	?>
	<label class="te-label" for="te-validate-migration-id"><?php esc_html_e( 'Migration ID', 'the-exporter' ); ?></label>
	<input type="text" class="te-input" id="te-validate-migration-id" value="<?php echo esc_attr( $migration_id ); ?>" />
	<div class="te-actions te-mt-md">
		<?php UI::button( __( 'Validate All', 'the-exporter' ), 'primary', array( 'id' => 'te-run-validation' ) ); ?>
		<?php UI::button( __( 'Download Report (JSON)', 'the-exporter' ), 'secondary', array( 'id' => 'te-download-validation' ) ); ?>
	</div>
	<?php
	UI::glass_card( __( 'Full Migration Validation', 'the-exporter' ), ob_get_clean() );
	?>
</div>

<div class="te-mt-lg" id="te-validation-by-component" data-migration-id="<?php echo esc_attr( $migration_id ); ?>">
	<?php
	ob_start();
	?>
	<p class="te-text-muted"><?php esc_html_e( 'Validate one subject at a time after uploading its package.', 'the-exporter' ); ?></p>
	<div class="te-component-accordion">
		<?php foreach ( $components as $component ) : ?>
			<div class="te-component-panel te-glass-card" data-component="<?php echo esc_attr( $component ); ?>">
				<div class="te-component-panel__header">
					<h3 class="te-component-panel__title"><?php echo esc_html( PackageIndex::component_label( $component ) ); ?></h3>
					<?php UI::button( __( 'Validate', 'the-exporter' ), 'ghost', array( 'class' => 'te-validate-component-page' ) ); ?>
				</div>
				<div class="te-component-panel__report"></div>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
	UI::glass_card( __( 'Validate by Subject', 'the-exporter' ), ob_get_clean() );
	?>
</div>

<div class="te-mt-lg" id="te-validation-report">
	<?php
	ob_start();
	?>
	<p class="te-text-muted"><?php esc_html_e( 'Run full validation to see all components at once.', 'the-exporter' ); ?></p>
	<?php
	UI::glass_card( __( 'Validation Report', 'the-exporter' ), ob_get_clean() );
	?>
</div>
