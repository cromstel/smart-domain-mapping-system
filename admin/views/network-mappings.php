<?php
/**
 * Network Admin screen: add / manage domain mappings.
 *
 * The form posts to network edit.php (no-JS fallback with the dm_mapping
 * nonce); admin.js intercepts the submit for the AJAX path.
 *
 * @package Domain_Mapping_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dm_render_mappings_page() {
	if ( ! current_user_can( 'manage_network' ) ) {
		wp_die( esc_html__( 'Permission denied.', 'domain-mapping-system' ) );
	}
	$results = DMS_Mapping_Engine::list_mappings();
	if ( is_wp_error( $results ) ) {
		$results = array();
	}
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

		<h2><?php esc_html_e( 'Add New Mapping', 'domain-mapping-system' ); ?></h2>
		<form id="dm-add-form" method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=dm_add_mapping' ) ); ?>">
			<?php wp_nonce_field( 'dm_mapping', 'dm_nonce' ); ?>
			<table class="form-table">
				<tr>
					<th><label for="dm-blog-id"><?php esc_html_e( 'Site (Blog ID)', 'domain-mapping-system' ); ?></label></th>
					<td><input type="number" id="dm-blog-id" name="blog_id" required class="small-text" /></td>
				</tr>
				<tr>
					<th><label for="dm-domain"><?php esc_html_e( 'Domain', 'domain-mapping-system' ); ?></label></th>
					<td><input type="text" id="dm-domain" name="domain" required placeholder="example.com" /></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Make Primary', 'domain-mapping-system' ); ?></th>
					<td><label><input type="checkbox" id="dm-make-primary" name="make_primary" value="1" /> <?php esc_html_e( 'Set as primary domain (syncs wp_blogs.domain)', 'domain-mapping-system' ); ?></label></td>
				</tr>
			</table>
			<p><span id="dm-add-status" role="status" aria-live="polite"></span></p>
			<?php submit_button( esc_html__( 'Add Mapping', 'domain-mapping-system' ) ); ?>
		</form>

		<h2><?php esc_html_e( 'Current Mappings', 'domain-mapping-system' ); ?></h2>
		<table class="widefat fixed" id="dm-mappings-table">
			<caption class="screen-reader-text"><?php esc_html_e( 'Current domain mappings', 'domain-mapping-system' ); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'ID', 'domain-mapping-system' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Domain', 'domain-mapping-system' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Blog ID', 'domain-mapping-system' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'domain-mapping-system' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Primary', 'domain-mapping-system' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'domain-mapping-system' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php
			if ( ! empty( $results ) ) :
				foreach ( $results as $r ) :
					?>
				<tr>
					<td><?php echo absint( $r['id'] ); ?></td>
					<td><code><?php echo esc_html( $r['domain'] ); ?></code></td>
					<td><?php echo absint( $r['blog_id'] ); ?></td>
					<td>
										<?php if ( ! empty( $r['active'] ) ) : ?>
							<span style="color:#00a32a;"><?php esc_html_e( 'Active', 'domain-mapping-system' ); ?></span>
						<?php else : ?>
							<span style="color:#d63638;"><?php esc_html_e( 'Inactive', 'domain-mapping-system' ); ?></span>
						<?php endif; ?>
						|
										<?php if ( ! empty( $r['verified'] ) ) : ?>
							<span style="color:#00a32a;"><?php esc_html_e( 'Verified', 'domain-mapping-system' ); ?></span>
						<?php else : ?>
							<span><?php esc_html_e( 'Unverified', 'domain-mapping-system' ); ?></span>
						<?php endif; ?>
					</td>
					<td>
										<?php if ( ! empty( $r['primary'] ) ) : ?>
							<strong><?php esc_html_e( 'Yes', 'domain-mapping-system' ); ?></strong>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
					<td>
										<?php if ( empty( $r['primary'] ) ) : ?>
							<button type="button" class="button dm-set-primary" data-id="<?php echo absint( $r['id'] ); ?>">
											<?php esc_html_e( 'Set Primary', 'domain-mapping-system' ); ?>
							</button>
						<?php endif; ?>
						<button type="button" class="button dm-verify-mapping" data-id="<?php echo absint( $r['id'] ); ?>">
											<?php esc_html_e( 'Verify', 'domain-mapping-system' ); ?>
						</button>
						<button type="button" class="button dm-toggle-mapping" data-id="<?php echo absint( $r['id'] ); ?>" data-active="<?php echo ! empty( $r['active'] ) ? '0' : '1'; ?>">
											<?php echo ! empty( $r['active'] ) ? esc_html__( 'Disable', 'domain-mapping-system' ) : esc_html__( 'Enable', 'domain-mapping-system' ); ?>
						</button>
						<button type="button" class="button dm-delete-mapping" data-id="<?php echo absint( $r['id'] ); ?>">
											<?php esc_html_e( 'Delete', 'domain-mapping-system' ); ?>
						</button>
					</td>
				</tr>
							<?php endforeach; else : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No mappings found.', 'domain-mapping-system' ); ?></td></tr>
			<?php endif; ?>
			</tbody>
		</table>
		<p><span id="dm-table-status" role="status" aria-live="polite"></span></p>
	</div>
	<?php
}
