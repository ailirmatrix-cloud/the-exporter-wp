<?php
/**
 * Reusable glass UI components.
 *
 * @package TheExporter
 */

namespace TheExporter\Admin\Components;

defined( 'ABSPATH' ) || exit;

/**
 * Class UI
 */
class UI {

	/**
	 * Render glass card.
	 *
	 * @param string $title   Card title.
	 * @param string $content Inner HTML.
	 * @param string $class   Extra class.
	 */
	public static function glass_card( $title, $content, $class = '' ) {
		printf(
			'<div class="te-glass-card %s"><div class="te-glass-card__header"><h3 class="te-glass-card__title">%s</h3></div><div class="te-glass-card__body">%s</div></div>',
			esc_attr( $class ),
			esc_html( $title ),
			$content // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	/**
	 * Render status badge.
	 *
	 * @param string $status Status key.
	 * @param string $label  Label.
	 */
	public static function status_badge( $status, $label = '' ) {
		$label = $label ?: ucfirst( $status );
		printf(
			'<span class="te-badge te-badge--%s"><span class="te-badge__dot"></span>%s</span>',
			esc_attr( $status ),
			esc_html( $label )
		);
	}

	/**
	 * Render button.
	 *
	 * @param string $text    Button text.
	 * @param string $type    primary|secondary|ghost|danger.
	 * @param array  $attrs   HTML attributes.
	 */
	public static function button( $text, $type = 'primary', array $attrs = array() ) {
		$extra_class = '';
		if ( isset( $attrs['class'] ) ) {
			$extra_class = ' ' . trim( (string) $attrs['class'] );
			unset( $attrs['class'] );
		}

		$attr_str = '';
		foreach ( $attrs as $k => $v ) {
			$attr_str .= sprintf( ' %s="%s"', esc_attr( $k ), esc_attr( $v ) );
		}
		printf(
			'<button type="button" class="te-btn te-btn--%s%s"%s>%s</button>',
			esc_attr( $type ),
			esc_attr( $extra_class ),
			$attr_str, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html( $text )
		);
	}

	/**
	 * Render stat tile.
	 *
	 * @param string $value Stat value.
	 * @param string $label Stat label.
	 */
	public static function stat_tile( $value, $label ) {
		printf(
			'<div class="te-stat-tile"><div class="te-stat-tile__value">%s</div><div class="te-stat-tile__label">%s</div></div>',
			esc_html( $value ),
			esc_html( $label )
		);
	}

	/**
	 * Render wizard steps.
	 *
	 * @param array $steps       Step labels.
	 * @param int   $current     Current step (1-based).
	 */
	public static function wizard_steps( array $steps, $current = 1, $id = '' ) {
		$id_attr = $id ? ' id="' . esc_attr( $id ) . '"' : '';
		printf( '<div%s class="te-wizard">', $id_attr ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		foreach ( $steps as $i => $step ) {
			$num    = $i + 1;
			$active = $num === $current ? ' te-wizard__step--active' : ( $num < $current ? ' te-wizard__step--done' : '' );
			printf(
				'<div class="te-wizard__step%s"><span class="te-wizard__num">%d</span><span class="te-wizard__label">%s</span></div>',
				esc_attr( $active ),
				$num,
				esc_html( $step )
			);
		}
		echo '</div>';
	}

	/**
	 * Render progress ring SVG.
	 *
	 * @param int $percent Percentage 0-100.
	 */
	public static function progress_ring( $percent ) {
		$percent = max( 0, min( 100, (int) $percent ) );
		$dash    = ( $percent / 100 ) * 283;
		printf(
			'<svg class="te-progress-ring" viewBox="0 0 100 100"><circle class="te-progress-ring__bg" cx="50" cy="50" r="45"/><circle class="te-progress-ring__fill" cx="50" cy="50" r="45" style="stroke-dasharray:%s 283"/><text x="50" y="54" class="te-progress-ring__text">%d%%</text></svg>',
			esc_attr( $dash ),
			$percent
		);
	}

	/**
	 * Render confirm modal markup.
	 */
	public static function confirm_modal() {
		?>
		<div class="te-modal" id="te-confirm-modal" hidden>
			<div class="te-modal__overlay"></div>
			<div class="te-modal__dialog te-glass-card">
				<h3 class="te-modal__title"><?php esc_html_e( 'Confirm Action', 'the-exporter' ); ?></h3>
				<p class="te-modal__message" id="te-modal-message"></p>
				<input type="text" class="te-input" id="te-modal-confirm-input" placeholder="<?php esc_attr_e( 'Type CONFIRM to proceed', 'the-exporter' ); ?>" />
				<div class="te-modal__actions">
					<button type="button" class="te-btn te-btn--ghost" id="te-modal-cancel"><?php esc_html_e( 'Cancel', 'the-exporter' ); ?></button>
					<button type="button" class="te-btn te-btn--danger" id="te-modal-confirm" disabled><?php esc_html_e( 'Proceed', 'the-exporter' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}
}
