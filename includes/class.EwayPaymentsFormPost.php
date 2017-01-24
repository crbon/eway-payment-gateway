<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
* wrapper for form post data
*/
class EwayPaymentsFormPost {

	protected $postdata;

	/**
	* maybe unslash the post data and store for access
	*/
	public function __construct() {
		$this->postdata = wp_unslash($_POST);

//~ error_log(__CLASS__ . ": postdata =\n" . print_r($this->postdata,1));

	}

	/**
	* get field value (trimmed if it's a string), or return null if not found
	* @param string $field_name
	* @return mixed|null
	*/
	public function get_value($field_name) {
		if (!isset($this->postdata[$field_name])) {
			return null;
		}

		$value = $this->postdata[$field_name];

		return is_string($value) ? trim($value) : $value;
	}

	/**
	* get array field subkey value (trimmed if it's a string), or return null if not found
	* @param string $field_name
	* @param string $subkey
	* @return mixed|null
	*/
	public function get_subkey($field_name, $subkey) {
		if (!isset($this->postdata[$field_name][$subkey])) {
			return null;
		}

		$value = $this->postdata[$field_name][$subkey];

		return is_string($value) ? trim($value) : $value;
	}

	/**
	* clean up a credit card number value, removing common extraneous characters
	* @param string $value
	* @return string
	*/
	public function clean_cardnumber($value) {
		return strtr($value, array(' ' => '', '-' => ''));
	}

}
