<?php

namespace Catalog\Task;

use Catalog\Application;
use Catalog\Config;

class Install implements TaskInterface
{
  public function run(Application $application)
  {
    $this->checkSystemRequirements();
    $this->initDatabase();
  }

  private function resultDisplay($flag, $message)
  {
    echo str_pad($message, 74, '.'),
         str_pad($flag ? 'OK' : 'ERROR', 5, '.', STR_PAD_LEFT), "\n";
  }

  private function getConnect()
  {
    try
    {
      $dsn = 'mysql:host=' . Config::get('database/host', 'localhost')
           . ';port=' . Config::get('database/port', 3306)
           . ';charset=UTF8';

      return new \PDO(
        $dsn, Config::get('database/user'), Config::get('database/password'),
        [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
      );
    }
    catch (\PDOException $e)
    {
      return false;
    }
  }

  /**
   * Проверка версии php и необходимых расширений
   */
  private function checkSystemRequirements()
  {
    $this->resultDisplay(
             version_compare(phpversion(), '5.4.0', '>='),
             'PHP version is at least 5.4.0. Current is ' . phpversion()
           );

    foreach(['curl', 'SimpleXML', 'pdo'] as $extension)
    {
      $this->resultDisplay(
        extension_loaded($extension),
        'Install "' . $extension . '" extension'
      );
    }

    if(extension_loaded('pdo'))
    {
      $this->resultDisplay(
        in_array('mysql', \PDO::getAvailableDrivers()),
        'PDO mysql drivers installed'
      );
    }
  }

  /**
   * Инициализация базы данных
   */
  private function initDatabase()
  {
    $connect = $this->getConnect();
    $dbname = Config::get('database/dbname');

    $this->resultDisplay($connect, 'Connect to database');
    $this->resultDisplay($dbname !== null, 'Specify database name');

    if (!$connect || $dbname === null)
    {
      return;
    }

    $result = $connect->query('CREATE DATABASE IF NOT EXISTS ' . $dbname);
    $this->resultDisplay($result, 'Create database "' . $dbname . '"');

    $tables = [
      'attribute' => <<<SQL
        CREATE TABLE $dbname.attribute (
          attribute_id int(11) NOT NULL AUTO_INCREMENT,
          attribute_name varchar(100) DEFAULT NULL,
          PRIMARY KEY (attribute_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8

SQL
      ,'attribute_option' => <<<SQL
        CREATE TABLE $dbname.attribute_option (
          option_id int(11) NOT NULL AUTO_INCREMENT,
          option_name varchar(100) DEFAULT NULL,
          attribute_id int(11) DEFAULT NULL,
          PRIMARY KEY (option_id),
          KEY fk_attribute_option_attribute_idx (attribute_id),
          CONSTRAINT fk_attribute_option_attribute
            FOREIGN KEY (attribute_id)
            REFERENCES attribute (attribute_id)
            ON DELETE NO ACTION
            ON UPDATE NO ACTION
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8
SQL
      ,'product' => <<<SQL
        CREATE TABLE $dbname.product (
          product_id int(11) NOT NULL AUTO_INCREMENT,
          product_name varchar(50) DEFAULT NULL,
          product_description varchar(255) DEFAULT NULL,
          PRIMARY KEY (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8
SQL
      ,'product_attribute_value' => <<<SQL
        CREATE TABLE $dbname.product_attribute_value (
          product_id int(11) NOT NULL,
          option_id int(11) NOT NULL,
          PRIMARY KEY (option_id,product_id),
          KEY fk_product_attribute_value_product_idx (product_id),
          KEY fk_product_attribute_value_option_idx (option_id),
          CONSTRAINT fk_product_attribute_value_product
            FOREIGN KEY (product_id)
            REFERENCES product (product_id)
            ON DELETE NO ACTION
            ON UPDATE NO ACTION,
          CONSTRAINT fk_product_attribute_value_option
            FOREIGN KEY (option_id)
            REFERENCES attribute_option (option_id)
            ON DELETE NO ACTION
            ON UPDATE NO ACTION
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
SQL
    ];

    $connect->query('SET FOREIGN_KEY_CHECKS=0');
    $connect->query(
      'DROP TABLE IF EXISTS `' . $dbname . '`.`' .
      implode('`, `' . $dbname . '`.`', array_keys($tables)) . '`'
    );

    foreach($tables as $name => $sql)
    {
      $result = $connect->query($sql);
      $this->resultDisplay($result, 'Create table "' . $name . '"');
    }

    $connect->query('SET FOREIGN_KEY_CHECKS=1');
  }
}