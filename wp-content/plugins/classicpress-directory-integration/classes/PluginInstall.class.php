<?php

namespace ClassicPress\Directory;

require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

class PluginInstall
{
	use Helpers;

	private $local_cp_plugins = false;

	private $page = null;

	public function __construct()
	{
		// Add menu under plugins.
		add_action('admin_menu', [$this, 'create_menu'], 100);
		add_action('admin_enqueue_scripts', [$this, 'styles']);
		add_action('admin_enqueue_scripts', [$this, 'scripts']);
		add_action('admin_menu', [$this, 'rename_menu']);
	}

	public function styles($hook)
	{
		if ($hook !== $this->page) {
			return;
		}
		wp_enqueue_style( 'classicpress-directory-integration-css', plugins_url( '../styles/directory-integration.css', __FILE__ ), []) ;
	}

	public function scripts($hook)
	{
		if ($hook !== $this->page) {
			return;
		}
		wp_enqueue_script( 'classicpress-directory-integration-js', plugins_url( '../scripts/directory-integration.js', __FILE__ ), array( 'wp-i18n' ), false, true );
		wp_set_script_translations( 'classicpress-directory-integration-js', 'classicpress-directory-integration', plugin_dir_path( 'classicpress-directory-integration' ) . 'languages' );
	}

	public function create_menu()
	{
		if (!current_user_can('install_plugins')) {
			return;
		}

		$this->page = add_submenu_page(
			'plugins.php',
			esc_html__('Install ClassicPress Plugins', 'classicpress-directory-integration'),
			esc_html__('Install CP Plugins', 'classicpress-directory-integration'),
			'install_plugins',
			'classicpress-directory-integration-plugin-install',
			[$this, 'render_menu'],
			2
		);

		add_action('load-' . $this->page, [$this, 'activate_action']);
		add_action('load-' . $this->page, [$this, 'install_action']);
	}

	public function rename_menu() {
		global $submenu;
		foreach ( $submenu['plugins.php'] as $key => $value ) {
			if($value[2] !== 'plugin-install.php') {
				continue;
			}
			$submenu['plugins.php'][$key][0] = esc_html__('Install WP Plugins', 'classicpress-directory-integration'); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	// Get all installed ClassicPress plugin
	// This function is different from the one in PluginUpdate class
	// and considers a plugin from the dir not only if it has UpdateURI
	// but also if it has RequiresCP.
	private function get_local_cp_plugins()
	{

		if ($this->local_cp_plugins !== false) {
			return $this->local_cp_plugins;
		}

		$all_plugins = get_plugins();
		$cp_plugins  = [];
		foreach ($all_plugins as $slug => $plugin) {
			if (!array_key_exists('UpdateURI', $plugin) && !array_key_exists('RequiresCP', $plugin)) {
				continue;
			}
			if (strpos($plugin['UpdateURI'], \CLASSICPRESS_DIRECTORY_INTEGRATION_URL) !== 0 && !array_key_exists('RequiresCP', $plugin)) {
				continue;
			}
			$cp_plugins[dirname($slug)] = [
				'WPSlug'      => $slug,
				'Name'        => $plugin['Name'],
				'Version'     => $plugin['Version'],
				'PluginURI'   => array_key_exists('PluginURI', $plugin) ? $plugin['PluginURI'] : null,
				'Active'      => is_plugin_active($slug),
			];
		}

		$this->local_cp_plugins = $cp_plugins;
		return $this->local_cp_plugins;
	}

	// Validate and sanitize args for quering the ClassicPress Directory
	public static function sanitize_args($args)
	{
		foreach ($args as $key => $value) {
			$sanitized = false;
			switch ($key) {
				case 'per_page':
				case 'page':
					$args[$key] = (int) $value;
					$sanitized = true;
					break;
				case 'byslug':
					$args[$key] = preg_replace('[^A-Za-z0-9\-_]', '', $value);
					$sanitized = true;
					break;
				case 'search':
					$args[$key] = sanitize_text_field($value);
					$sanitized = true;
					break;
				case '_fields':
					$args[$key] = preg_replace('[^A-Za-z0-9\-_,]', '', $value);
					$sanitized = true;
					break;
			}
			if ($sanitized) {
				continue;
			}
			unset($args[$key]);
		}
		return $args;
	}

	// Query the ClassicPress Directory
	public static function do_directory_request($args = [], $type = 'plugins')
	{
		$result['success'] = false;

		if (!in_array($type, ['plugins', 'themes'])) {
			$result['error'] = $type . ' is not a supported type';
			return $result;
		}

		$args = self::sanitize_args($args);
		$endpoint = \CLASSICPRESS_DIRECTORY_INTEGRATION_URL . $type;
		$endpoint = add_query_arg($args, $endpoint);

		$response = wp_remote_get($endpoint, ['user-agent' => classicpress_user_agent()]);

		if (is_wp_error($response)) {
			$result['error'] = rtrim(implode(',', $response->get_error_messages()), '.');
			return $result;
		}

		$e = wp_remote_retrieve_response_code($response);
		if ($e !== 200) {
			$result['error'] = $response['response']['message'];
			$result['code']  = $response['response']['code'];
			return $result;
		}

		if (!isset($response['headers'])) {
			$result['error'] = 'No headers found';
			return $result;
		}

		$headers = $response['headers']->getAll();
		if (!isset($headers['x-wp-total']) || !isset($headers['x-wp-totalpages'])) {
			$result['error'] = 'No pagination headers found';
			return $result;
		}

		$data_from_dir = json_decode(wp_remote_retrieve_body($response), true);
		if ($data_from_dir === null) {
			$result['error'] = 'Failed decoding response';
			return $result;
		}

		$result['success']     = true;
		$result['total-pages'] = $headers['x-wp-totalpages'];
		$result['total-items'] = $headers['x-wp-total'];
		$result['response']    = $data_from_dir;

		return $result;
	}

	// Enqueue a notice
	private function add_notice($message, $failure = false)
	{
		$other_notices = get_transient('cpdi_pi_notices');
		$notice = $other_notices === false ? '' : $other_notices;
		$failure_style = $failure ? 'notice-error' : 'notice-success';
		$notice .= '<div class="notice ' . $failure_style . ' is-dismissible">';
		$notice .= '    <p>' . esc_html($message) . '</p>';
		$notice .= '</div>';
		set_transient('cpdi_pi_notices', $notice, \HOUR_IN_SECONDS);
	}

	// Display notices
	private function display_notices()
	{
		$notices = get_transient('cpdi_pi_notices');
		if ($notices === false) {
			return;
		}
		// This contains html formatted from 'add_notice' function that uses 'esc_html'.
		echo $notices; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		delete_transient('cpdi_pi_notices');
	}

	// Deal with activation requests
	public function activate_action()
	{

		// Load local plugins information
		$local_cp_plugins = $this->get_local_cp_plugins();

		// Security checks
		if (!isset($_GET['action'])) {
			return;
		}
		if ($_GET['action'] !== 'activate') {
			return;
		}
		if (!check_admin_referer('activate', '_cpdi')) {
			return;
		}
		if (!current_user_can('activate_plugins')) {
			return;
		}
		if (!isset($_REQUEST['slug'])) {
			return;
		}
		// Check if plugin slug is proper
		$slug = sanitize_key(wp_unslash($_REQUEST['slug']));
		if (!array_key_exists($slug, $local_cp_plugins)) {
			return;
		}

		// Activate plugin
		$result = activate_plugin($local_cp_plugins[$slug]['WPSlug']);

		if ($result !== null) {
			// Translators: %1$s is the plugin name.
			$message = sprintf(esc_html__('Error activating %1$s.', 'classicpress-directory-integration'), $local_cp_plugins[$slug]['Name']);
			$this->add_notice($message, true);
		} else {
			// Translators: %1$s is the plugin name.
			$message = sprintf(esc_html__('%1$s activated.', 'classicpress-directory-integration'), $local_cp_plugins[$slug]['Name']);
			$this->add_notice($message, false);
		}

		$sendback = remove_query_arg(['action', 'slug', '_cpdi'], wp_get_referer());
		wp_safe_redirect($sendback);
		exit;
	}

	// Deal with installation requests
	public function install_action()
	{

		// Security checks
		if (!isset($_GET['action'])) {
			return;
		}
		if ($_GET['action'] !== 'install') {
			return;
		}
		if (!check_admin_referer('install', '_cpdi')) {
			return;
		}
		if (!current_user_can('install_plugins')) {
			return;
		}
		if (!isset($_REQUEST['slug'])) {
			return;
		}
		// Check if plugin slug is proper
		$slug = sanitize_key(wp_unslash($_REQUEST['slug']));

		// Get github release file
		$args = [
			'byslug'  => $slug,
			'_fields' => 'meta,title',
		];
		$response = $this->do_directory_request($args, 'plugins');
		if (!$response['success'] || !isset($response['response'][0]['meta']['download_link'])) {
			// Translators: %1$s is the plugin name.
			$message = sprintf(esc_html__('API error.', 'classicpress-directory-integration'), $local_cp_plugins[$slug]['Name']);
			$this->add_notice($message, true);
			$sendback = remove_query_arg(['action', 'slug', '_cpdi'], wp_get_referer());
			wp_safe_redirect($sendback);
			exit;
		}

		$installation_url = $response['response'][0]['meta']['download_link'];
		$plugin_name      = $response['response'][0]['title']['rendered'];

		// Install plugin
		$skin     = new PluginInstallSkin(['type'  => 'plugin']);
		$upgrader = new \Plugin_Upgrader($skin);
		$response = $upgrader->install($installation_url);

		if ($response !== true) {
			// Translators: %1$s is the plugin name.
			$message = sprintf(esc_html__('Error installing %1$s.', 'classicpress-directory-integration'), $plugin_name);
			$this->add_notice($message, true);
		} else {
			// Translators: %1$s is the plugin name.
			$message = sprintf(esc_html__('%1$s installed.', 'classicpress-directory-integration'), $plugin_name);
			$this->add_notice($message, false);
		}

		$sendback = remove_query_arg(['action', 'slug', '_cpdi'], wp_get_referer());
		wp_safe_redirect($sendback);
		exit;
	}

	// Render "Install CP plugins" menu
	public function render_menu()
	{ // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh
		// Load local plugins information
		$local_cp_plugins = $this->get_local_cp_plugins();

		// Set age number if empty
		// We check nonces only on activations and installations.
		// In this function nothing is modified.
		$page   = isset($_REQUEST['getpage']) ? (int) $_REQUEST['getpage'] : 1; //phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Query the directory
		$args = [
			'per_page' => 12,
			'page'     => $page,
		];

		if (isset($_REQUEST['searchfor'])) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args['search'] = sanitize_text_field(wp_unslash($_REQUEST['searchfor'])); //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$result = $this->do_directory_request($args);
		if ($result['success'] === false) {
			// Query failed, display errors and exit.
			$this->add_notice(esc_html($result['error']).' ('.esc_html($result['code']).').', true);
		}

		// Set up variables
		$plugins = $result['response'] ?? [];
		$pages   = $result['total-pages'] ?? 0;

		if ($plugins === []) {
			$this->add_notice(esc_html__('No plugins found.', 'classicpress-directory-integration'), true);
		}

		// Display notices
		$this->display_notices();
?>

		<div class="wrap plugin-install-tab">
			<h1 class="wp-heading-inline"><?php echo esc_html__('Plugins', 'classicpress-directory-integration'); ?></h1>
			<h2 class="screen-reader-text"><?php echo esc_html__('Plugins list', 'classicpress-directory-integration'); ?></h2>

			<!-- Search form -->
			<div class="cp-plugin-search-form">
				<form method="GET" action="<?php echo esc_url(add_query_arg(['page' => 'classicpress-directory-integration-plugin-install'], remove_query_arg(['getpage']))); ?>">
					<p class="cp-plugin-search-box">
						<label for="searchfor" class="screen-reader-text" ><?php echo esc_html__('Search for plugins', 'classicpress-directory-integration'); ?></label><br>
						<input type="text" id="searchfor" name="searchfor" class="wp-filter-search" placeholder="<?php echo esc_html__('Search for a plugin...', 'classicpress-directory-integration'); ?>"><br>
						<?php
						foreach ((array) $_GET as $key => $val) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
							if (in_array($key, ['searchfor'])) {
								continue;
							}
							echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_html($val) . '" />';
						}
						?>
					</p>
				</form>
			</div>
			<hr class="wp-header-end">

			<div class="cp-plugins-page">
				<div class="cp-plugin-cards">
					<?php
					foreach ( $plugins as $plugin ) {
						$slug = $plugin['meta']['slug'];
						$content = $plugin['content']['rendered'];
						$markdown_contents = self::get_markdown_contents( $content, '<div class="markdown-heading">', '</div>' );
						foreach ( $markdown_contents as $markdown_content ) {
							$content = str_replace( '<div class="markdown-heading">' . $markdown_content . '</div>', $markdown_content, $content );
						}
					?>
						<article class="cp-plugin-card" id="cp-plugin-id-<?php echo esc_attr($slug); ?>">
							<header class="cp-plugin-card-header">
								<h3><?php echo esc_html($plugin['title']['rendered']); ?></h3>
								<div class="cp-plugin-author"><?php echo wp_kses(sprintf(__('By <b>%1$s</b>.', 'classicpress-directory-integration'), $plugin['meta']['developer_name']), ['b' => []]); ?></div>
							</header>
							<div class="cp-plugin-card-body">
								<div class="cp-plugin-description"><?php echo wp_kses_post($plugin['excerpt']['rendered']); ?></div>
							</div>
							<footer class="cp-plugin-card-footer" data-content="<?php echo esc_attr( $content ); ?>">
								<div class="cp-plugin-installs"><?php echo esc_html($plugin['meta']['active_installations'] === '' ? 0 : $plugin['meta']['active_installations']) . esc_html__(' Active Installations', 'classicpress-directory-integration'); ?></div>
								<div class="cp-plugin-actions">
									<a href="https://directory.classicpress.net/plugins/<?php echo esc_attr( $slug ); ?>" target="_blank" class="button link-txt"><?php esc_html_e('More Details', 'classicpress-directory-integration'); ?></a>
									<?php
									if (!array_key_exists($slug, $local_cp_plugins)) {
										echo '<a href="' . esc_url(wp_nonce_url(add_query_arg(['action' => 'install', 'slug' => $slug]), 'install', '_cpdi')) . '" class="button install-now">' . esc_html__('Install', 'classicpress-directory-integration') . '</a>';
									}
									if (array_key_exists($slug, $local_cp_plugins) && $local_cp_plugins[$slug]['Active']) {
										echo '<span class="button cp-plugin-installed" tabindex="0">' . esc_html__('Active', 'classicpress-directory-integration') . '</span>';
									}
									if (array_key_exists($slug, $local_cp_plugins) && !$local_cp_plugins[$slug]['Active']) {
										echo '<a href="' . esc_url(wp_nonce_url(add_query_arg(['action' => 'activate', 'slug' => $slug]), 'activate', '_cpdi')) . '" class="button button-primary">' . esc_html__('Activate', 'classicpress-directory-integration') . '</a>';
									}
									?>
								</div>
							</footer>
						</article>
					<?php
					}
					?>
				</div>

				<nav aria-label="<?php esc_attr_e('Plugin search results navigation', 'classicpress-directory-integration'); ?>">
					<ul class="cp-plugins-pagination">
						<?php
						for ($x = 1; $x <= $pages; $x++) {
							$current_page = ($x == $page) ? ' cp-current-page" aria-current="page' : '';
							$link = '<a href="' . esc_url(add_query_arg(['getpage' => $x], remove_query_arg('getpage'))) . '">' . (int)$x . '</a>';
							echo '<li class="cp-search-page-item' . wp_kses_post($current_page) . '">' . wp_kses_post($link) . '</li>';
						}
						?>
					</ul>
				</nav>


			</div>
		</div>

<?php
	} // End of render_menu()

}

class PluginInstallSkin extends \Plugin_Installer_Skin
{

	public function header()
	{
	}

	public function footer()
	{
	}

	public function error($errors)
	{
	}

	public function feedback($string, ...$args)
	{
	}
}
