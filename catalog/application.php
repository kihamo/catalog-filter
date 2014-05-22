<?php

namespace Catalog;

use Catalog\Task\TaskInterface;

class Application
{
  private $connect;

  public function __construct()
  {
    set_include_path(
      get_include_path() . PATH_SEPARATOR
      . dirname(__FILE__) . DIRECTORY_SEPARATOR
    );
    spl_autoload_extensions('.php');
    spl_autoload_register();
  }

  /**
   * Запуск приложения
   *
   * @throws \InvalidArgumentException
   * @throws \Exception
   */
  public function run()
  {
    // из терминала
    if (PHP_SAPI == 'cli')
    {
      $options = getopt('t:');
      if (!$options)
      {
        throw new \InvalidArgumentException('Specify task name to execute');
      }

      $this->runTask($options['t']);
      return;
    }

    // web
    $path = explode('/', trim(isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : '', '/'));
    $path = array_filter($path);

    $action = isset($path[0]) ? strtolower($path[0]) : 'index';
    if(in_array($action, ['filter', 'search']) && !$this->isXmlHttpRequest())
    {
      $action = 'index';
    }

    $method = 'execute' . ucfirst($action);

    try
    {
      if (!is_callable([$this, $method]))
      {
        throw new \Exception('Page not found', 404);
      }

      $this->$method();
    }
    catch(\Exception $e)
    {
      $this->executeError($e);
    }
  }

  /**
   * Запуск терминальной задачи
   *
   * @param string $task
   * @throws \LogicException
   * @throws \BadMethodCallException
   */
  public function runTask($task)
  {
    $className = 'Catalog\Task\\' . $task;

    try
    {
      error_reporting(E_ERROR | E_PARSE);

      try
      {
        $taskInstance = null;
        if (class_exists($className))
        {
          $taskInstance = new $className;
        }
      }
      catch(\LogicException $e)
      {
        throw new \BadMethodCallException(
          'Class "' . $className . '" not found', $e->getCode(), $e
        );
      }

      if (!($taskInstance instanceof TaskInterface))
      {
        throw new \LogicException('Task class "' . $className .
                                  '" is not an instance of TaskInterface');
      }

      $taskInstance->run($this);
    }
    catch(\Exception $e)
    {
      echo 'Exception "', trim($e->getMessage()), '" in file ',
           $e->getFile(), ':', $e->getLine(), "\n";
    }
  }

  /**
   * Коннект к базе данных
   *
   * @return \PDO
   * @throws \RuntimeException
   */
  public function getConnect()
  {
    if(null === $this->connect)
    {
      try
      {
        $dsn = 'mysql:host=' . Config::get('database/host', 'localhost')
             . ';port=' . Config::get('database/port', 3306)
             . ';dbname=' . Config::get('database/dbname')
             . ';charset=UTF8';

        $this->connect = new \PDO(
          $dsn, Config::get('database/user'), Config::get('database/password'),
          [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC
          ]
        );
      }
      catch (\PDOException $e)
      {
        throw new \RuntimeException(
          'Database connect error: ' . $e->getMessage(), $e->getCode(), $e
        );
      }
    }

    return $this->connect;
  }

  /**
   * Проверяет является ли запрос аяксовым
   *
   * @return bool
   */
  private function isXmlHttpRequest()
  {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
      && strcasecmp($_SERVER['HTTP_X_REQUESTED_WITH'], 'XMLHttpRequest') === 0;
  }

  /**
   * Рендеринг шаблона
   *
   * @param string $template
   * @param array $context
   */
  private function render($template, array $context = [])
  {
    $file = 'templates/' . $template . '.php';

    if(is_readable($file))
    {
      header('Content-Type: text/html; charset=utf-8');
      extract($context);

      include_once $file;
    }
  }

  /**
   * Обработка короткой записи параметров фильтра
   *
   * @return array
   */
  private function getFilterValues()
  {
    $options = [];

    $query = strstr($_SERVER['REQUEST_URI'], '?');
    if($query)
    {
      foreach(explode('&', substr($query, 1)) as $chunk)
      {
        list($param, $value) = explode('=', $chunk);

        if(is_numeric($param) && is_numeric($value))
        {
          if(!isset($options[$param]))
          {
            $options[$param] = [];
          }

          $options[$param][] = (int) $value;
        }
      }
    }

    return $options;
  }

  /**
   * Отправка ответа в формате JSON
   *
   * @param object|array $response
   */
  private function sendJson($response)
  {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response);
  }

  private function executeError(\Exception $e)
  {
    if ($e->getCode() == 404)
    {
      header('HTTP/1.1 404 Not Found');
    }
    else
    {
      header('HTTP/1.1 500 Internal Server Error');
    }

    if ($this->isXmlHttpRequest())
    {
      $this->sendJson(['error' => $e->getMessage()]);
    }
    else
    {
      $this->render('error', ['error' => $e]);
    }
  }

  /**
   * Главная страница
   */
  private function executeIndex()
  {
    $attributes = [];

    $sql = 'SELECT attribute_id AS a_id, attribute_name AS a_name,'
         .        'option_id AS o_id, option_name AS o_name '
         . 'FROM attribute a '
         . 'LEFT JOIN attribute_option o USING(attribute_id) '
         . 'LEFT JOIN product_attribute_value v USING(option_id) '
         . 'WHERE v.product_id IS NOT NULL '
         . 'GROUP BY option_id '
         . 'ORDER BY a.attribute_id';

    $result = $this->getConnect()->query($sql);
    foreach($result->fetchAll() as $row)
    {
      if(!isset($attributes[$row['a_name']]))
      {
        $attributes[$row['a_name']] = [];
      }

      $attributes[$row['a_name']][] = $row;
    }

    $this->render('index', compact('attributes'));
  }

  /**
   * Результаты фильтрации
   */
  private function executeFilter()
  {
    $filters = $this->getFilterValues();

    if(!$filters)
    {
      $sql = 'SELECT option_id AS id, COUNT(v.product_id) AS count '
           . 'FROM attribute_option o '
           . 'LEFT JOIN product_attribute_value v USING(option_id) '
           . 'GROUP BY o.option_id';
    }
    else
    {
      $sql = 'SELECT option_id AS id, COUNT(p.product_id) AS count '
           . 'FROM attribute_option o '
           . 'LEFT JOIN product_attribute_value v USING(option_id) '
           . 'LEFT JOIN ('
           .   'SELECT product_id '
           .   'FROM product_attribute_value sv '
           .   'LEFT JOIN attribute_option so USING(option_id) '
           .   'WHERE 1=0 ';

      foreach($filters as $attributeId => $options)
      {
        $sql .= 'OR (sv.option_id IN (' . implode(',', $options)
             .  ') AND so.attribute_id = ' . $attributeId . ') ';
      }

      $sql .=  'GROUP BY product_id '
           .   'HAVING COUNT(attribute_id) = ' . count($filters)
           . ') p USING(product_id) '
           . 'GROUP BY o.option_id';
    }

    $response = [
      'total'   => 0,
      'options' => []
    ];

    $result = $this->getConnect()->query($sql);
    while($row = $result->fetch())
    {
      $response['options'][$row['id']] = $row['count'] > 0;
      $response['total'] = max($response['total'], $row['count']);
    }

    $this->sendJson($response);
  }

  /**
   * Результаты поиска
   */
  private function executeSearch()
  {
    $response = [
      'products' => []
    ];

    $filters = $this->getFilterValues();

    if(!$filters)
    {
      $sql = 'SELECT product_id AS id, product_name AS name, '
           . 'product_description AS description '
           . 'FROM product';
    }
    else
    {
      $sql = 'SELECT product_id AS id, product_name AS name, '
           .        'product_description AS description '
           . 'FROM product_attribute_value v '
           . 'LEFT JOIN attribute_option o USING(option_id) '
           . 'LEFT JOIN product p USING(product_id)'
           . 'WHERE 1=0 ';

      foreach($filters as $attributeId => $options)
      {
        $sql .= 'OR (v.option_id IN (' . implode(',', $options)
              . ') AND o.attribute_id = ' . $attributeId . ') ';
      }

      $sql .=  'GROUP BY v.product_id '
           .   'HAVING COUNT(attribute_id) = ' . count($filters);
    }

    $result = $this->getConnect()->query($sql);
    while($row = $result->fetch())
    {
      $response['products'][$row['id']] = [
        'name'        => $row['name'],
        'description' => $row['description']
      ];
    }

    $this->sendJson($response);
  }
}