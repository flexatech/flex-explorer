List of issues found


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

flex-explorer.php:108 return false === $root ? WP_CONTENT_DIR : $root;
flex-explorer.php:106 $root = realpath( WP_CONTENT_DIR );




## Don't Force Set PHP Limits Globally

While many plugins can need optimal settings for PHP, we ask you please not set them as global defaults.

Having defines like ini_set('memory_limit', '-1'); run globally (like on init or in the __construct() part of your code) means you'll be running that for everything on the site, which may cause your users to fall out of compliance with any limits or restrictions on their host.

If you must use those, you need to limit them specifically to only the exact functions that require them.

Example(s) from your plugin:

flex-explorer.php:674 ini_set('zlib.output_compression', 'Off');