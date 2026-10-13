<?php
/**
 * Single-site "Domain Mappings" admin view (Phase 1 skeleton).
 *
 * Rendered by DMS_Single_Site_Admin::render_page(). All output is escaped;
 * every form post is capability-checked and nonce-guarded in the controller.
 *
 * @package Domain_Mapping_System
 * @var string $host Current host value (unused placeholder for Phase 2 tabs).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Read-only cosmetic param (renders a notice after a redirect); the redirect
// itself is a POST handler guarded by check_admin_referer() + capability.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$dm_msg_key  = isset( $_GET['dm_msg'] ) ? sanitize_key( (string) $_GET['dm_msg'] ) : '';
$dm_messages = array(
	'added'   => __( 'Domain mapping added.', 'domain-mapping-system' ),
	'deleted' => __( 'Domain mapping removed.', 'domain-mapping-system' ),
	'error'   => __( 'The mapping could not be saved. Check host/path and try again.', 'domain-mapping-system' ),
);
$dm_list     = DMS_Single_Site_Mappings::list_mappings();
if ( is_wp_error( $dm_list ) ) {
	$dm_list = array();
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Domain Mappings', 'domain-mapping-system' ); ?></h1>

	<?php if ( isset( $dm_messages[ $dm_msg_key ] ) ) : ?>
		<div class="notice notice-<?php echo 'error' === $dm_msg_key ? 'error' : 'success'; ?> is-dismissible">
			<p><?php echo esc_html( $dm_messages[ $dm_msg_key ] ); ?></p>
		</div>
	<?php endif; ?>

	<h2 class="title"><?php esc_html_e( 'Add a mapping', 'domain-mapping-system' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dm-add-form">
		<input type="hidden" name="action" value="dm_add_single_mapping" />
		<?php wp_nonce_field( 'dm_add_single_mapping' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="dm-host"><?php esc_html_e( 'Host', 'domain-mapping-system' ); ?></label>
				</th>
				<td>
					<input type="text" id="dm-host" name="host" required
						placeholder="example.com" class="regular-text" />
					<p class="description">
						<?php esc_html_e( 'The domain to map. Lowercase, no protocol, no path.', 'domain-mapping-system' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="dm-path"><?php esc_html_e( 'Path (optional)', 'domain-mapping-system' ); ?></label>
				</th>
				<td>
					<input type="text" id="dm-path" name="path" placeholder="solutions"
						class="regular-text" />
					<p class="description">
						<?php esc_html_e( 'Optional subdirectory, e.g. "solutions" maps example.com/solutions.', 'domain-mapping-system' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Active', 'domain-mapping-system' ); ?></th>
				<td>
					<label for="dm-active">
						<input type="checkbox" id="dm-active" name="active" value="1" checked />
						<?php esc_html_e( 'Enable this mapping immediately', 'domain-mapping-system' ); ?>
					</label>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Add mapping', 'domain-mapping-system' ), 'primary', 'submit', false ); ?>
	</form>

	<h2 class="title"><?php esc_html_e( 'Mapped domains', 'domain-mapping-system' ); ?></h2>
	<?php if ( empty( $dm_list ) ) : ?>
		<p><?php esc_html_e( 'No mappings yet.', 'domain-mapping-system' ); ?></p>
	<?php else : ?>
		<table class="widefat striped" aria-describedby="dm-mapping-list-desc">
			<caption id="dm-mapping-list-desc" class="screen-reader-text">
				<?php esc_html_e( 'Mapped domains and their state.', 'domain-mapping-system' ); ?>
			</caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Host', 'domain-mapping-system' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Path', 'domain-mapping-system' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'domain-mapping-system' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'domain-mapping-system' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $dm_list as $dm_mapping ) : ?>
					<tr>
						<td>
							<strong><?php echo esc_html( $dm_mapping['host'] ); ?></strong>
							<?php if ( $dm_mapping['primary'] ) : ?>
								<span class="dashicons dashicons-star-filled" aria-hidden="true"></span>
								<span class="screen-reader-text"><?php esc_html_e( 'Primary', 'domain-mapping-system' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $dm_mapping['path'] ? '/' . $dm_mapping['path'] : '/' ); ?></td>
						<td>
							<?php if ( $dm_mapping['active'] ) : ?>
								<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
								<?php esc_html_e( 'Active', 'domain-mapping-system' ); ?>
							<?php else : ?>
								<?php esc_html_e( 'Inactive', 'domain-mapping-system' ); ?>
							<?php endif; ?>
							<?php if ( $dm_mapping['verified'] ) : ?>
								&middot; <em><?php esc_html_e( 'verified', 'domain-mapping-system' ); ?></em>
							<?php endif; ?>
						</td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
								<input type="hidden" name="action" value="dm_delete_single_mapping" />
								<input type="hidden" name="id" value="<?php echo absint( $dm_mapping['id'] ); ?>" />
								<?php wp_nonce_field( 'dm_delete_single_mapping' ); ?>
								<button type="submit" class="button-link-delete"
									onclick="return confirm( '<?php echo esc_js( __( 'Remove this domain mapping?', 'domain-mapping-system' ) ); ?>' );">
									<?php esc_html_e( 'Remove', 'domain-mapping-system' ); ?>
								</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>