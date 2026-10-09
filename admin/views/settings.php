<?php
/**
 * Network Admin settings screen (rendered by DMS_Settings::render_settings).
 *
 * @package Domain_Mapping_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dm_render_settings_page() {
	if ( ! current_user_can( 'manage_network' ) ) {
		wp_die( esc_html__( 'Permission denied.', 'domain-mapping-system' ) );
	}
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=dm_settings' ) ); ?>">
			<?php settings_fields( 'dm_group' ); ?>
			<?php do_settings_sections( 'dm_settings_page' ); ?>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}
