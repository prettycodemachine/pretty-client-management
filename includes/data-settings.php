<?php
/**
 * PCM Settings › Platform › Data & Uninstall.
 *
 * One switch: whether deleting the plugin also deletes everything it stored.
 * Off by default — this is a business's client records, and "delete the
 * plugin" is a routine step when reinstalling or moving from a zip install to
 * the WordPress.org one. uninstall.php reads the option directly, since none
 * of this file is loaded when it runs.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const PCM_CRM_DELETE_DATA_OPTION = 'pcm_crm_delete_data_on_uninstall';

pcm_crm_register_setup_page( 'data', array(
	'group'       => 'platform',
	'label'       => __( 'Data & Uninstall', 'pretty-client-management' ),
	'description' => __( 'What happens to your CRM data if the plugin is deleted.', 'pretty-client-management' ),
	'render'      => 'pcm_crm_render_data_tab',
	'order'       => 90,
) );

function pcm_crm_register_data_settings() {
	pcm_crm_register_setting( 'pcm_crm_data_settings', PCM_CRM_DELETE_DATA_OPTION, array(
		'type'              => 'boolean',
		'sanitize_callback' => 'rest_sanitize_boolean',
		'default'           => false,
	) );
}
add_action( 'admin_init', 'pcm_crm_register_data_settings' );

function pcm_crm_render_data_tab() {
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" class="pcm-crm-card">
		<?php settings_fields( 'pcm_crm_data_settings' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'On uninstall', 'pretty-client-management' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( PCM_CRM_DELETE_DATA_OPTION ); ?>" value="1" <?php checked( (bool) get_option( PCM_CRM_DELETE_DATA_OPTION, false ) ); ?>>
						<?php esc_html_e( 'Delete all CRM data when the plugin is deleted', 'pretty-client-management' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Removes every account, contact, opportunity, project and setting this plugin stored, along with its Staff and Client roles. Deactivating never deletes anything; only deleting the plugin from the Plugins screen does, and only while this is ticked. This cannot be undone.', 'pretty-client-management' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>
	<?php
}
