<?php
// Copyright 2011 _ALL RIGHTS RESERVED!_
/**
*	FindMyiPhone:
*		me.com (1.0) device manipulation tool
*		written by rsully
*	
*	Features:
*		Code:
*			Object oriented
*			Easy to use
*		Proceedural:
*			Authenticates to me.com
*			Gets custom hostname
*			Gets device listing
*		Functionality:
*			Sends messages
*			Locks devices
*/

error_reporting(E_ALL);

function DebugMsg($msg) {
	echo "__Debug: \t$msg\n";
}
function SafeArrayString($arr) {
	$ret = '';
	foreach($arr as $k => $v) {
		$ret .= ('&'.urlencode($k));
		if(strlen($v) > 0) {
			$ret .= ('='.urlencode($v));
		}
	}
	return substr($ret, 1);
}
function RequestSplit($resp, $info) {
	$ret = array();
	$ret['header'] = substr($resp, 0, $info['header_size']);
	if($info['download_content_length'] > 0) {
		$ret['body'] = substr($resp, -$info['download_content_length']);
	} else {
		$ret['body'] = '';
	}
	return $ret;
}

class iDevice
{
	// message, lock, sound, location, wipe
	private $_data = array(), $fms = null;
	public $features = array('MSG' => 0, 'LCK' => 0, 'SND' => 0, 'LOC' => 0, 'WIP' => 0);
	public $names = array('model' => '', 'displayName' => '', 'name' => '', 'class' => '');
	public $_wipe, $_lock, $_msg, $_location;
	public $id, $deviceStatus, $isLocating, $locationEnabled;
	
	function __construct($fms, $data) {
		$this->fms = $fms;
		$this->_data = $data;
		$this->parse();
		if($this->fms->_debug) {
			$this->log();
		}
	}
	public function parse() {
		$this->id = $this->_data['id'];
		$this->deviceStatus = $this->_data['deviceStatus'];
		if(isset($this->fms->devs_numbers[$this->_data['deviceStatus']])) {
			$this->deviceStatus .= "_".$this->fms->devs_numbers[$this->_data['deviceStatus']];
		}
		$this->isLocating = $this->_data['isLocating'];
		$this->locationEnabled = $this->_data['locationEnabled'];
		$this->features = $this->_data['features'];
		$this->names['model'] = $this->_data['deviceModel'];
		$this->names['displayName'] = $this->_data['deviceDisplayName'];
		$this->names['name'] = $this->_data['name'];
		$this->names['class'] = $this->_data['deviceClass'];
		$this->_wipe = $this->_data['remoteWipe'];
		$this->_lock = $this->_data['remoteLock'];
		$this->_msg = $this->_data['msg'];
		$this->_location = $this->_data['location'];
	}
	public function log() {
		$log = sprintf("\n[%s] `%s`", $this->names['displayName'], $this->names['name'], $this->id, $this->isLocating);
		$log .= sprintf("\n\tdeviceStatus=\t\t%s", $this->deviceStatus);
		$log .= sprintf("\n\tlocationEnabled=\t%d", $this->locationEnabled);
		if($this->locationEnabled) {
			$log .= sprintf("\n\t\tisLocating=\t%d", $this->isLocating);
		}
		if($this->_location) {
			$log .= sprintf("\n\t-Last location");
			$log .= sprintf("\n\t\tlastLocated=\t%s", date(DATE_RSS, $this->_location['timeStamp']/1000));
			$log .= sprintf("\n\t\tpositionType=\t%s", $this->_location['positionType']);
			$log .= sprintf("\n\t\thorizAccuracy=\t%s", $this->_location['horizontalAccuracy']);
			$log .= sprintf("\n\t\tlongitude=\t%s", $this->_location['longitude']);
			$log .= sprintf("\n\t\tlatitude=\t%s", $this->_location['latitude']);
			if($this->_location['isOld']) {
				$log .= sprintf("\n\t\t** IS OLD = %s", $this->_location['isOld']);
			}
		}
		if($this->_msg) {
			$log .= sprintf("\n\t-Last message:");
			$log .= sprintf("\n\t\tlastMessaged=\t%s", date(DATE_RSS, $this->_msg['createTimestamp']/1000));
			$log .= sprintf("\n\t\tlastStatus=\t%s", $this->fms->devs_msg_err[$this->_msg['statusCode']]);
		}
		$log .= sprintf("\n\tFeatures: ");
		foreach($this->features as $f => $enabled) {
			if($enabled == '1' || $enabled == 1) {
				$log .= sprintf(" %s ", $f);
			}
		}
		$log .= sprintf("\n\ta=%s\n\tb=%s\n[/%s]\n\n", $this->_data['a'], $this->_data['b'], $this->names['displayName']);
		echo $log;
	}
	
	public function sendMsg($body = 'Test', $title = 'Important Message', $sound = false) {
		// "device":"id thing~","text":"nomnomnom","sound":false,"subject":"Important Message"
		//if($sound && (!isset($this->features['SND']) || !$this->features['SND'])) { $sound = false; }
		if(!isset($this->features['MSG']) || !$this->features['MSG']) { return false; }
		$fields = array('device' => $this->id, 'subject' => $title, 'text' => $body, 'sound' => (bool)$sound);
		return $this->fms->sendPayload($fields, 'MSG');
	}
	public function sendLck($new = '', $old = '') {
		// "device":"id thing~","oldPasscode":"","passcode":""
		$fields = array('device' => $this->id, 'oldPasscode' => $old, 'passcode' => $new);
		return $this->fms->sendPayload($fields, 'LCK');
	}
	public function sendLoc($loc = true) {
		//"shouldLocate":true,"selectedDevice":"QVBTOmVjZjc0ODE0ZmMyN2UwNWI3YjAzZjhiOWI2YjRhMTFiMzk2MTRiNmY~"
		$fields = array('clientContext' => array('shouldLocate' => $loc, 'selectedDevice' => $this->id));
		return $this->fms->sendPayload($fields, 'LOC');
	}
}

class FindMyiPhone
{
	private $auth = 'https://auth.icloud.com/authenticate';
	private $auth_ret = 'https://icloud.com/find/';
	private $auth_can = 'http://icloud.com/find';
	
	private $devs_partition = '';
	private $devs_try_base = 'https://XYZ-fmipweb.icloud.com';
	private $devs = '';
	
	private $devs_ini = '/fmipservice/client/initClient';
	private $devs_ref = '/fmipservice/client/refreshClient';
	private $devs_loc = '/fmipservice/client/locateDevice';
	private $devs_msg = '/fmipservice/client/sendMessage';
	public $devs_msg_err = array(500 => 'SEND_MESSAGE_FAILURE', 200 => 'SEND_MESSAGE_MSG_DISPLAED', 205 => 'SEND_MESSAGE_MSG_SENT');
	private $devs_lck = '/fmipservice/client/remoteLock';
	public $devs_lck_err = array(205 => 'LOCK_SENT', 1200 => 'LOCK_SUCC_PASSCODE_CHANGED', 1403 => 'LOCK_SUCC_PASSCODE_NOT_SET_CONS_FAIL', 1201 => 'LOCK_SUCCESSFUL_1', 1406 => 'LOCK_FAIL_NO_PASSCD_1', 2200 => 'LOCK_SUCC_PASSCODE_SET', 2406 => 'LOCK_FAIL_NO_PASSCD_2', 2403 => 'LOCK_FAIL_PASSCODE_NOT_SET_CONS_FAIL', 2201 => 'LOCK_SUCC_PASSCODE_NOT_SET_PASSCD_EXISTS', 2204 => 'LOCK_SUCCESSFUL_2', 500 => 'LOCK_SERVICE_FAILURE');
	public $devs_numbers = array(200 => 'DEVICE_STATUS_ONLINE', 201 => 'DEVICE_STATUS_OFFLINE', 203 => 'DEVICE_STATUS_PENDING', 204 => 'DEVICE_STATUS_UNREGISTERED', 500 => 'DEVICE_STATUS_ERROR');
	private $user = '', $pass = '';
	protected $ch = null, $_lastSend;
	public $_devices_response, $prsId, $_debug;
	
	function __construct($user, $pass) {
		$this->user = $user;
		$this->pass = $pass;
		$this->devices = array();
		$this->prsId = 0;
		$this->_lastSend = 0;
		$this->ch = curl_init();
		curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($this->ch, CURLINFO_HEADER_OUT, true);
		curl_setopt($this->ch, CURLOPT_HEADER, true);
		curl_setopt($this->ch, CURLOPT_COOKIEJAR, '/tmp/_lulz'.time());
		curl_setopt($this->ch, CURLOPT_COOKIEFILE, '/tmp/_lulz'.time());
	}
	
	public function d($m) { if($this->_debug) { DebugMsg($m); } }
	public function debug($d) { $this->_debug = $d; }
	public function login() {
		$this->_lastSend = time();
		$headers = array('Referer: https://auth.icloud.com/authenticate?service=findmyiphone&ssoNamespace=appleid&formID=loginForm&returnURL=aHR0cHM6Ly9tZS5jb20vZmluZC8=&anchor=findmyiphone&lang=en', 'Origin: https://auth.icloud.com', 'User-Agent: Mozilla/5.0 (Macintosh) Version/5.0.5', 'Content-Type: application/x-www-form-urlencoded');
		$post = array('service' => 'findmyiphone', 
					'ssoNamespace' => 'appleid', 
					'returnURL' => base64_encode($this->auth_ret), 
					'cancelURL' => $this->auth_can, 
					'{SSO_ATTRIBUTE_NAME}' => '{SSO_ATTRIBUTE_VALUE}', 
					'ssoOpaqueToken' => null, 
					'ownerPrsId' => null, 
					'formID' => 'loginForm', 
					'username' => $this->user, 
					'password' => $this->pass);
		$post = SafeArrayString($post);
		curl_setopt($this->ch, CURLOPT_URL, $this->auth);
		curl_setopt($this->ch, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($this->ch, CURLOPT_POST, true);
		curl_setopt($this->ch, CURLOPT_POSTFIELDS, $post);
		curl_setopt($this->ch, CURLOPT_HTTPHEADER, $headers);
		
		$_response = RequestSplit(curl_exec($this->ch), curl_getinfo($this->ch));
		$code = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
		if($code == '302' || $code == 302) {
			$this->getDevsCookie($_response['header'], 'nc-partition=');
			return true;
		}
		return false;
	}
	private function useDevs($use) {
		$this->devs = str_replace('XYZ', $use, $this->devs_try_base);
		$this->d("Using `$this->devs`");
	}
	private function getDevsCookie($content, $cookie) {
		$begin = strpos($content, $cookie);
		$content = substr($content, $begin);
		$end = strpos($content, ';');
		$data = substr($content, 0, $end);
		$this->useDevs(str_replace($cookie, '', $data));
	}
	
	private function _sessionTimeoutCheck() {
		$last = $this->_lastSend; $now = time();
		$timeout = 450;
		if($this->_devices_response) {
			$timeout = floor($this->_devices_response['serverContext']['sessionLifespan']/1000);
		}
		return (($now - $last) > $timeout);
	}
	public function sendPayload($customFields, $location, $returnResponse = false) {
		if($this->_sessionTimeoutCheck()) {
			$this->d('(Re)login...');
			$_login = $this->login();
			if($_login == false) {
				$this->d('(Re)login failed.');
				return false;
			}
		}
		$this->_lastSend = time();
		
		$_errs = array('200' => 'SUCCESS'); $errs = array();
		if($location == 'LOC') { $location = 'REF'; }
		if($location == 'INI') { $location = $this->devs_ini; }
		if($location == 'REF') { $location = $this->devs_ref; }
		if($location == 'MSG') { $location = $this->devs_msg; $errs = $this->devs_msg_err; }
		if($location == 'LCK') { $location = $this->devs_lck; $errs = $this->devs_lck_err; }
		$errs = array_merge($errs, $_errs);
		
		$payload = json_decode($this->_payload(), true);
		$payload = array_merge($customFields, $payload);
		
		curl_setopt($this->ch, CURLOPT_HTTPHEADER, $this->_headers());
		curl_setopt($this->ch, CURLOPT_URL, $this->devs.$location);
		curl_setopt($this->ch, CURLOPT_POST, true);
		curl_setopt($this->ch, CURLOPT_POSTFIELDS, json_encode($payload));
		$_response = RequestSplit(curl_exec($this->ch), curl_getinfo($this->ch));
		$code = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
		//print_r(json_decode($_response['body'], true));
		
		if(isset($errs[$code])) {
			$error = $errs[$code]."_$code";
		} else {
			$error = "UNKNOWN_$code";
		}
		return ($returnResponse == true ? array('response' => $_response, 'code' => $code) : $code);
	}
	
	private function _payload() {
		$pay = '{"clientContext":{"appName":"MobileMe Find (Web)","appVersion":"1.0"}}';
		$pay = json_decode($pay, true);
		if(isset($this->devices['serverContext'])) {
			$pay['serverContext'] = $this->devices['serverContext'];
		}
		return json_encode($pay);
	}
	private function _headers() {
		return array('Content-Type: application/json', 'Origin: '.$this->devs, 'Referer: '.$this->devs.'/find/resources/frame.html', 'User-Agent: Mozilla/5.0 (Macintosh) Version/5.0.5', 'X-Mobileme-Version: 1.0', 'X-Sproutcore-Version: 1.0');
	}
	//--
	public function devices() {
		curl_setopt($this->ch, CURLOPT_URL, $this->auth_ret);
		curl_exec($this->ch);
		$code = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
		if($code != 200) {
			$_login = $this->login();
			if($_login == false) {
				$this->d('Failed login');
				return false;
			}
		}
		
		if($this->devices == null || count($this->devices) < 1) {
			$this->getDevices();
		} else {
			$this->updateDevices();
		}
		return $this->devices;
	}
	//--
	private function getDevices() {
		$_resp = $this->sendPayload(array(), 'INI', true);
		$_response = $_resp['response'];
		$_code = $_resp['code'];
		
		if($_code == 200) {
			$this->handleDeviceResp($_response);
		}
		return ($_code == 200);
	}
	private function updateDevices() {
		if(isset($this->devices) === false || count($this->devices) < 1) {
			return $this->getDevices();
		}
		
		$_resp = $this->sendPayload(array(), 'REF', true);
		$_response = $_resp['response'];
		$_code = $_resp['code'];
		
		if($_code == 200) {
			$this->handleDeviceResp($_response);
		}
		return ($_code == 200);
	}
	//--
	private function createDeviceObjects($content) {
		$objs = array();
		foreach($content as $k => $v) {
			$objs[$k] = new iDevice($this, $v);
		}
		return $objs;
	}
	private function handleDeviceResp($resp) {
		$head = $resp['header'];
		$body = $resp['body'];
		$this->_devices_response = json_decode($body, true);
		//print_r($this->_devices_response);
		@date_default_timezone_set($this->_devices_response['serverContext']['timezone']['tzName']);
		$this->prsId = $this->_devices_response['serverContext']['prsId'];
		$this->devices = $this->createDeviceObjects($this->_devices_response['content']);
	}
}
?>
