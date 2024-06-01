<?php

namespace ClassicPress\Directory;

if (!defined('ABSPATH')) {
	die('-1');
}

/**
* Commands to work with ClassicPress Directory Integration.
*
*
* ## EXAMPLES
*
*     wp cpdi flush
*
* @when after_wp_load
*/

class CPDICLI{

	/**
	* Flush ClassicPress Directory Integration data.
	*
	*
	* ## EXAMPLES
	*
	*     wp cpdi flush
	*
	* @when after_wp_load
	*/
	public function flush($args, $assoc_args) {
		$transients = [
			'cpdi_pi_notices',
			'cpdi_ti_notices',
			'cpdi_directory_data_plugins',
			'cpdi_directory_data_themes',
		];

		$count = 0;

		foreach ($transients as $transient) {
			if (delete_transient($transient)) {
				continue;
			}
			\WP_CLI::warning("Transient '$transient' not found.");
			$count++;
		}

		if ($count === count($transients)) {
			\WP_CLI::error('No transients deleted.');
		}

		$howmany = $count === 0 ? 'all' : count($transients) - $count;

		\WP_CLI::success("Deleted $howmany transients.");
	}
}
