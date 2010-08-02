<?php

$version = '1.2';

if(isset($_GET['dbboon_version'])) {
  echo '{"version":"' . $version . '"}';
  exit;
}

function dbboon_parseHeaders($subject) {

  global $version;

  $subject = trim($subject);
  $parsed = Array();
  $len = strlen($subject);
  $position = $field = 0;
  $position = strpos($subject, "\r\n") + 2;

  while(isset($subject[$position])) {

    $nextC = strpos($subject, ':', $position);
    $fieldName = substr($subject, $position, ($nextC-$position));
    $position += strlen($fieldName) + 1;
    $fieldValue = NULL;

    while(1) {
      $nextCrlf = strpos($subject, "\r\n", $position - 1);
      if(FALSE === $nextCrlf) {
        $t = substr($subject, $position);
        $position = $len;
      } else {
        $t = substr($subject, $position, $nextCrlf-$position);
        $position += strlen($t) + 2;
      }

      $fieldValue .= $t;
      if(!isset($subject[$position]) || (' ' != $subject[$position] && "\t" != $subject[$position])) {
        break;
      }
    }

    $parsed[strtolower($fieldName)] = trim($fieldValue);
    if($position > $len) {
      echo '{"result":false,"error":{"code":4,"message":"Communication error, unable to contact proxy service.","version":"' . $version . '"}}';
      exit;
    }
  }
  return $parsed;
}

if(!function_exists('http_build_query')) {
  function http_build_query($data, $prefix = '', $sep = '', $key = '') {
    $ret = Array();
    foreach((array) $data as $k => $v) {
      if(is_int($k) && NULL != $prefix) {
        $k = urlencode($prefix . $k);
      }
      if(!empty($key) || $key === 0) {
        $k = $key . '[' . urlencode($k) . ']';
      }
      if(is_array($v) || is_object($v)) {
        array_push($ret, http_build_query($v, '', $sep, $k));
      } else {
        array_push($ret, $k . '=' . urlencode($v));
      }
    }
    if(empty($sep)) {
      $sep = '&';
    }
    return implode($sep, $ret);
  }
}

$host = 'dbexternalsubscriber.secureserver.net';
$get  = http_build_query($_GET);
$post = http_build_query($_POST);
$url = $get ? "?$get" : '';
$fp = fsockopen($host, 80, $errno, $errstr);

if($fp) {

  $payload  = "POST /embed/$url HTTP/1.1\r\n";
  $payload .= "Host: $host\r\n";
  $payload .= "Content-Length: " . strlen($post) . "\r\n";
  $payload .= "Content-Type: application/x-www-form-urlencoded\r\n";
  $payload .= "Connection: Close\r\n\r\n";
  $payload .= $post;

  fwrite($fp, $payload);

  $httpCode = NULL;
  $response = NULL;
  $timeout = time() + 15;

  do {
    while($line = fgets($fp)) {
      $response .= $line;
      if(!trim($line)) {
        break;
      }
    }
  } while($timeout > time() && NULL === $response);

  $headers = dbboon_parseHeaders($response);
  if(isset($headers['transfer-encoding']) && 'chunked' === $headers['transfer-encoding']) {
    do {
      $cSize = $read = hexdec(trim(fgets($fp)));
      while($read > 0) {
        $buff = fread($fp, $read);
        $read -= strlen($buff);
        $response .= $buff;
      }
      $response .= fgets($fp);
    } while($cSize > 0);
  } else {
    preg_match('/Content-Length:\s([0-9]+)\r\n/msi', $response, $match);
    if(!isset($match[1])) {
      echo '{"result":false,"error":{"code":3,"message":"Communication error, unable to contact proxy service.","version":"' . $version . '"}}';
      exit;
    } else {
      while($match[1] > 0) {
        $buff = fread($fp, $match[1]);
        $match[1] -= strlen($buff);
        $response .= $buff;
      }
    }
  }

  fclose($fp);

  if(!$pos = strpos($response, "\r\n\r\n")) {
    echo '{"result":false,"error":{"code":2,"message":"Communication error, unable to contact proxy service.","version":"' . $version . '"}}';
    exit;
  }

  echo substr($response, $pos + 4);

} else {
  echo '{"result":false,"error":{"code":1,"message":"Communication error, unable to contact proxy service.","version":"' . $version . '"}}';
  exit;
}