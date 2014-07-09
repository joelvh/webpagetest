<?php

/******************************************************************************
* 
*   Export a result data set  in HTTP archive format:
*   http://groups.google.com/group/firebug-working-group/web/http-tracing---export-format
* 
******************************************************************************/

include 'common.inc';
require_once('page_data.inc');
include 'object_detail.inc';
require_once('lib/json.php');

// see if we are loading a single run or all of them
if( isset($testPath) ) {
    $pageData;
    if( isset($_REQUEST["run"]) && $_REQUEST["run"] ) {
        if (!strcasecmp($_REQUEST["run"],'median')) {
          $raw = loadAllPageData($testPath);
          $run = GetMedianRun($raw, $cached, $median_metric);
          if (!$run)
            $run = 1;
          unset($raw);
        }
        $pageData[$run] = array();
        if( isset($cached) )
            $pageData[$run][$cached] = loadPageRunData($testPath, $run, $cached);
        else
        {
            $pageData[$run][0] = loadPageRunData($testPath, $run, 0);
            $pageData[$run][1] = loadPageRunData($testPath, $run, 1);
        }
    }
    else
        $pageData = loadAllPageData($testPath);

    // build up the array
    $result = BuildResult($pageData);
    
    // spit it out as json
    $filename = '';
    if (@strlen($url))
    {
        $parts = parse_url($url);
        $filename = $parts['host'];
    }
    if (!strlen($filename))
        $filename = "pagetest";
    $filename .= ".$id.har";
    header("Content-disposition: attachment; filename=$filename");
    header('Content-type: application/json');

    // see if we need to wrap it in a JSONP callback
    if( isset($_REQUEST['callback']) && strlen($_REQUEST['callback']) )
        echo "{$_REQUEST['callback']}(";

    $json_encode_good = version_compare(phpversion(), '5.4.0') >= 0 ? true : false;
    $pretty_print = array_key_exists('pretty', $_REQUEST) && $_REQUEST['pretty'] ? true : false;
    if( array_key_exists('php', $_GET) && $_GET['php'] ) {
      if ($pretty_print && $json_encode_good)
        echo json_encode($result, JSON_PRETTY_PRINT);
      else
        echo json_encode($result);
    } elseif ($json_encode_good) {
      if ($pretty_print)
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
      else
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    } else {    
      $json = new Services_JSON();
      echo $json->encode($result);
    }

    if( isset($_REQUEST['callback']) && strlen($_REQUEST['callback']) )
        echo ");";
}

function msdate($mstimestamp)
{
    $timestamp = floor($mstimestamp);
    $milliseconds = round(($mstimestamp - $timestamp) * 1000);
    
    $date = gmdate('c', $timestamp);
    $msDate = substr($date, 0, 19) . '.' . sprintf('%03d', $milliseconds) . substr($date, 19);

    return $msDate;
}

/**
 * Time intervals can be UNKNOWN_TIME or a non-negative number of milliseconds.
 * Intervals that are set to UNKNOWN_TIME represent events that did not happen,
 * so their duration is 0ms.
 *
 * @param type $value
 * @return int The duration of $value
 */
function durationOfInterval($value) {
  if ($value == UNKNOWN_TIME) {
    return 0;
  }
  return (int)$value;
}

/**
* Build the data set
* 
* @param mixed $pageData
*/
function BuildResult(&$pageData)
{
    global $id;
    global $testPath;
    $result = array();
    $entries = array();
    
    $result['log'] = array();
    $result['log']['version'] = '1.1';
    $result['log']['creator'] = array(
        'name' => 'WebPagetest',
        'version' => '1.8'
        );
    $result['log']['pages'] = array();
    foreach ($pageData as $run => $pageRun) {
        foreach ($pageRun as $cached => $data) {
            $cached_text = '';
            if ($cached)
                $cached_text = '_Cached';
            if (!array_key_exists('browser', $result['log'])) {
                $result['log']['browser'] = array(
                    'name' => $data['browser_name'],
                    'version' => $data['browser_version']
                    );
            }
            $pd = array();
            $pd['startedDateTime'] = msdate($data['date']);
            $pd['title'] = "Run $run, ";
            if( $cached )
                $pd['title'] .= "Repeat View";
            else
                $pd['title'] .= "First View";
            $pd['title'] .= " for " . $data['URL'];
            $pd['id'] = "page_{$run}_{$cached}";
            $pd['pageTimings'] = array( 'onLoad' => $data['docTime'], 'onContentLoad' => -1, '_startRender' => $data['render'] );
            
            // add the pagespeed score
            $score = GetPageSpeedScore("$testPath/{$run}{$cached_text}_pagespeed.txt");
            if( strlen($score) )
                $pd['_pageSpeed'] = array( 'score' => $score );
            
            // dump all of our metrics into the har data as custom fields
            foreach($data as $name => $value) {
                if (!is_array($value))
                    $pd["_$name"] = $value;
            }
            
            // add the page-level ldata to the result
            $result['log']['pages'][] = $pd;
            
            // now add the object-level data to the result
            $secure = false;
            $haveLocations = false;
            $requests = getRequests($id, $testPath, $run, $cached, $secure, $haveLocations, false, true);
            foreach( $requests as &$r )
            {
                $entry = array();
                $entry['pageref'] = $pd['id'];
                $entry['startedDateTime'] = msdate((double)$data['date'] + ($r['load_start'] / 1000.0));
                $entry['time'] = $r['all_ms'];
                
                $request = array();
                $request['method'] = $r['method'];
                $protocol = ($r['is_secure']) ? 'https://' : 'http://';
                $request['url'] = $protocol . $r['host'] . $r['url'];
                $request['headersSize'] = -1;
                $request['bodySize'] = -1;
                $request['cookies'] = array();
                $request['headers'] = array();
                $ver = '';
                $headersSize = 0;
                if( isset($r['headers']) && isset($r['headers']['request']) ) {
                    foreach($r['headers']['request'] as &$header) {
                        $headersSize += strlen($header) + 2; // add 2 for the \r\n that is on the raw headers
                        $pos = strpos($header, ':');
                        if( $pos > 0 ) {
                            $name = trim(substr($header, 0, $pos));
                            $val = trim(substr($header, $pos + 1));
                            if( strlen($name) )
                                $request['headers'][] = array('name' => $name, 'value' => $val);

                            // parse out any cookies
                            if( !strcasecmp($name, 'cookie') ) {
                                $cookies = explode(';', $val);
                                foreach( $cookies as &$cookie ) {
                                    $pos = strpos($cookie, '=');
                                    if( $pos > 0 ) {
                                        $name = (string)trim(substr($cookie, 0, $pos));
                                        $val = (string)trim(substr($cookie, $pos + 1));
                                        if( strlen($name) )
                                            $request['cookies'][] = array('name' => $name, 'value' => $val);
                                    }
                                }
                            }
                        } else {
                            $pos = strpos($header, 'HTTP/');
                            if( $pos >= 0 )
                                $ver = (string)trim(substr($header, $pos + 5, 3));
                        }
                    }
                }
                if ($headersSize)
                  $request['headersSize'] = $headersSize;
                $request['httpVersion'] = $ver;

                $request['queryString'] = array();
                $parts = parse_url($request['url']);
                if( isset($parts['query']) )
                {
                    $qs = array();
                    parse_str($parts['query'], $qs);
                    foreach($qs as $name => $val)
                        $request['queryString'][] = array('name' => (string)$name, 'value' => (string)$val );
                }
                
                if( !strcasecmp(trim($request['method']), 'post') )
                {
                    $request['postData'] = array();
                    $request['postData']['mimeType'] = '';
                    $request['postData']['text'] = '';
                }
                
                $entry['request'] = $request;

                $response = array();
                $response['status'] = (int)$r['responseCode'];
                $response['statusText'] = '';
                $response['headersSize'] = -1;
                $response['bodySize'] = (int)$r['objectSize'];
                $response['headers'] = array();
                $ver = '';
                $loc = '';
                $headersSize = 0;
                if( isset($r['headers']) && isset($r['headers']['response']) ) {
                    foreach($r['headers']['response'] as &$header) {
                        $headersSize += strlen($header) + 2; // add 2 for the \r\n that is on the raw headers
                        $pos = strpos($header, ':');
                        if( $pos > 0 ) {
                            $name = (string)trim(substr($header, 0, $pos));
                            $val = (string)trim(substr($header, $pos + 1));
                            if( strlen($name) )
                                $response['headers'][] = array('name' => $name, 'value' => $val);
                            
                            if( !strcasecmp($name, 'location') )
                                $loc = (string)$val;
                        } else {
                            $pos = strpos($header, 'HTTP/');
                            if( $pos >= 0 )
                                $ver = (string)trim(substr($header, $pos + 5, 3));
                        }
                    }
                }
                if ($headersSize)
                  $response['headersSize'] = $headersSize;
                $response['httpVersion'] = $ver;
                $response['redirectURL'] = $loc;

                $response['content'] = array();
                $response['content']['size'] = (int)$r['objectSize'];
                if( isset($r['contentType']) && strlen($r['contentType']))
                    $response['content']['mimeType'] = (string)$r['contentType'];
                else
                    $response['content']['mimeType'] = '';
                
                // unsupported fields that are required
                $response['cookies'] = array();

                $entry['response'] = $response;
                
                $entry['cache'] = (object)array();
                
                $timings = array();
                $timings['blocked'] = -1;
                $timings['dns'] = (int)$r['dns_ms'];
                if( !$timings['dns'])
                    $timings['dns'] = -1;

                // HAR did not have an ssl time until version 1.2 .  For
                // backward compatibility, "connect" includes "ssl" time.
                // WepbageTest's internal representation does not assume any
                // overlap, so we must add our connect and ssl time to get the
                // connect time expected by HAR.
                $timings['connect'] = (durationOfInterval($r['connect_ms']) +
                                       durationOfInterval($r['ssl_ms']));
                if(!$timings['connect'])
                    $timings['connect'] = -1;

                $timings['ssl'] = (int)$r['ssl_ms'];
                if (!$timings['ssl'])
                    $timings['ssl'] = -1;

                // TODO(skerner): WebpageTest's data model has no way to
                // represent the difference between the states HAR calls
                // send (time required to send HTTP request to the server)
                // and wait (time spent waiting for a response from the server).
                // We lump both into "wait".  Issue 24* tracks this work.  When
                // it is resolved, read the real values for send and wait
                // instead of using the request's TTFB.
                // *: http://code.google.com/p/webpagetest/issues/detail?id=24
                $timings['send'] = 0;
                $timings['wait'] = (int)$r['ttfb_ms'];
                $timings['receive'] = (int)$r['download_ms'];

                $entry['timings'] = $timings;

                // The HAR spec defines time as the sum of the times in the
                // timings object, excluding any unknown (-1) values and ssl
                // time (which is included in "connect", for backward
                // compatibility with tools written before "ssl" was defined
                // in HAR version 1.2).
                $entry['time'] = 0;
                foreach ($timings as $timingKey => $duration) {
                    if ($timingKey != 'ssl' && $duration != UNKNOWN_TIME) {
                        $entry['time'] += $duration;
                    }
                }
                
                if (array_key_exists('custom_rules', $r)) {
                    $entry['_custom_rules'] = $r['custom_rules'];
                }

                // dump all of our metrics into the har data as custom fields
                foreach($r as $name => $value) {
                    if (!is_array($value))
                        $entry["_$name"] = $value;
                }
                
                // add it to the list of entries
                $entries[] = $entry;
            }
            
            // add the bodies to the requests
            if (array_key_exists('bodies', $_REQUEST) && $_REQUEST['bodies']) {
              $bodies_file = $testPath . '/' . $run . $cached_text . '_bodies.zip';
              if (is_file($bodies_file)) {
                  $zip = new ZipArchive;
                  if ($zip->open($bodies_file) === TRUE) {
                      for( $i = 0; $i < $zip->numFiles; $i++ ) {
                          $index = intval($zip->getNameIndex($i), 10) - 1;
                          if (array_key_exists($index, $entries))
                              $entries[$index]['response']['content']['text'] = $zip->getFromIndex($i);
                      }
                  }
              }
            }
        }
    }
    
    $result['log']['entries'] = $entries;
    
    return $result;
}
?>
