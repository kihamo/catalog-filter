<?php
class YandexMarketParser
{
  const BASE_URL = 'http://mobile.market.yandex.net/';
  private $defaultParams = array(
    'uuid'     => '5f8def90c4d64c8497205ec84cbd8e57',
    'operator' => 2,                                   // Мегафон
  );

  public function __construct()
  {

  }

  public function import()
  {

  }

  private function request($url, array $params = array())
  {
    $params += $this->defaultParams;


    var_dump($params);
    die();

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $response = curl_exec($ch);

    // TODO нас забанили

    curl_close($ch);
  }
}