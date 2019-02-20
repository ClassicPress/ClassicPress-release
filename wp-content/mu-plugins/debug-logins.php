<?php

$actions = [
	'lostpassword_form',
	'resetpass_form',
	'register_form',
	'login_form',
];

foreach ( $actions as $action ) {
	add_action( $action, function() use( $action ) {
		error_log( $action );
	} );
}
