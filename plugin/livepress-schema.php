<?php
defined( 'ABSPATH' ) || exit;
$schemas = array();
foreach ( glob( __DIR__ . '/schema-*.php' ) as $file ) {
	$entry = require $file;
	if ( is_array( $entry ) ) { $schemas = array_merge( $schemas, $entry ); }
}
return $schemas;