<?php

namespace Catalog\Task;

use Catalog\Application;
use Catalog\Config;

class ImportYandexMarket implements TaskInterface
{
  /**
   * Базовый URL api
   *
   * @var string
   */
  const BASE_URL = 'http://mobile.market.yandex.net/market/content/v1';

  /**
   * Параметры, которые присутствуют во всех запросах к api
   * Данные получены реверс-инжинирингом мобильного приложения,
   * в любой момент может взорваться
   *
   * @var array
   */
  private $defaultParams = [
    'uuid'           => '5f8def90c4d64c8497205ec84cbd8e57',
    'operator'       => 1,
    'geo_id'         => 213,
    'lac'            => 5032,
    'cellid'         => 49667,
    'countrycode'    => 250,
    'signalstrength' => 60,
    'wifinetworks'   => '', // ай-яй-яй, Яндекс палит нас по wi-fi сетям
    'clid'           => 1
  ];

  private $attributesByName = [];
  private $attributesByHash = [];
  private $optionsByName = [];
  private $optionsByHash = [];

  /**
   * @var Application
   */
  private $application;

  public function run(Application $application)
  {
    $this->application = $application;

    $url = 'filter/' . Config::get('yandex_market/category_id') . '.xml';
    $params = [
      'page'  => 1,
      'count' => Config::get('yandex_market/category_max_items'),
      'sort'  => 'popularity'
    ];

    $xml = $this->request($url, $params);
    $pages = ceil($xml['total'] / $xml['count']);

    $maxPages = Config::get('yandex_market/category_max_pages', 0);
    if($maxPages && $pages > $maxPages)
    {
      $pages = $maxPages;
    }

    do {
      if($params['page'] > 1)
      {
        $xml = $this->request($url, $params);
      }

      $products = [];
      foreach($xml->xpath('.//model') as $data)
      {
        $product = [
          'product_id'          => (int) $data['id'],
          'product_name'        => trim($data->name),
          'product_description' => trim($data->description),
          'attributes'          => []
        ];

        $detailsXml = $this->request('model/' . $data['id'] . '/details.xml');
        foreach($detailsXml->block as $block)
        {
          foreach($block->param as $param)
          {
            $product['attributes'][trim($param['name'])] = trim($param['value']);
          }
        }

        $products[] = $product;
      }

      $this->addProducts($products);

      $params['page']++;
    }
    while($params['page'] <= $pages);
  }

  /**
   * Запрос к api маркета и парсинг xml из ответа
   *
   * @param string $url URL запроса
   * @param array $params Параметры запроса
   * @return \SimpleXMLElement
   * @throws \RuntimeException
   */
  private function request($url, array $params = [])
  {
    $params += $this->defaultParams;
    $url = self::BASE_URL . '/' . $url . '?' . http_build_query($params);
    $header = [
      'User-Agent: Yandex.Market/0.1 (Android/4.2.2; Galaxy Nexus/IMM76B)',
      'Accept: text/xml'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $header);

    $attempts = 0;
    $maxAttempts = Config::get('yandex_market/max_attempts');
    $sleepTime = Config::get('yandex_market/sleep_time');

    do {
      echo 'Parse url: ', $url, "\n";

      $response = curl_exec($ch);

      if(curl_errno($ch))
      {
        throw new \RuntimeException(
          'Request error: ' . curl_error($ch) . ' url: ' . $url
        );
      }

      $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

      if ($responseCode >= 500)
      {
        sleep($sleepTime);
      }
      else if ($responseCode >= 300)
      {
        throw new \RuntimeException(
          'Request error: response code is ' . $responseCode . ' url: ' . $url,
          $responseCode
        );
      }
      else
      {
        break;
      }

      $attempts++;
    }
    while($attempts < $maxAttempts);

    curl_close($ch);

    return simplexml_load_string($response);
  }

  /**
   * Пакетное добавление записей в базу данных
   *
   * @param array $objects
   * @param $table
   * @param $primaryKey
   * @return array
   * @throws \Exception
   */
  private function bulkInsert(array $objects, $table, $primaryKey = null)
  {
    $items = array_values($objects);
    $firstObject = current($items);
    $columns = array_keys(get_object_vars($firstObject));

    $sql = 'INSERT INTO ' . $table . ' ('
         . implode(',', $columns)
         .') VALUES ';
    $bindParams =
    $ids = [];

    $connection = $this->application->getConnect();
    $connection->beginTransaction();

    $fill = '(' . implode(',', array_fill(0, count($columns), '?')) . '),';
    foreach($items as $object)
    {
      $sql .= $fill;

      if($primaryKey && isset($object->$primaryKey))
      {
        $ids[spl_object_hash($object)] = (int) $object->$primaryKey;
      }

      $object = get_object_vars($object);
      $bindParams = array_merge($bindParams, array_values($object));
    }

    $sql = rtrim($sql, ',');

    try
    {
      $stmt = $connection->prepare($sql);
      $stmt->execute($bindParams);

      if(!$ids && $primaryKey)
      {
        $result = $connection->query(
            'SELECT ' . $primaryKey . ' AS id '
          . 'FROM ' . $table
          . ' WHERE ' . $primaryKey . ' >= LAST_INSERT_ID()'
        );

        foreach ($result->fetchAll() as $key => $row)
        {
          $ids[spl_object_hash($items[$key])] = (int) $row['id'];
        }
      }

      $connection->commit();
    }
    catch (\Exception $e)
    {
      $connection->rollback();
      throw $e;
    }

    return $ids;
  }

  /**
   * Добавление продуктов в базу данных
   *
   * @param array $items
   */
  private function addProducts(array $items)
  {
    $products =
    $attributes =
    $options =
    $values = [];

    foreach($items as $value)
    {
      $product = (object) $value;
      unset($product->attributes);

      $productHash = spl_object_hash($product);
      $products[$productHash] = $product;

      foreach($value['attributes'] as $attributeName => $optionName)
      {
        // attribute
        if(isset($this->attributesByName[$attributeName]))
        {
          $attribute = $this->attributesByName[$attributeName];
        }
        else
        {
          $attribute = (object) [
            'attribute_name' => $attributeName
          ];

          $attributes[] =
          $this->attributesByName[$attributeName] = $attribute;
        }

        $attributeHash = spl_object_hash($attribute);
        $this->attributesByHash[$attributeHash] = $attribute;

        // option
        $cacheKey = $attributeHash . '/' . $optionName;
        if(isset($this->optionsByName[$cacheKey]))
        {
          $option = $this->optionsByName[$cacheKey];
        }
        else
        {
          $option = (object) [
            'option_name'  => $optionName,
            'attribute_id' => isset($attribute->attribute_id) ?
                              $attribute->attribute_id :
                              spl_object_hash($attribute)
          ];

          $options[] =
          $this->optionsByName[$cacheKey] = $option;
        }

        $optionHash = spl_object_hash($option);
        $this->optionsByHash[$optionHash] = $option;

        // value
        $values[] = (object) [
          'option_id'  => $optionHash,
          'product_id' => $productHash
        ];
      }
    }

    if($attributes)
    {
      $attributesIds = $this->bulkInsert($attributes, 'attribute', 'attribute_id');

      foreach($attributesIds as $hash => $id)
      {
        $this->attributesByHash[$hash]->attribute_id = $id;
      }
    }

    // option
    if($options)
    {
      foreach($options as $key => $object)
      {
        if(!is_int($options[$key]->attribute_id))
        {
          $options[$key]->attribute_id = $this->attributesByHash[$object->attribute_id]->attribute_id;
        }
      }

      $optionsIds = $this->bulkInsert($options, 'attribute_option', 'option_id');

      foreach($optionsIds as $hash => $id)
      {
        $this->optionsByHash[$hash]->option_id = $id;
      }
    }

    // product
    $productIds = $this->bulkInsert($products, 'product', 'product_id');

    // product attribute option
    foreach($values as $key => $object)
    {
      $values[$key]->option_id = $this->optionsByHash[$object->option_id]->option_id;
      $values[$key]->product_id = $productIds[$object->product_id];
    }

    $this->bulkInsert($values, 'product_attribute_value');
  }
}