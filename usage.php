<?php
// Include/funct
require_once dirname(__FILE__) . '/findmyiphone.class.php';
function d($m) { echo "** D: $m\n"; }


// Create object:
$fms = new FindMyiPhone('user@me.com', 'password');

// Enable debug:
$fms->debug(true);

// Returns bool:
$login = $fms->login();

if($login === true) {
	d('Login success');
	
	// Returns array
	$devices = $fms->devices();
	
	if(count($devices) > 0) {
		d('Got devices ('.count($devices).')');
		
		// Each device is iDevice object
		// 	$device->sendMsg(body, title, sound t/f);
		//	$device->sendLck();
	}
	
} else {
	d('Login failure');
}

?>
