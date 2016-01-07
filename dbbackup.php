#!/bin/env php
<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

set_time_limit(0);

/**
 * @version 1.1.1
 * Creates database backups based on a configuration file.
 * Reads in a configuration file (in "ini" format)
 * The file can contain a [Global] section to specify settings globally.
 * It will also contain one section per database to be be backed up.  Section configurations override [Global] settings
 * Settings include:
 * backuppath - the path where the backup will be stored
 * backupcommand - The command called 
 **/
define('SECTION_GLOBAL', 'Global');
define('AGE_GROUP_YEAR', 'year');
define('AGE_GROUP_MONTH', 'month');
define('AGE_GROUP_WEEK', 'week');
define('AGE_GROUP_DAY', 'day');

$dir = dirname(__FILE__) . DIRECTORY_SEPARATOR;
chdir($dir);
$filename = basename(__FILE__);
$config_file_path = count($argv) > 1 ? $argv[count($argv)-1] : $dir . $filename . '.conf';
$is_debugging = in_array('--debug', $argv); // 
$display_help = in_array('-help', $argv) || in_array('--help', $argv); // Display the configuration help options
$config_exists = file_exists($config_file_path);
$ignore_file_check = false;
if ($display_help) {
	$is_debugging = true;
	$ignore_file_check = true;
}

if (!$ignore_file_check && !$config_exists) die('Missing config file: ' . $config_file_path . '.  You can also specify a config path as an argument.' . PHP_EOL);

$process_sections = true;

$configs = $ignore_file_check ? array() : parse_ini_file($config_file_path, $process_sections);

$default_settings = array(
	'fileextension' => '.sql.gz',
	'bin' => 'mysqldump',
	'command' => '$bin -h $host -u $username -p$password $database $arguments | gzip > $backup_file_path',
	'host' => 'localhost',
	'arguments' => '',
);

$config_help = array(
	'fileextension' => array(
		'label' => 'File Extension',
		'description' => 'The filename for the dumped file',
		'default' => get_raw_setting($default_settings, 'fileextension')
	),
	'backupcommand' => array(
		'label' => 'Backup Command',
		'description' => 'The command run to backup the database.  Can use any values in the form $settingname that are calculated for the per section configuration.',
		'default' => get_raw_setting($default_settings, 'backupcommand')
	),
	'host' => array(
		'label' => 'Host',
		'description' => 'The host that we will be connecting to in order to download the database',
		'default' => get_raw_setting($default_settings, 'host')
	),
	'database' => array(
		'label' => 'Database',
		'description' => 'The name of the database being connected to',
		'default' => null
	),
	'username' => array(
		'label' => 'Username',
		'description' => 'The username used to connect to the database',
		'default' => null
	),
	'password' => array(
		'label' => 'Password',
		'description' => 'The password for connecting to the database',
		'default' => null
	),
	'filebase' => array(
		'label' => 'File name base',
		'description' => 'The file base name to be used as the backup name',
		'default' => 'The section name'
	)
);

if ($display_help) {
	display_help($config_help);
	exit;
}

$global_settings = isset($configs[SECTION_GLOBAL]) ? $configs[SECTION_GLOBAL] : array();

$app_settings = array_merge($default_settings, $global_settings);

foreach($configs as $section => $settings) {
	
	if ($section != SECTION_GLOBAL) {
		
		$config = array_merge($app_settings, $settings);
		
		$backup_path = get_setting($config, 'backuppath');
		if (!in_array(substr($backup_path, -1), array('/', '\\'))) $backup_path .= DIRECTORY_SEPARATOR;
		
		$file_extension = get_setting($config, 'fileextension');
		$host = get_setting($config, 'host');
		$database = get_setting($config, 'database');
		$username = get_setting($config, 'username');
		$pasword = get_setting($config, 'password');
		
		// Generated values
		$filebase = get_setting($config, 'filebase', '');
		if (empty($filebase)) $filebase = preg_replace('/[^a-z]+/i', '', $section);
		
		$backup_filename = sprintf('%s-%s%s', $filebase, date('YmdHis'), $file_extension);
		$backup_file_path = $backup_path . $backup_filename;
		$config['backup_filename'] = $backup_filename;
		$config['backup_file_path'] = $backup_file_path;
		
		$command = get_setting($config, 'command');
		
		if ($is_debugging) echo sprintf('[%s]', $section) . PHP_EOL . '     ';
		
		echo 'Run Command: ' . $command . PHP_EOL;
		
		// Run command
		if (!$is_debugging) $response = `$command`;
		
		// Output
		if ($is_debugging) {
			
			$debug_settings = calculate_config($config);
			
			foreach($debug_settings as $key=>$value) {
				$len_key = strlen($key);
				echo sprintf('     %s => %s', $key, $value) . PHP_EOL;
			}
			
		}
				
		// Cleanup old files
		$dh = opendir($backup_path);
		$pattern = '#' . $filebase . '\-([0-9]{14})' .  $file_extension . '#'; // backup file pattern
		
		#$keep_pattern = get_setting($config, 'keep');
		$keep_yearly = get_asterisk_numeric_setting($config, 'keepyearly');
		$keep_monthly = get_asterisk_numeric_setting($config, 'keepmonthly');
		$keep_weekly = get_asterisk_numeric_setting($config, 'keepweekly');
		$keep_daily = get_asterisk_numeric_setting($config, 'keepdaily');
		
		// Keep track of files that are currently being backed up
		$file_refs = array();
		// Keep track of ages of files (keyed with 'year', 'month', 'week'
		$file_age_groups = array(
			AGE_GROUP_YEAR => array(),
			AGE_GROUP_MONTH => array(),
			AGE_GROUP_WEEK => array(),
			AGE_GROUP_DAY => array()
		);
		
		while(false !== ($file = readdir($dh))) {
			
			if ($file != $backup_filename && preg_match($pattern, $file, $matches)) {
				
				$backup_date = $matches[1];
				$backup_year = intval(substr($backup_date, 0, 4));
				$backup_month = intval(substr($backup_date, 4, 2));
				$backup_day = intval(substr($backup_date, 6, 2));
				$backup_hour = intval(substr($backup_date, 8, 2));
				$backup_minute = intval(substr($backup_date, 10, 2));
				$backup_second = intval(substr($backup_date, 12, 2));
				$tm_backup_date = strtotime($backup_date);
				$backup_day_of_week = date('w', $tm_backup_date);
				
				$last_day_of_backup_month = date('t', $tm_backup_date);
				
				// The day of the month to keep monthly backups for
				$keep_day_of_month = get_numeric_setting($config, 'keepdayofmonth', $last_day_of_backup_month);
				if ($keep_day_of_month < 0 || $keep_day_of_month > $last_day_of_backup_month) $keep_day_of_month = $last_day_of_backup_month;
				
				// The day of the week to keep weekly backups for
				$keep_day_of_week = get_numeric_setting($config, 'keepdayofweek', 0);
				if ($keep_day_of_week < 0 || $keep_day_of_week > 6) $keep_day_of_week = 0;
				
				$tm_backup_date = strtotime($backup_date);
				$now = time();
				$age_seconds = $now - $tm_backup_date;
				
				$age_years = 0;
				$age_months = -1;
				$current = $tm_backup_date;
				while ($current < $now) {
					$age_months ++;
					$current = strtotime('+1 month', $current);
				}
				#$age_months = ( (intval(date('Y')) - $backup_year) * 12 ) + (intval(date('m')) - $backup_month);
				
				$age_days = floor(($age_seconds) / (60 * 60 * 24));
				$age_weeks = floor($age_days / 7);
				$age_years = floor($age_days / 365);
				
				if (!isset($file_refs[$file])) $file_refs[$file] = 0;
				
				// Setup age groups and values that we need to iterate over to check whether this file should be kept
				$age_groups = array(
					AGE_GROUP_YEAR => $age_years,
					AGE_GROUP_MONTH => $age_months,
					AGE_GROUP_WEEK => $age_weeks,
					AGE_GROUP_DAY => $age_days
				);
				
				// Look at each age group
				foreach($age_groups as $age_group => $age_val) {
					
					$add_ref = false;
					if (isset($file_age_groups[$age_group][$age_val])) {
						
						if ($age_days > $file_age_groups[$age_group][$age_val]['age_days']) {
							$old_file = $file_age_groups[$age_group][$age_val]['file'];
							// Decrease reference count for old file
							$file_refs[$old_file] --;
							$add_ref = true;
						}
						
					} else {
						$add_ref = true;
					}
					
					if ($add_ref) {
						$file_age_groups[$age_group][$age_val] = array(
							'file' => $file,
							'age_days' => $age_days
						);
						$file_refs[$file] ++;
					}
					
				}
				
				
				
				
			}
		}
		
		// Close out directory handle
		closedir($dh);
		
		// Setup initial structure for how to process archived files
		$archived_backups = array();
		foreach($file_refs as $file=>$count) {
			$archived_backups[$file] = array(
				'start_count' => $count, // The count at the start of processing
				'count' => $count, // The count after processing
				'kept_reasons' => array() // The reasons the file was kept
			);
		}
		
		// Setup groups to be processed and their $max_age values
		$age_groups = array(
			AGE_GROUP_YEAR => $keep_yearly,
			AGE_GROUP_MONTH => $keep_monthly,
			AGE_GROUP_WEEK => $keep_weekly,
			AGE_GROUP_DAY => $keep_daily
		);
		
		// Process each age group and associated files
		foreach($age_groups as $age_group => $max_age) {
			
			// If the specific age group is not a defined key then skip this iteration
			if (!isset($file_age_groups[$age_group])) continue;
			
			$ages = $file_age_groups[$age_group];
			
			foreach($ages as $age => $file_info) {
				
				$file = $file_info['file'];
				
				/*
				 * Do not keep files where the $max_age is not defined
				 * Keep files that are less than $max_age
				 * Keep files with any non-numeric value (the convention should be to use an asterisk "*")
				 */
				if ( 
					empty($max_age) || 
					(is_numeric($max_age) && $age > $max_age)
				) {
					
					$archived_backups[$file]['count'] --;
					
				} else {
					
					$archived_backups[$file]['kept_reasons'][] = $age_group;
					
				}
				
			}
			
		}
		
		foreach($archived_backups as $file => $file_info) {
			
			if ($file_info['count'] == 0) {
				
				if (!$is_debugging && !$keep) unlink($file);
				
			} else {
				echo 'Kept: ' . $file . ' because: ' . implode(', ', $file_info['kept_reasons']);
				echo PHP_EOL;
			}
			
		}
		
	}

}
/**
 * Required functions
 **/

function display_help($config_help) {
	$longest_name = 0;
	$max_desc_len = 50;
	$gutter = 2;
	foreach($config_help as $setting => $info) {
		$len = strlen($setting);
		if ($len > $longest_name) $longest_name = $len;
	}
	$name_col_len = $longest_name + $gutter;
	
	echo 'Create a configuration file in the ini format, where each section represents a single database backup to perform.  A [Global] section can be created to set settings that will be applied to all individual backups.' . PHP_EOL;
	echo 'Example: dbbackup.conf' . PHP_EOL;
	echo '[Global]' . PHP_EOL;
	echo 'backuppath = /path/to/backupdir' . PHP_EOL;
	echo '[MyDatabase]' . PHP_EOL;
	echo 'database = dbname' . PHP_EOL;
	echo 'username = username' . PHP_EOL;
	echo 'password = secret' . PHP_EOL;
	echo PHP_EOL;
	echo 'Any setting can be accessed by using dollar sign variables, e.g. $username.  Possible values are:' . PHP_EOL;
	
	foreach($config_help as $setting => $info) {
		// Values
		$desc_words = explode(' ', $info['description']);
		$t_desc = '';
		$desc = '';
		foreach($desc_words as $word) {
			$add_space = !empty($t_desc);
			$t_desc .= ($add_space?' ':'') . $word;
			if (strlen($t_desc) > $max_desc_len) {
				$desc .= PHP_EOL . str_repeat(' ', $name_col_len);
				$t_desc = $word;
			} else {
				
				if ($add_space) $desc .= ' ';
			}
			
			$desc .= $word;
		}
		
		$default = (!isset($info['default']) || null === $info['default'] ? 'None' : $info['default']);
		// Display
		echo str_pad($setting, $name_col_len);
		echo $desc . PHP_EOL;
		echo str_repeat(' ', $name_col_len) . 'Default: ' . $default . PHP_EOL;
	}
}
function get_raw_setting(array $settings, $key, $default=null) {
	return (isset($settings[$key])) ? $settings[$key] : $default;
}
function get_setting(array $settings, $key, $default=null) {
	$value = get_raw_setting($settings, $key, $default);

	if (preg_match_all('/\$([a-zA-Z]+[a-zA-Z0-9_\-]*)/', $value, $matches)) {
		
		for($i=0, $j=count($matches[1]); $i < $j; $i++) {
			$sub_value = get_setting($settings, $matches[1][$i]);
			
			if (null === $sub_value) throw new RuntimeException('Unable to lookup value for ' . $matches[0][$i]);
			$value = str_replace($matches[0][$i], $sub_value, $value);

		}
		
	}
	return $value;
}
function get_numeric_setting(array $settings, $key, $default=null) {
	$value = get_setting($settings, $key, $default);
	if (!is_numeric($value)) $value = $default;
	return $value;
}
function get_asterisk_numeric_setting(array $settings, $key, $default=null) {
	$value = get_setting($settings, $key, $default);
	if (!is_numeric($value) && $value != '*') $value = $default;
	return $value;
}
function calculate_config($settings) {

	foreach($settings as $key => $value) {
		$value = get_setting($settings, $key);
		$settings[$key] = $value;
	}
	return $settings;
}
