<?php
/**
 * Settings admin page.
 *
 * @package Rivian_Software_Updates
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="wrap">
	<h1>Rivian Software Updates Settings</h1>
	<form method="post" action="options.php">
		<?php
		settings_fields( 'rsu_settings_group' );
		do_settings_sections( 'rsu-settings' );
		submit_button();
		?>
	</form>
</div>
