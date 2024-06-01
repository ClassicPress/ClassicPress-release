<?php

namespace ClassicPress\Directory;

class ThemeUpdate {

	private $cp_themes_directory_data = false;
	private $cp_themes = false;

	public function __construct() {

		// Hook to check for updates
		$update_plugins_hook = 'update_themes_'.wp_parse_url(\CLASSICPRESS_DIRECTORY_INTEGRATION_URL, PHP_URL_HOST);
		add_filter($update_plugins_hook, [$this, 'update_uri_filter'], 10, 4);

	}

	// Get all installed ClassicPress themes
	private function get_cp_themes() {

		if ($this->cp_themes !== false) {
			return $this->cp_themes;
		}

		$all_themes = wp_get_themes();
		$cp_themes  = [];
		foreach ($all_themes as $slug => $theme) {
			if ($theme->display('UpdateURI') === '') {
				continue;
			}
			if (strpos($theme->display('UpdateURI'), \CLASSICPRESS_DIRECTORY_INTEGRATION_URL) !== 0) {
				continue;
			}
			$cp_themes[$slug] = [
				'WPSlug'      => $slug,
				'Version'     => $theme->display('UpdateURI'),
				'RequiresPHP' => $theme->display('RequiresPHP'),
				'RequiresCP'  => $theme->display('RequiresCP'),
				'PluginURI'   => $theme->display('PluginURI'),
			];
		}

		$this->cp_themes = $cp_themes;
		return $this->cp_themes;

	}

	// Get data from the directory for all installed ClassicPress themes
	private function get_directory_data($force = false) {

		// Try to get stored data
		if (!$force && $this->cp_themes_directory_data !== false) {
			// We have it in memory
			return $this->cp_themes_directory_data;
		}
		$this->cp_themes_directory_data = get_transient('cpdi_directory_data_themes');
		if (!$force && $this->cp_themes_directory_data !== false) {
			// We have it in transient
			return $this->cp_themes_directory_data;
		}

		// Query the directory
		$themes   = $this->get_cp_themes();
		$endpoint = \CLASSICPRESS_DIRECTORY_INTEGRATION_URL.'themes?byslug='.implode(',', array_keys($themes)).'&_fields=meta';
		$response = wp_remote_get($endpoint, ['user-agent' => classicpress_user_agent(true)]);

		if (is_wp_error($response) || empty($response['response']) || wp_remote_retrieve_response_code($response) !== 200) {
			return [];
		}

		$data_from_dir = json_decode(wp_remote_retrieve_body($response), true);
		$data = [];

		foreach ($data_from_dir as $single_data) {
			$data[$single_data['meta']['slug']] = [
				'Download'        => $single_data['meta']['download_link'],
				'Version'         => $single_data['meta']['current_version'],
				'RequiresPHP'     => $single_data['meta']['requires_php'],
				'RequiresCP'      => $single_data['meta']['requires_cp'],
				'active_installs' => $single_data['meta']['active_installations'],
			];
		}

		$this->cp_themes_directory_data = $data;
		set_transient('cpdi_directory_data_themes', $this->cp_themes_directory_data, 3 * HOUR_IN_SECONDS);
		return $this->cp_themes_directory_data;

	}

	// Filter to trigger updates using Update URI header
	public function update_uri_filter($update, $theme_data, $theme_stylesheet, $locales) {

		// https://developer.wordpress.org/reference/hooks/update_themes_hostname/

		// Get the slug from Update URI
		if (preg_match('/themes\?byslug=(.*)/', $theme_data['UpdateURI'], $matches) !== 1) {
			return false;
		}

		// Check if the slug matches theme dir
		if (!isset($matches[1]) || $theme_stylesheet !== $matches[1]) {
			return false;
		}
		$slug = $matches[1];

		// Check if we have that theme in installed ones
		$themes  = $this->get_cp_themes();

		if (!array_key_exists($slug, $themes)) {
			return false;
		}

		// Check if we have that theme in directory ones
		$dir_data = $this->get_directory_data();
		if (!array_key_exists($slug, $dir_data)) {
			return false;
		}

		$theme = $themes[$slug];
		$data  = $dir_data[$slug];

		if (version_compare($theme['Version'], $data['Version']) >= 0) {
			// No updates available
			return false;
		}
		if (version_compare(classicpress_version(), $theme['RequiresCP']) === -1) {
			// Higher CP version required
			return false;
		}
		if (version_compare(phpversion(), $theme['RequiresPHP']) === -1) {
			// Higher PHP version required
			return false;
		}

		$update = [
			'slug'         => $theme_stylesheet,
			'version'      => $data['Version'],
			'package'      => $data['Download'],
			'requires_php' => $data['RequiresPHP'],
			'requires_cp'  => $data['RequiresCP'],
			'url'          => 'https://'.wp_parse_url(\CLASSICPRESS_DIRECTORY_INTEGRATION_URL, PHP_URL_HOST).'/themes/'.$theme_stylesheet,
		];

		return $update;

	}

}
