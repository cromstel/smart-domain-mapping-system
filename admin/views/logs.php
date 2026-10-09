<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dm_render_logs_page() {
	if ( ! current_user_can( 'manage_network' ) ) {
		wp_die( esc_html__( 'Permission denied.', 'domain-mapping-system' ) );
	}
	global $wpdb;
	$table = $wpdb->base_prefix . DMS_TABLE_LOGS;
	$rows  = $wpdb->get_results(
		$wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d", 100 )
	);
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<table class="widefat fixed">
			<caption class="screen-reader-text"><?php echo esc_html__( 'Audit log entries', 'domain-mapping-system' ); ?></caption>
			<thead><tr><th scope="col"><?php echo esc_html__( 'Blog', 'domain-mapping-system' ); ?></th><th scope="col"><?php echo esc_html__( 'Domain', 'domain-mapping-system' ); ?></th><th scope="col"><?php echo esc_html__( 'User', 'domain-mapping-system' ); ?></th><th scope="col"><?php echo esc_html__( 'Action', 'domain-mapping-system' ); ?></th><th scope="col"><?php echo esc_html__( 'Details', 'domain-mapping-system' ); ?></th><th scope="col"><?php echo esc_html__( 'Time', 'domain-mapping-system' ); ?></th></tr></thead>
			<tbody>
			<?php
			if ( ! empty( $rows ) ) :
				foreach ( $rows as $r ) :
					?>
			<tr>
				<td><?php echo absint( $r->blog_id ); ?></td>
				<td><?php echo esc_html( $r->domain ); ?></td>
				<td><?php echo absint( $r->user_id ); ?></td>
				<td><?php echo esc_html( $r->action ); ?></td>
				<td><?php echo esc_html( $r->context ); ?></td>
				<td><?php echo esc_html( $r->created_at ); ?></td>
			</tr>
							<?php endforeach; else : ?>
			<tr><td colspan="6"><?php esc_html_e( 'No log entries found.', 'domain-mapping-system' ); ?></td></tr>
			<?php endif; ?>
			</tbody>
		</table>
	</div>
	<?php
}
