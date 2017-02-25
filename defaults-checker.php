<?php

class mdc {
    
    public static function check($text) {
        $variables = mdc::parseVariables($text);
        $version = mdc::getMysqlVersion($variables);
        $os = mdc::getOs($variables);
        $platform = mdc::getPlatform($variables);
        if (!empty($version) && !empty($os) && !empty($platform)) {
            $variables = mdc::checkDefaults($variables, $version, $os, $platform);
        }
        return (object) array('version' => $version, 'os' => $os, 'platform' => $platform, 'variables' => $variables);
    }
    
    private static function parseVariables($text) {
        preg_match_all('/^(\| )?([a-z0-9_]+)[, \\t\\|]+(.*?)$/m', $text, $match);
        $variables = array();
        for ($i = 0; $i < count($match[0]); $i++) {
            $name = $match[2][$i];
            $variables[$name] = new stdClass;
            $variables[$name]->value = preg_replace('/[\\t ]+\\|$/', '', trim($match[3][$i]));
        }
        return $variables;
    }
    
	private static function getMysqlVersion($variables) {
        if (!isset($variables['version'])) {
            return null;
        }
		$version = explode('-', $variables['version']->value);
		$version = $version[0];
		$version = preg_split('/(\.|\-|(?<=\d)(?=[a-z]+))/', $version);
		if (!mdc::isValidInt($version[0]) || !mdc::isValidInt($version[1]) || !mdc::isValidInt($version[2])) {
			return null;
		}
		return $version;
	}
	
	private static function getOs($variables) {
        if (!isset($variables['version_compile_os'])) {
            return null;
        }
		$os = strtolower($variables['version_compile_os']->value);
		if (strpos($os, 'linux') !== false) {
			return 'Linux';
		}
		elseif (strpos($os, 'win') !== false) {
			return 'Windows';
		}
		return null;
	}
	
	private static function getPlatform($variables) {
        if (!isset($variables['version_compile_machine']) || !isset($variables['version_compile_os'])) {
            return null;
        }
		if ((strpos($variables['version_compile_machine']->value, '64') !== false) || (strpos($variables['version_compile_os']->value, '64') !== false)) {
			return '64-bit';
		} else {
            return '32-bit';
        }
	}
    
	private static function checkDefaults($variables, $version, $os, $platform) {
        $defaults = mdc::loadDefaults($version[0].'.'.$version[1]);
		foreach ($variables as $key => &$value) {
			if (!isset($defaults[$key])) {
				$value->result = 'unknown variable or unknown default value';
                continue;
			}
            $default = mdc::findDefault($defaults, $version, $os, $platform, $key);
            if ($default == null) {
                $value->result = 'unknown variable or unknown default value';
                continue;
            }
            if ($default->autosized == 1) {
                switch ($key) {
                    case 'back_log':
                        if (isset($variables['max_connections'])) {
                            $default->value = min(50 + floor($variables['max_connections']->value / 5), 900);
                        }
                    break;
                    case 'general_log_file':
                        if (isset($variables['datadir']) && isset($variables['hostname'])) {
                            $default->value = $variables['datadir']->value.$variables['hostname']->value.'.log';
                        }
                    break;
                    case 'host_cache_size':
                        if (isset($variables['max_connections'])) {
                            $default->value = min(128 + min($variables['max_connections']->value, 500) + max(0, floor(($variables['max_connections']->value - 500) / 20)), 2000);
                        }
                    break;
                    case 'innodb_buffer_pool_instances':
                        if (isset($variables['innodb_buffer_pool_size'])) {
                            if (($os == 'Windows') && ($platform == '32-bit')) {
                                $default->value = ($variables['innodb_buffer_pool_size']->value >= 1.3 * 1024 * 1024 * 1024) ? ceil($variables['innodb_buffer_pool_size']->value / (128 * 1024 * 1024)) : 1;
                            }
                            else {
                                $default->value = ($variables['innodb_buffer_pool_size']->value >= 1024 * 1024 * 1024) ? 8 : 1;
                            }
                        }
                    break;
                    case 'innodb_data_home_dir':
                        if (isset($variables['datadir'])) {
                            $default->value = $variables['datadir']->value;
                        }
                    break;
                    case 'innodb_io_capacity_max':
                        if (isset($variables['innodb_io_capacity'])) {
                            $default->value = max($variables['innodb_io_capacity']->value * 2, 2000);
                        }
                    break;
                    case 'innodb_open_files':
                        if (isset($variables['innodb_file_per_table']) && isset($variables['table_open_cache'])) {
                            if (!is_true($variables['innodb_file_per_table']->value)) {
                                $default->value = 300;
                            }
                            else {
                                $default->value = max($variables['table_open_cache']->value, 300);
                            }
                        }
                    break;
                    case 'log_bin_basename':
                        if (isset($variables['log_bin']) && is_true($variables['log_bin']->value) && isset($variables['datadir']) && isset($variables['hostname'])) {
                            $default->value = $variables['datadir']->value.$variables['hostname']->value.'-bin';
                        }
                        elseif (isset($variables['log_bin']) && is_false($variables['log_bin']->value)) {
                            $default->value = '';
                        }
                    break;
                    case 'log_bin_index':
                        if (isset($variables['log_bin']) && is_false($variables['log_bin']->value)) {
                            $default->value = '';
                        }
                    break;
                    case 'log_error':
                        if (isset($variables['datadir']) && isset($variables['hostname'])) {
                            $default->value = $variables['datadir']->value.$variables['hostname']->value.'.err';
                        }
                    break;
                    case 'open_files_limit':
                        if (isset($variables['max_connections']) && isset($variables['table_open_cache'])) {
                            $default->value = max(10 + $variables['max_connections']->value + ($variables['table_open_cache']->value * 2), $variables['max_connections']->value * 5, 5000);
                        }
                    break;
                    case 'pid_file':
                        if (isset($variables['datadir']) && isset($variables['hostname'])) {
                            $default->value = $variables['datadir']->value.$variables['hostname']->value.'.pid';
                        }
                    break;
                    case 'plugin_dir':
                        if (isset($variables['basedir'])) {
                            if ($os == 'Windows') {
                                $default->value = str_replace('/', '\\', $variables['basedir']->value).'lib\\plugin\\';
                            } else {
                                // TODO 5.5.5
                                $default->value = $variables['basedir']->value.'lib/plugin/';
                            }
                        }
                    break;
                    case 'relay_log_basename':
                        if (isset($variables['relay_log']) && is_true($variables['relay_log']->value) && isset($variables['datadir']) && isset($variables['hostname'])) {
                            $default->value = $variables['datadir']->value.$variables['hostname']->value.'-relay-bin';
                        }
                        elseif (isset($variables['relay_log']) && (is_false($variables['relay_log']->value) || ($variables['relay_log']->value == ''))) {
                            $default->value = '';
                        }
                    break;
                    case 'relay_log_index':
                        if (!isset($variables['relay_log']) || ($variables['relay_log']->value == '')) {
                            $default->value = '';
                        } elseif (isset($variables['datadir']) && isset($variables['hostname'])) {
                            $default->value = $variables['datadir']->value.$variables['hostname']->value.'-relay-bin.index';
                        }
                    break;
                    case 'slow_query_log_file':
                        if (isset($variables['datadir']) && isset($variables['hostname'])) {
                            $default->value = $variables['datadir']->value.$variables['hostname']->value.'-slow.log';
                        }
                    break;
                    case 'table_definition_cache':
                        if (isset($variables['table_open_cache'])) {
                            $default->value = min(400 + floor($variables['table_open_cache']->value / 2), 2000);
                        }
                    break;
                    case 'thread_cache_size':
                        if (isset($variables['max_connections'])) {
                            $default->value = min(8 + floor($variables['max_connections']->value / 100), 100);
                        }
                    break;
                }
            }
            if ((($default->autosized != 1) || ($default->value !== null)) && (strtolower($default->value) == strtolower($value->value))) {
                $value->result = 'ok';
            } elseif (($default->type == 'boolean') && ((mdc::isTrue($default->value) && mdc::isTrue($value->value)) || (mdc::isFalse($default->value) && mdc::isFalse($value->value)))) {
                $value->result = 'ok';
            } elseif ((($default->type == 'string') || ($default->type == 'directory name') || ($default->type == 'file name')) && ($default->value == 'NULL') && ($value->value == '')) {
                $value->result = 'ok';
            } else {
                $value->result = 'ko';
            }
            $value->default = $default;
		}
		return $variables;
	}
    
	private static function findDefault($defaults, $version, $os, $platform, $variable) {
        if (empty($defaults[$variable])) {
            return null;
        }
        $default = null;
        foreach ($defaults[$variable] as $candidate) {
            if ((($candidate->os == 'all') || ($candidate->os == $os)) && (($candidate->platform == 'all') || ($candidate->platform == $platform)) && ($candidate->version_c <= $version[2]) && ($candidate->version_d <= $version[3])) {
                $default = $candidate;
            }
        }
        return $default;
    }
    
    private static function loadDefaults($majorVersion) {
        $defaults = array();
        if (!preg_match('/^[0-9]\\.[0-9]$/', $majorVersion)) {
            return $defaults;
        }
        if (($handle = fopen(__DIR__.'/variables_mysql_'.$majorVersion.'.csv', 'r')) !== false) {
            while (($data = fgetcsv($handle, 0 , ',', '"', '"')) !== false) {
                if (!isset($defaults[$data[0]])) {
                    $defaults[$data[0]] = array();
                }
                $defaults[$data[0]][] = (object) array(
                    'version_c' => $data[1],
                    'version_d' => $data[2],
                    'os' => $data[3],
                    'platform' => $data[4],
                    'removed' => $data[5],
                    'autosized' => $data[6],
                    'type' => $data[7],
                    'unit' => $data[8],
                    'value' => $data[9]
                );
            }
            fclose($handle);
        }
        return $defaults;
    }
    
    private static function isValidInt($value) {
        return preg_match('/^[0-9]+$/', $value);
    }
    
    private static function isTrue($value) {
        return in_array(strtolower($value), array('1', 'on', 'true', 'yes'));
    }
    
    private static function isFalse($value) {
        return in_array(strtolower($value), array('0', 'off', 'false', 'no'));
    }
    
}

?>