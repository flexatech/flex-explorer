## Determine files and directories locations correctly

WordPress provides several functions for easily determining where a given file or directory lives.

We detected that the way your plugin references some files, directories and/or URLs may not work with all WordPress setups. This happens because there are hardcoded references or you are using the WordPress internal constants.

Let's improve it, please check out the following documentation:

https://developer.wordpress.org/plugins/plugin-basics/determining-plugin-and-content-directories/

It contains all the functions available to determine locations correctly.

Most common cases in plugins can be solved using the following functions:

    For where your plugin is located: plugin_dir_path(), plugin_dir_url(), plugins_url()
    For the uploads directory: wp_upload_dir() (Note: If you need to write files, please do so in a folder in the uploads directory, not in your plugin directories).


Example(s) from your plugin:

flex-explorer.php:110 $base    = isset( $uploads['basedir'] ) ? dirname( $uploads['basedir'] ) : trailingslashit( ABSPATH ) . 'wp-content';
 -----> ABSPATH
# ✨ Fallback path hardcodes ABSPATH/wp-content, which can break on installs with a custom content directory; use WP_CONTENT_DIR instead of concatenating ABSPATH with wp-content.