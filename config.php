<?php
// Database configuration
$db_host = getenv('DB_HOST') ?: 'localhost';
$db_name = getenv('DB_NAME') ?: 'netdisco';
$db_user = getenv('DB_USER') ?: 'netdisco_ro';
$db_pass = getenv('DB_PASS') ?: 'securepassword';

// URL configuration
$netdisco_base_url = getenv('NETDISCO_BASE_URL') ?: '/netdisco2/device?q=';
$script_base_url = getenv('SCRIPT_BASE_URL') ?: '/nettools';

// Vendor filter configuration
$filtervendors = array('hp', 'aruba', 'palo', 'force', 'ubiquiti', 'f5'); 