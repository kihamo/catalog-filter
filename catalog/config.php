<?php

namespace Catalog;

final class Config
{
  const CONFIG_FILE = 'config.ini';
  const PATH_DELIMITER = '/';

  private static $instance;
  private $config;

  private function __construct() {}
  final private function __clone() {}

  public static function getInstance()
  {
    if (null === self::$instance)
    {
      self::$instance = new self;
    }

    return self::$instance;
  }

  private function lazyLoadConfigFromFile()
  {
    if (null === $this->config)
    {
      $this->config = parse_ini_file(self::CONFIG_FILE, true);

      if($this->config === false)
      {
        throw new \UnexpectedValueException(
          'Error parse config file: ' . error_get_last()['message']
        );
      }
    }
  }

  public static function get($path, $default = null)
  {
    $instance = self::getInstance();
    $instance->lazyLoadConfigFromFile();

    $parts = explode(self::PATH_DELIMITER, trim($path, self::PATH_DELIMITER));
    $value = $instance->config;

    foreach ($parts as $part) {
      if (isset($value[$part])) {
        $value = $value[$part];
      }
      else {
        return $default;
      }
    }

    return $value;
  }
}