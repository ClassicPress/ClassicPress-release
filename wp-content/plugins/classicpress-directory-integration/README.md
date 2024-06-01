![ClassicPress Directory Integration Plugin logo](images/banner-772x250.png "ClassicPress Directory Integration Plugin")

# Draft plugin for ClassicPress Directory integrator.

## Features

#### Plugins and Themes from ClassicPress Directory now can update as WP.org plugins.

#### Plugins from ClassicPress Directory now can be installed using the "Install CP Plugins" menu under "Plugins" menu.

#### Themes from ClassicPress Directory now can be installed using the "CP Themes" menu under "Appearance" menu.

## WP-CLI commands

- Flush transients: `wp cpdi flush`

## Hooks

#### `apply_filters( "cpdi_images_folder_{$plugin}", string $folder )`
Filters the folder where we search for icons and banners.
The filtered path is relative to the plugin's directory.

Example:
```php
add_filter(
	'cpdi_images_folder_' . basename( __DIR__ ),
	function ( $source ) {
		return '/assets/images';
	}
);
