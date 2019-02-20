<?php

/**
 * Example usage:
 *
 * ```
add_filter( 'wp_redirect', function( $location, $status ) {
	error_log( "wp_redirect $status $location" );
	error_log_backtrace( 'wp_redirect' );
	return $location;
}, 10, 2 );
 * ```
 */

function error_log_backtrace( $marker = 'trace' ) {
	$trace = debug_backtrace();
	error_log( "$marker: (begin backtrace)" );

	for ( $i = count( $trace ) - 1; $i >= 0; $i-- ) {
		$frame = $trace[ $i ];

		if ( ! empty( $frame['class'] ) ) {
			$function = " ($frame[class]#$frame[function])";

		} else if ( ! empty( $frame['function'] ) ) {
			switch ( $frame['function'] ) {
				case 'apply_filters':
				case 'do_action':
					$function = " ($frame[function] '{$frame['args'][0]}')";
					break;

				default:
					$function = " ($frame[function])";
					break;
			}
		}

		if ( substr( $frame['file'], 0, strlen( ABSPATH ) ) === ABSPATH ) {
			$frame['file'] = substr( $frame['file'], strlen( ABSPATH ) );
		}

		error_log( "$marker: $frame[file]:$frame[line]$function" );
	}

	error_log( "$marker: (end backtrace)" );
}
