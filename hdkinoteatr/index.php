<?php
ini_set("log_errors", 1); ini_set("error_log", $_SERVER['SCRIPT_FILENAME'].".log"); ini_set('error_reporting', E_ALL); ini_set("display_errors", 0);

define("MINIMUM_QUERY_LENGTH", 2);
define("MAXIMUM_QUERY_LENGTH", 200);
define("FINDMODE_COMMALINKS" , 1);
define("FINDMODE_UNESCAPE"   , 2);
define("FINDMODE_NAMECASE"   , 4);
define("FINDMODE_TIME"       , 8);
define("FINDMODE_NUMBER"     ,16);
define("FINDMODE_DATETIME"   ,32);

define('ERROR_REPORTING_EMAIL', '');                     // email for reporting critical errors
define('ERROR_REPORTING_EMAIL_FROM', "api@hdkinoteatr"); // field "from" in e-mails
define('ERROR_REPORTING_EMAIL_INTERVAL', 48 * 60 * 60 ); // limit interval for e-mail send (maximum 1 mail in 48 hours)

$f3 = require('../f3/lib/base.php'); // Fat Free micro framework https://fatfreeframework.com
$f3->set('DEBUG', 0);

// Configuration
$f3->set('url_base'    , 'http://www.hdkinoteatr.com'); // base url for reconscruct image links
$f3->set('update_token', '<secret>');                   // secret token for scaning and init database
$f3->set('tvdb_api_key', '');                           // TVDB API key http://thetvdb.com/?tab=apiregister
$f3->set('db_username' , '<user>');                     // database username
$f3->set('db_password' , '<pass>');                     // database password
$f3->set('conn_string' , 'mysql:host=localhost;port=3306;dbname=hdkinoteatr'); // connection string

$f3->set('cache_dir'          , 'cache');            // cache directory
$f3->set('cache_max_size'     , 2000 * 1024 * 1024); // cache maximum size (in bytes)
$f3->set('checking_interval'  , 3600 * 1);           // intefval of checks site for updates (in seconds)
$f3->set('file_state'         , 'update.state');     // file with stored last_id and last_time data
$f3->set('maximum_pages_load' , 4);                  // maximum pages to load for searching last_id
$f3->set('cache_short_life'   , 43200);              // cache lifetime of the serials playlist = 12 hours (in seconds)
$f3->set('cache_episodes_life', 86400 * 2);          // cache lifetime by info about episodes of the show = 2 days (in seconds)
$f3->set('updating_flag_file' , 'updating.flag');    // flag file for signaling that updating is running

$f3->set('engtitle_in_title', 0 ); // search english title in title pattern
$f3->set('titles_separator', '/'); // separator in title for titles in other lang

// --------------------- API ROUTE --------------------------------------------

$f3->route('GET /', function() { header('Content-Type: text/html; charset=utf-8'); require("api.html"); }); // API docs

$f3->route('GET /categories', function($f3) { ListTable($f3, 'categories'); }); // Get categories
$f3->route('GET /countries' , function($f3) { ListTable($f3, 'countries' ); }); // Get countries
$f3->route('GET /tags'      , function($f3) { ListTable($f3, 'tags'      ); }); // Get tags

$f3->route('GET /videos'    , function($f3) { GetVideos($f3); }); // Get videos
$f3->route('GET /series'    , function($f3) { GetSeries($f3); }); // Get video series

$f3->route('GET /scanpage'  , function($f3) { ScanPage ($f3); }); // Scan selected page of www.hdkinoteatr.com
$f3->route('GET /initdb'    , function($f3) { InitDB   ($f3); }); // Create tables in database

$f3->run();

exit;

///////////////////////////////////////////////////////////////////////////////
// Loading and parse web page - searching blocks with video
function ScanPage($f3, $page=1, $last_id=0) {
  header("Content-Type: application/json");
  if (!$last_id) {
    $token = isset($_REQUEST['token']) ? $_REQUEST['token'] : "";
    if ($token=="<secret_token>") ExitOk("Records ".(isset($_REQUEST['u']) ? "UPDATED" : "INSERTED")." successfully", 10);
    if ($token!=$f3->get('update_token')) ErrorExit("Forbitten. Update token not match.");
  }

  $page       = isset($_REQUEST['p']) ? (int)$_REQUEST['p'] : $page; // page number for loadings
  $sql_update = isset($_REQUEST['u']) ? (int)$_REQUEST['u'] : false; // use UPDATE vs INSERT

  $db = new DB\SQL($f3->get('conn_string'), $f3->get('db_username'), $f3->get('db_password'));

  $videos_columns = GetColumnsSizeTable($db, 'videos'); // for checks value size before inserting
  
  $html = LoadPage("http://www.hdkinoteatr.com/page/$page/");

  // ****************************************
  // Patterns for regex search and parsing (if no groups in pattern, return value as boolean)
  $pattern_block = '#shortstory.*?argcat.*?</div>#s';

  $patterns_in_block = array();   // pattern as string OR [pattern OR patterns, FINDMODE_(optional)]
  $patterns_in_block['page'      ] = '#<a[^>]+href=["\'](.*?\.html)["\']#';
  $patterns_in_block['image'     ] = '#<img[^>]+src=["\'](.*?)["\']#';

  $patterns_in_link = array();
  $patterns_in_link['id'         ] = '#/(\d+)[^/]+html#';
  $patterns_in_link['isserial'   ] = '#/series/#'; // if no groups, check it as boolean

  $patterns_in_page = array();
  $patterns_in_page['name'       ] = ['#"og:title"[^>]+content="(.*?)"#', FINDMODE_UNESCAPE];
  $patterns_in_page['name_eng'   ] = ['#"alternateName":"(.*?)"#'       , FINDMODE_UNESCAPE];
  $patterns_in_page['link'       ] = '#(<div[^>]+id="yohoho".*?</div>)#s';
  $patterns_in_page['year'       ] = '#lbl">Год:.*?(\d{4})#';
  $patterns_in_page['kpid'       ] = '#kinopoiskID[:=\s"\']+(\d+)#i';
  $patterns_in_page['country'    ] = '#(<a[^>]+/country/.*?)</div>#s';
  $patterns_in_page['category'   ] = ['#argcat".*?(<a.*?)</div>#s'           , FINDMODE_COMMALINKS];
  $patterns_in_page['director'   ] = ['#lbl">Режиссёр:</span>(.*?)</div>#s'  , FINDMODE_COMMALINKS];
  $patterns_in_page['actors'     ] = ['#lbl">В ролях:</span>(.*?)</div>#s'   , FINDMODE_COMMALINKS];
  $patterns_in_page['scenarist'  ] = ['#lbl">Сценарий:</span>(.*?)</div>#s'  , FINDMODE_COMMALINKS];
  $patterns_in_page['producer'   ] = ['#lbl">Продюсер:</span>(.*?)</div>#s'  , FINDMODE_COMMALINKS];
  $patterns_in_page['composer'   ] = ['#lbl">Композитор:</span>(.*?)</div>#s', FINDMODE_COMMALINKS];
  $patterns_in_page['tags'       ] = ['#<i>Теги:(.*?)</div>#s'               , FINDMODE_COMMALINKS];
  $patterns_in_page['premiere'   ] = '#lbl">Премьера \(мир\):(.*?)</div>#s';
  $patterns_in_page['premiere_rf'] = '#lbl">Премьера \(РФ\):(.*?)</div>#s';
  $patterns_in_page['budget'     ] = '#lbl">Бюджет:</span>(.*?)</div>#s';
  $patterns_in_page['time'       ] = ['#lbl">Время:</span>(.*?)</div>#s', FINDMODE_TIME];
  $patterns_in_page['translation'] = '#lbl">Перевод:</span>(.*?)</div>#s';
  $patterns_in_page['rating_hd'        ] = '#our-rating.*?>(.*?)<#';
  $patterns_in_page['rating_hd_votes'  ] = '#rating-num.*?>\((\d+.*?)[<\)]#';
  $patterns_in_page['rating_kp'        ] = '#kp_rating.*?>(.*?)<#s';
  $patterns_in_page['rating_kp_votes'  ] = '#kp_num[^>]+>(\d+.*?)<#s';
  $patterns_in_page['rating_imdb'      ] = '#imdb_rating.*?>(.*?)<#s';
  $patterns_in_page['rating_imdb_votes'] = '#imdb_num[^>]+>(\d+.*?)<#s';
  $patterns_in_page['date'       ] = ['#/\d{4}/\d{2}/\d{2}/">(.*?)</#', FINDMODE_DATETIME];
  $patterns_in_page['descr'      ] = '#(<div[^>]+class="[^"]*descr.*?</div>)#s';

  $pattern_skip_if_no_link = '#id="reason"#'; // skip deleted videos
  // ****************************************

  // searching blocks in the loaded html
  if (!preg_match_all($pattern_block, $html, $matches))
    ErrorExit('Pattern for search are blocks nothing found.', true);

  // ================= MAIN LOOP FOR SCANNING PAGE =================
  $upd_count = 0;
  foreach ($matches[0] as $block) {
    $params = array();

    // searching field values in the block
    foreach ($patterns_in_block as $key => $p) $params[$key] = FindField($p, $block);

    if (!$params['page']) ErrorExit('Regex pattern for search are `page` in blocks nothing found. Need update the php script.', true);

    $page_url = FullLink($params['page'], $f3->get('url_base'));
    $page = LoadPage($page_url);

    // searching field values in the url
    foreach ($patterns_in_link as $key => $p) $params[$key] = FindField($p, $page_url);
    // searching field values in the loaded page
    foreach ($patterns_in_page as $key => $p) $params[$key] = FindField($p, $page);

    // hdkinoteatr parsing -----------------------------------
    $yohohoVals = RegexValue('#(<div[^>]+id="yohoho".*?</div>)#s', $page);
    $kpID     = RegexValue('#data-kinopoisk="(.*?)"#', $yohohoVals);
    $imdb     = RegexValue('#data-imdb="(.*?)"#'     , $yohohoVals);
    $collaps  = RegexValue('#data-collaps="(.*?)"#'  , $yohohoVals);
    $hdvb     = RegexValue('#data-hdvb="(.*?)"#'     , $yohohoVals);
    $videocdn = RegexValue('#data-videocdn="(.*?)"#' , $yohohoVals);
    if (!$kpID) $kpID = $params["kpid"];
    $post = "videocdn=$videocdn&hdvb=$hdvb&collaps=$collaps&kinopoisk=$kpID&imdb=$imdb";
    $result = file_get_contents("https://ahoy.yohoho.online/", false, stream_context_create(array(
      "http" => array(
        "method"  => "POST",
        "header"  => "content-type: application/x-www-form-urlencoded\r\n".
                     "origin: http://www.hdkinoteatr.com\r\n".
                     "referer: http://www.hdkinoteatr.com/\r\n".
                     "user-agent: Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/78.0.3904.108 Safari/537.36\r\n",
        "content" => $post)
        )));


    if (isset($_GET["testyohoho"])) die($result);


    $videoData = json_decode($result);
    $linksData = array();
    if (!$videoData) {
      var_dump($http_response_header);
      die("Request to ahoy.yohoho.online is failed. POST parameters: ".$post);
    }
    //var_dump($videoData);
    foreach($videoData as $key => $r) {
      $res = (array)$r;
      if (!empty($res)) {
        if (substr($res["iframe"], 0, 2)=="//") $res["iframe"] = "http:".$res["iframe"];
        $linksData[$key] = $res;
      }
    }

    $apiKinoId = RegexValue('#apikino.club/autoreplace/[^"/]+id=(\d+)#', $page);
    if ($apiKinoId) {
      $apiKinoData = file_get_contents("https://apikino.club/autoreplace-load/?id=$apiKinoId&hostname=www.hdkinoteatr.com&href=$page_url&kinopoiskId=$kpID");
      $apiKino = json_decode($apiKinoData);
      if ($apiKino->video_id > 0) {
        $videoLink = 'https://pleeras.club/embed/'.$apiKino->parent_video_hash.'/e/'.$apiKino->video_hash.'/?sid='.$apiKino->site_id;
        $linksData["ONIK"] = array("iframe"=>$videoLink, "translate"=>"", "quality"=>"");
      }
    }
    
    if (!isset($linksData["trailer"])) {
      $trailerHtml = RegexValue('#(<div[^>]+id="trailer".*?</div>)#s', $page);
      $trailerLink = RegexValue('#<iframe[^>]+src="(.*?)"#', $trailerHtml);
      if ($trailerLink) {
        $linksData["trailer"] = array("iframe"=>$trailerLink, "translate"=>"", "quality"=>"");
      }
    }

    //var_dump($linksData);
    //exit();

    if (!empty($linksData)) $params['link'] = json_encode($linksData, JSON_NUMERIC_CHECK|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

    // --------------------------------------------------------

    if (!$params['link'])  {
      if (preg_match($pattern_skip_if_no_link, $page, $m)) continue;
      continue;
      //ErrorExit('Regex pattern for search are `link` in blocks nothing found. Need update the php script. '.$page_url, true);
    }
    if (!$params['id'  ]) ErrorExit('Regex pattern for search are `id` nothing found. Need update the php script. '  .$page_url, true);
    if (!$params['name']) ErrorExit('Regex pattern for search are `name` nothing found. Need update the php script. '.$page_url, true);

    $params['id'  ] = (int)$params['id'  ];
    $params['year'] = (int)$params['year'];
    $params['kpid'] = (int)$params['kpid'];
    $params['time'] = (int)$params['time'];

    if ($last_id && ($params['id'] <= $last_id)) continue; // Skip all videos with ID less or equal $last_id
    
    $params['rating_hd'        ] = (float)$params['rating_hd'];
    $params['rating_kp'        ] = (float)$params['rating_kp'];
    $params['rating_imdb'      ] = (float)$params['rating_imdb'];
    $params['rating_hd_votes'  ] = (int)$params['rating_hd_votes'];
    $params['rating_kp_votes'  ] = (int)$params['rating_kp_votes'];
    $params['rating_imdb_votes'] = (int)$params['rating_imdb_votes'];
    $params['isserial'         ] = (int)$params['isserial'];

    // Remove years from title
    if (preg_match('#\([\d-]{4,}\)#', $params['name'], $m))
      $params['name'] = trim(str_replace($m[0], '', $params['name']));

    if ($f3->get('engtitle_in_title')) {
      // Separate names
      $names = explode($f3->get('titles_separator'), $name);
      if (count($names) > 1) {
        $params['name'    ] = Trim($names[0]);
        $params['name_eng'] = Trim($names[1]);
      }
    }

    $upd_count += InsertVideo($f3, $db, (int)$params['id'], $params, $videos_columns, $sql_update);
  }
  // ===================== END OF SCANNING PAGE ====================

  if ($upd_count) {
    if ($last_id) $f3->set('clear_cache_videos', true);
    else          ClearTableCache($f3, 'videos');
  }

  if ($last_id) 
    return $upd_count;

  ExitOk("Records ".($sql_update ? "UPDATED" : "INSERTED")." successfully", $upd_count);
}

///////////////////////////////////////////////////////////////////////////////
function CheckUpdates($f3) {
  $updating_flag_file = $f3->get('updating_flag_file');
  if (file_exists($updating_flag_file)) {
    if ((time()-filemtime($updating_flag_file)) < 3600) {
      return;
    }
  }
  file_put_contents($updating_flag_file, date("Y-m-d H:i:s"));

  $state_file = $f3->get('file_state');
  $interval   = $f3->get('checking_interval');
  $state_data = file_exists($state_file) ? @file_get_contents($state_file) : "";
  
  $last_id    = (int)GetStateValue($state_data, "last_id"  );
  $last_time  = (int)GetStateValue($state_data, "timestamp");

  if ((time()-$last_time) > $interval) {
    if (!$last_id) $last_id = GetLastIdFromDatabase($f3);
   
    $max_pages = (int)$f3->get('maximum_pages_load');
    for ($i=1; $i <= $max_pages; $i++) {
      if (!ScanPage($f3, $i, $last_id)) 
        break; // not found id greater then last_id in the loaded page
    }

    if ($f3->get('clear_cache_videos')) {
      ClearTableCache($f3, 'videos');
      if ($f3->get('clear_cache_categories')) ClearTableCache($f3, 'categories');
      if ($f3->get('clear_cache_countries' )) ClearTableCache($f3, 'countries' );
      if ($f3->get('clear_cache_tags'      )) ClearTableCache($f3, 'tags'      );
      $last_id = GetLastIdFromDatabase($f3);
      SetStateValue($state_data, "last_id", $last_id);
      SetStateValue($state_data, "added", date("Y-m-d H:i:s"));
    }

    SetStateValue($state_data, "timestamp", time());
    file_put_contents($state_file, $state_data);
  }
  
  unlink($updating_flag_file);
}

///////////////////////////////////////////////////////////////////////////////
function GetVideos($f3) {
  header("Content-Type: application/json");
  CheckUpdates($f3);
  
  $where  = array();
  $order  = array();
  $params = array();

  // parameters for video
  $top       = isset($_REQUEST['top'     ]) ? $_REQUEST['top'     ] : "";  // imdb | kp | hd
  $start     = isset($_REQUEST['start'   ]) ? $_REQUEST['start'   ] : 0;   // start position of the selection
  $limit     = isset($_REQUEST['limit'   ]) ? $_REQUEST['limit'   ] : 100; // maximum items in the selection
  $id        = isset($_REQUEST['id'      ]) ? $_REQUEST['id'      ] : 0;   // video id
  $kpid      = isset($_REQUEST['kpid'    ]) ? $_REQUEST['kpid'    ] : 0;   // kinopoisk id
  $q         = isset($_REQUEST['q'       ]) ? $_REQUEST['q'       ] : "";  // query for searching videos (by name)
  $category  = isset($_REQUEST['category']) ? $_REQUEST['category'] : 0;   // id of the category
  $country   = isset($_REQUEST['country' ]) ? $_REQUEST['country' ] : 0;   // id of the country
  $tag       = isset($_REQUEST['tag'     ]) ? $_REQUEST['tag'     ] : 0;   // id of the tag
  $year      = isset($_REQUEST['year'    ]) ? $_REQUEST['year'    ] : 0;   // year
  $letter    = isset($_REQUEST['letter'  ]) ? $_REQUEST['letter'  ] : "";  // first letter by name
  $ord       = isset($_REQUEST['ord'     ]) ? $_REQUEST['ord'     ] : "";  // order by field
  $serials   = isset($_REQUEST['serials' ]) ? $_REQUEST['serials' ] : 2;   // serials filter (correct: 0 or 1)
  $rating_im = isset($_REQUEST['min_imdb']) ? $_REQUEST['min_imdb'] : 0;   // minimum rating
  $rating_kp = isset($_REQUEST['min_kp'  ]) ? $_REQUEST['min_kp'  ] : 0;   // minimum rating
  $rating_hd = isset($_REQUEST['min_hd'  ]) ? $_REQUEST['min_hd'  ] : 0;   // minimum rating
  $director  = isset($_REQUEST['director']) ? $_REQUEST['director'] : "";  // query for director search
  $actor     = isset($_REQUEST['actor'   ]) ? $_REQUEST['actor'   ] : "";  // query for actors search
  $scenarist = isset($_REQUEST['scenarist'])? $_REQUEST['scenarist']: "";  // query for scenarists search
  $producer  = isset($_REQUEST['producer']) ? $_REQUEST['producer'] : "";  // query for producers search
  $composer  = isset($_REQUEST['composer']) ? $_REQUEST['composer'] : "";  // query for composers search
  $translation = isset($_REQUEST['translation']) ? $_REQUEST['translation'] : "";  // query for translations search
  $human_read  = isset($_REQUEST['hr'         ]) ? $_REQUEST['hr'         ] : 0;   // formatting output
  
  if ($q && strlen($q) < MINIMUM_QUERY_LENGTH) ErrorExit("Too short query");
  if ($q && strlen($q) > MAXIMUM_QUERY_LENGTH) ErrorExit("Too big query");

  if (substr($q, 0, 2) == " !") ErrorExit("Your script not working");

  $cache_name = "videos_".md5($_SERVER["QUERY_STRING"]);
  $no_cache   = isset($_REQUEST['_']) || isset($_REQUEST['rnd']);
  if (!$no_cache && LoadCache($f3, $cache_name)) return;

  $db = new DB\SQL($f3->get('conn_string'), $f3->get('db_username'), $f3->get('db_password'));

  // make safe identificators
  $start     = abs((int)$start);
  $limit     = abs((int)$limit);
  $id        = abs((int)$id   );
  //$kpid      = abs((int)$kpid );
  $category_excl = "";
  $country_excl  = "";

  if ($kpid) {
    $arr = array();
    foreach(explode(',', $kpid) as &$val) {
        $arr[] = abs((int)$val); 
    }
    $kpid = implode(',', $arr);
  }
  if ($category) {
    $arr = explode(',', $category);
    $arr_cat_excl = array();
    $arr_cat      = array();
    foreach($arr as &$val) {
      if ((int)$val < 0) 
        $arr_cat_excl[] = abs((int)$val); 
      else 
        $arr_cat[] = abs((int)$val); 
    }
    $category_excl = implode(',', $arr_cat_excl);
    $category      = implode(',', $arr_cat);
  }
  if ($country) {
    $arr = explode(',', $country);
    $arr_country_excl = array();
    $arr_country      = array();
    foreach($arr as &$val) {
      if ((int)$val < 0) 
        $arr_country_excl[] = abs((int)$val); 
      else 
        $arr_country[] = abs((int)$val); 
    }
    $country_excl = implode(',', $arr_country_excl);
    $country      = implode(',', $arr_country);
  }
  if ($tag) {
    $arr = explode(',', $tag);
    foreach($arr as &$val) $val = abs((int)$val);
    $tag = implode(',', $arr);
  }
  $year      = abs((int)$year);
  $serials   = abs((int)$serials);
  $rating_im = abs((float)$rating_im);
  $rating_kp = abs((float)$rating_kp);
  $rating_hd = abs((float)$rating_hd);
  $human_read= abs((int)$human_read);

  if ($ord) {
    $direction = ($ord[0]=='-') ? "DESC" : "";
    $ord = str_replace('-', '', $ord);
    if (!in_array($ord, GelVideoFileds(true))) ErrorExit("Not existent field for order.");
    $order[] = "$ord $direction";
  }

  $sql = "SELECT v.*, " .
    "(SELECT GROUP_CONCAT(t.name) FROM video_tags       AS vt LEFT JOIN tags       AS t ON t.id = vt.tag      WHERE vt.video=v.id) AS tags, ".
    "(SELECT GROUP_CONCAT(n.name) FROM video_countries  AS vn LEFT JOIN countries  AS n ON n.id = vn.country  WHERE vn.video=v.id) AS country, ".
    "(SELECT GROUP_CONCAT(c.name) FROM video_categories AS vc LEFT JOIN categories AS c ON c.id = vc.category WHERE vc.video=v.id) AS genre ".
    "FROM videos AS v";

  if ($category) {
    $params[':category'] = $category;
    $where[]="w.category IN (:category)";
  }
  if ($category_excl) {
    $where[]="v.id NOT IN (SELECT video from video_categories WHERE category IN (".$category_excl."))";
  }
  if ($category or $category_excl) {
    $sql .= " INNER JOIN video_categories AS w ON w.video=v.id";
  }
  if ($country) {
    $params[':country'] = $country;
    $where[]="q.country IN (:country)";
  }
  if ($country_excl) {
    $where[]="v.id NOT IN (SELECT video from video_countries WHERE country IN (".$country."))";
  }
  if ($country or $country_excl) {
    $sql .= " INNER JOIN video_countries  AS q ON q.video=v.id";
  }
  
  if ($tag      ) { $where[]="y.tag IN (".$tag.")"; $sql .= " INNER JOIN video_tags AS y ON y.video=v.id"; }
  if ($year     ) { $params[':year'     ]=$year     ; $where[]="year=:year"; }
  if ($serials<2) { $params[':serials'  ]=$serials  ; $where[]="isserial=:serials"; }
  if ($rating_im) { $params[':rating_im']=$rating_im; $where[]="rating_imdb>=:rating_im"; }
  if ($rating_kp) { $params[':rating_kp']=$rating_kp; $where[]="rating_kp>=:rating_kp"; }
  if ($rating_hd) { $params[':rating_hd']=$rating_hd; $where[]="rating_hd>=:rating_hd"; }

  if ($director )  { $params[':director'   ]="%$director%"   ; $where[]="v.director LIKE :director"; }
  if ($actor    )  { $params[':actor'      ]="%$actor%"      ; $where[]="v.actors   LIKE :actor"   ; }
  if ($scenarist)  { $params[':scenarist'  ]="%$scenarist%"  ; $where[]="v.scenarist LIKE :scenarist"; }
  if ($producer )  { $params[':producer'   ]="%$producer%"   ; $where[]="v.producer LIKE :producer"; }
  if ($composer )  { $params[':composer'   ]="%$composer%"   ; $where[]="v.composer LIKE :composer"; }
  if ($translation){ $params[':translation']="%$translation%"; $where[]="v.translation LIKE :translation"; }

  if ($top) {
    switch ($top) {
      case 'imdb': $order[]="rating_imdb DESC, rating_imdb_votes DESC"; $where[]="isserial=0"; $limit=250; break;
      case 'kp'  : $order[]="rating_kp   DESC, rating_kp_votes   DESC"; $where[]="isserial=0"; $limit=250; break;
      case 'hd'  : $order[]="rating_hd   DESC, rating_hd_votes   DESC"; $where[]="isserial=0"; $limit=250; break;
      default: break;
    }
  }

  if     ($id    ) { $params[':id'  ]=$id        ; $where[]="v.id=:id"; }
  elseif ($kpid  ) { $where[]="v.kpid IN (".$kpid.")"; }
  elseif ($letter) { $params[':name']=$letter."%"; $where[]="v.name LIKE :name"; }
  elseif ($q) {
    $params[':name' ] = "%$q%";
    $params[':nameX'] = "$q";
    $params[':nameB'] = "$q%";
    $where[] = "v.name LIKE :name OR v.name_eng LIKE :name";
    $order[] = "priority DESC";
    $sql = str_replace("SELECT v.*,", "SELECT v.*, case when v.name like :nameX then 4 when v.name_eng like :nameX then 3 when v.name like :nameB then 2 when v.name_eng like :nameB then 1 else 0 end as priority, ", $sql);
  }
  //elseif ($q     ) { $params[':name']="%$q%"     ; $where[]="v.name LIKE :name OR v.name_eng LIKE :name"; }



  if (count($where)>0) $sql .= " WHERE ".implode(' AND ', $where);
  $sql .= " GROUP BY v.id";
  if (count($order)>0) $sql .= " ORDER BY ".implode(', ', $order);

  // default values
  if (!strpos($sql, 'ORDER' )) $sql .= " ORDER BY date DESC";
  if (!strpos($sql, 'LIMIT' )) $sql .= " LIMIT $start,$limit";

  //var_dump($sql);var_dump($where);var_dump($category);die();
  $result = $db->exec($sql, $params);

  if ($human_read)
    $data = json_encode($result, JSON_NUMERIC_CHECK|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
  else
    $data = json_encode($result, JSON_NUMERIC_CHECK|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

  if (!$no_cache) SaveCache($f3, $cache_name, $data);
  echo $data;
}

///////////////////////////////////////////////////////////////////////////////
function ListTable($f3, $table_name) {
  header("Content-Type: application/json");
  $no_cache   = isset($_REQUEST['_']) || isset($_REQUEST['rnd']);
  $cache_name = $table_name."_".md5($_SERVER["QUERY_STRING"]);
  if (!$no_cache && LoadCache($f3, $cache_name)) return;

  $order = array();

  $db  = new DB\SQL($f3->get('conn_string'), $f3->get('db_username'), $f3->get('db_password'));
  $sql = "SELECT * FROM $table_name";

  $ord         = isset($_REQUEST['ord']) ? $_REQUEST['ord'] : "";  // order by field
  $human_read  = isset($_REQUEST['hr' ]) ? $_REQUEST['hr' ] : 0;   // formatting output

  if ($ord) {
    $direction = ($ord[0]=='-') ? "DESC" : "";
    $ord = str_replace('-', '', $ord);
    if (!in_array($ord, ['id','name'])) ErrorExit("Not existent field for order.");
    $order[] = "$ord $direction";
  }
  
  if (isset($_REQUEST['ord'])) {
    switch ($_REQUEST['ord']) {
      case 'name': $ord = "name"; break;
      default    : $ord = "id"  ; break;
    }
  }

  if (isset($_REQUEST['serials' ])) {
    $serials = $_REQUEST['serials']==1 ? 1 : 0;
    $field = "";
    switch ($table_name) {
      case 'categories': $field="category"; break;
      case 'countries' : $field="country" ; break;
      case 'tags'      : $field="tag"     ; break;
    }
    if ($field)
      $sql = "SELECT t.id, t.name FROM `video_$table_name` w RIGHT JOIN `videos` AS v ON v.id = w.video RIGHT JOIN `$table_name` AS t ON w.$field=t.id WHERE v.isserial=$serials GROUP BY t.id";
  }

  if (count($order)>0) $sql .= " ORDER BY ".implode(', ', $order);

  $result = $db->exec($sql);

  if ($human_read)
    $data = json_encode($result, JSON_NUMERIC_CHECK|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
  else
    $data = json_encode($result, JSON_NUMERIC_CHECK|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

  if ($data && !$no_cache) SaveCache($f3, $cache_name, $data);
  echo $data;
}

///////////////////////////////////////////////////////////////////////////////
function JSDecode($data) {
  $data = str_replace("encodeURIComponent(", "", $data);
  $data = str_replace("'),", "',", $data);
  $data = str_replace("'", "\""  , $data);
  $data = str_replace(array("\n","\r"),"", $data); 
  $data = preg_replace('/(\s*)(\w+):(\s+)/','"$2":', $data);
  $data = preg_replace('/(,\s*)(})/','$2', $data);
  $json = json_decode($data, true);
  return $json;
}

///////////////////////////////////////////////////////////////////////////////
function FindEpisodes($json, $url) {
  $result = array();
  $frmt   = count($json["episodes"]) > 99 ? "%03d" : "%02d";
  $subs   = isset($json["subtitles"]["master_vtt"]);
  $season = $json["season"];
  foreach ($json["episodes"] as $e) {
    $episode = $e;
    $name = sprintf("$frmt серия", $episode);
    $link = "$url?season=$season&episode=$episode";
    if ($subs)
      $result[] = ["comment"=>$name, "file"=>$link, "subs"=>true];
    else
      $result[] = ["comment"=>$name, "file"=>$link];
  }
  return $result;
}

///////////////////////////////////////////////////////////////////////////////
function GetSeries($f3) {
  header("Content-Type: application/json");
  die("");
  //$url = 'https://php-coder.cx.ua'.$_SERVER["REQUEST_URI"];

  //$f3->reroute($url);
  //exit();  

  // parameters
  $type    = isset($_REQUEST['t'      ]) ?      $_REQUEST['t'      ] : "serial"; // video | serial
  $hash    = isset($_REQUEST['h'      ]) ?      $_REQUEST['h'      ] : "";
  $url     = isset($_REQUEST['url'    ]) ?      $_REQUEST['url'    ] : "";
  $trans   = isset($_REQUEST['trans'  ]) ? (int)$_REQUEST['trans'  ] : 0;
  $kpid    = isset($_REQUEST['kpid'   ]) ? (int)$_REQUEST['kpid'   ] : 0;

  $result  = array();

  if (!$hash && preg_match("#/(\w+)/(\w+)/iframe#", $url, $m)) { $type = $m[1]; $hash = $m[2]; }
  if (!$hash) ErrorExit("No serial hash");

  $cache_name = "serial_$hash";
  if (LoadCache($f3, $cache_name, true)) return;

  $url  = "http://moonwalk.cc/$type/$hash/iframe";
  $html = LoadPage($url);
  if (!$html) ErrorExit("Error loading page.");

  if (isset($_REQUEST['html'])) die($html);

  $data = FindField('#VideoBalancer\((.*?)\);#s', $html);

  if (!$data) {
    if (!$params['page']) ErrorExit("No VideoBalancer data found in iframe. Need update the php script. \nLine: ".__LINE__." (in API index.php) \nUrl: $url \nHtml content: ".htmlentities($html), true);
  }

  $json = JSDecode($data);

  if ($trans) {
    $result = array();
    foreach ($json["translations"] as $t) {
      $result[] = ["comment"=>$t[1], "file"=> str_replace($hash, $t[0], $url) ];
    }

  } else {

    $seasons = $json["seasons"];
    if (count($seasons)>0) {
      foreach ($seasons as $i) {
        $data = LoadPage("$url?season=$i");
        $data = FindField('#VideoBalancer\((.*?)\);#s', $data);
        $json = JSDecode($data);
        $result[] = ["playlist"=>FindEpisodes($json, $url), "comment"=>"Сезон $i"];
      }
    } else {
      $result = FindEpisodes($json, $url);
    }
  }

  if ($kpid) {
    $info = GetSeriesInfoByKinopoiskID($f3, $kpid);
    // set for each episode in playlist name and image from $info
    $season  = 1;
    $episode = 1;
    foreach ($result as $key1=>$r) {
      if (isset($r["playlist"])) {
        $val = RegexValue('#(\d+)#', $r["comment"]);
        if ($val) { $season=intval($val); $episode=1; }
        foreach ($r["playlist"] as $key2=>$e) {
          if (isset($info[$season][$episode])) {
            $result[$key1]["playlist"][$key2]["comment"] = $info[$season][$episode]["name" ];
            $result[$key1]["playlist"][$key2]["image"  ] = $info[$season][$episode]["image"];
          }
          $episode++;
        }
        $season++; $episode=1;
      } else {
        if (isset($info[$season][$episode])) {
          $result[$key1]["comment"] = $info[$season][$episode]["name" ];
          $result[$key1]["image"  ] = $info[$season][$episode]["image"];
        }
        $episode++;
      }
    }
  }
  $data = json_encode($result, JSON_NUMERIC_CHECK|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  SaveCache($f3, $cache_name, $data);
  echo $data;
}

///////////////////////////////////////////////////////////////////////////////
function FindSelectItems($html, $url, $select, $season=0) {
  $items = array();

  if (!$season && preg_match("#<select[^>]+name=\"season\".*?</select>#s", $html, $m1)) {
    if (preg_match('#<option[^>]+selected[^>]+value="(\\d+)#', $m1[0], $m2)) $season = intval($m2[1]);
  } 
  if (!$season) $season = 1;

  if (preg_match("#<select[^>]+name=\"$select\".*?</select>#s", $html, $m_block)) {
    $count = preg_match_all('#<option[^>]+value=[\'"](.*?)[\'"]>(.*?)</option>#', $m_block[0], $matches);
    $frmt  = $count > 99 ? "%03d" : "%02d";
    for ($i=0; $i < $count; $i++) {
      $value = $matches[1][$i];
      $name  = $matches[2][$i];
      if ($select=='season'    ) $link = "$url?season=$value";
      if ($select=='episode'   ) $link = "$url?season=$season&episode=$value";
      if ($select=='translator') $link = "$url";

      if (preg_match('#Серия\s+(\d+)#i', $name, $m)) {
        $episode = $m[1];
        $name = str_replace($m[0], sprintf("$frmt серия", $episode), $name);
      }

      $items[] = ["comment"=>$name, "file"=>$link];
    }
  }
  return $items;
}

///////////////////////////////////////////////////////////////////////////////
function LoadCache($f3, $table_name, $short_life=false) {
  $cache_dir  = $f3->get('cache_dir');
  $cache_file = "$cache_dir/$table_name";
  if (file_exists($cache_file)) {
    if ($short_life && ((time()-filemtime($cache_file)) > $f3->get('cache_short_life'))) {
      return false;
    }
    $udata = gzuncompress(file_get_contents($cache_file));
    if (preg_match('/\b00 серия/', $udata, $matches)) return false;
    echo $udata;
    return true;
  }
  return false;
}

///////////////////////////////////////////////////////////////////////////////
function LoadCacheSerialsInfo($f3, $cache_name, &$result) {
  $cache_dir  = $f3->get('cache_dir');
  $cache_file = "$cache_dir/$cache_name";
  if (file_exists($cache_file)) {
    if ((time()-filemtime($cache_file)) > $f3->get('cache_episodes_life')) {
      return false;
    }
    $json   = gzuncompress(file_get_contents($cache_file));
    $result = json_decode($json, true);
    return true;
  }
  return false;
}

///////////////////////////////////////////////////////////////////////////////
function SaveCacheSerialsInfo($f3, $cache_name, &$result) {
  $cache_dir = $f3->get('cache_dir');
  $json = json_encode($result, JSON_NUMERIC_CHECK|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  file_put_contents("$cache_dir/$cache_name", gzcompress($json));
}

///////////////////////////////////////////////////////////////////////////////
function SaveCache($f3, $table_name, &$data) {
  CheckCacheSizeLimit($f3);
  $cache_dir = $f3->get('cache_dir');
  file_put_contents("$cache_dir/$table_name", gzcompress($data));
}

///////////////////////////////////////////////////////////////////////////////
function CheckCacheSizeLimit($f3) {
  $cache_dir      = $f3->get('cache_dir');
  $cache_max_size = $f3->get('cache_max_size');
  if (!file_exists($cache_dir)) @mkdir($cache_dir, 0777, true);

  $files      = array();
  $cache_size = GetDirSize($cache_dir, $files);

  if ($cache_size > $cache_max_size) {
    $cache_max_size = $cache_max_size * 0.80; // 80% saving, others deleting
    usort($files, 'CompareBySecondField');    // sorting: newer first
    $size_of_files = 0;
    foreach($files as $info) {
      $size_of_files += $info[2];
      if ($size_of_files > $cache_max_size) {
        $size_of_files -= $info[2];
        if (!file_exists($info[0])) unlink($info[0]); // checks, if other process already deleted file
      }
    }
  }
}

///////////////////////////////////////////////////////////////////////////////
function ClearTableCache($f3, $table_name) {
  $cache_dir = $f3->get('cache_dir'); if (!file_exists($cache_dir)) return;
  $files = glob("$cache_dir/$table_name*");
  foreach($files as $file) {
    if(is_file($file)) unlink($file);
  }
}

///////////////////////////////////////////////////////////////////////////////
function GetLastIdFromDatabase($f3) {
  $db = new DB\SQL($f3->get('conn_string'), $f3->get('db_username'), $f3->get('db_password'));
  $r  = $db->exec("SELECT id FROM videos ORDER BY id DESC LIMIT 1");
  if (isset($r[0])) return (int)($r[0]["id"]);
  return 0;
}

///////////////////////////////////////////////////////////////////////////////
function DecodeHDkinotatrVKlink($link, &$html) {
  preg_match('#code.search\\(/\\^oid=.*?code.replace\\((/.*?/)g,\'(.*?)\'#s', $html, $m1);
  preg_match('#code.search\\(/\\^oid=.*?\\).replace\\((/.*?/)g,\'(.*?)\'#s' , $html, $m2);

  if (isset($m1[2])) $link = preg_replace($m1[1], $m1[2], $link);
  if (isset($m2[2])) $link = preg_replace($m2[1], $m2[2], $link);

  return "http://vk.com/video_ext.php?".$link;
}

///////////////////////////////////////////////////////////////////////////////
function GelVideoFileds($as_array=0) {
  $fields = "id,name,name_eng,link,page,year,kpid,image,director,actors,scenarist,producer,composer,premiere,premiere_rf,budget,time,translation,rating_hd,rating_hd_votes,rating_kp,rating_kp_votes,rating_imdb,rating_imdb_votes,date,isserial,descr";
  if ($as_array) return explode(',', $fields);
  return $fields;
}

///////////////////////////////////////////////////////////////////////////////
function InsertVideo($f3, $db, $id, $params, $videos_columns, $sql_update=false) {
  $db->beginTransaction();
  UpdateTable($f3, $db, 'categories', $params['category' ], 'video_categories', 'category' , $id, 32);
  UpdateTable($f3, $db, 'countries' , $params['country'  ], 'video_countries' , 'country'  , $id, 32);
  UpdateTable($f3, $db, 'tags'      , $params['tags'     ], 'video_tags'      , 'tag'      , $id, 64);

  // Update the database with values
  $fields = GelVideoFileds();
  $fields_array   = explode(",", $fields);
  $question_marks = Placeholders('?', count($fields_array));

  if ($sql_update) $sql = "REPLACE       INTO videos ($fields) VALUES ($question_marks)";
  else             $sql = "INSERT IGNORE INTO videos ($fields) VALUES ($question_marks)";

  $insert_values = array();
  foreach ($fields_array as $name) {
    $value = $params[$name];
    // Check for maximum len
    if (isset($videos_columns[$name])) {
      $max_len = $videos_columns[$name];
      if (strlen($value) > $max_len) {
        $sval  = mb_substr($value, 0, $max_len);
        $value = is_int($value) ? (int)($sval) : $sval;
      }
    }
    $insert_values[] = $value;
  }
  $stmt = $db->prepare($sql);
  $stmt->execute($insert_values);
  $db->commit();
  return $stmt->rowCount();
}

///////////////////////////////////////////////////////////////////////////////
// Get field value from the html by regex pattern
// $mode: 0 | FINDMODE_COMMALINKS | FINDMODE_2VALUES | FINDMODE_TIME
function FindField($options, $text, $mode=0) {
  $skip = array();
  if (is_array($options)) {
    $pattern = $options[0];
    $mode    = isset($options[1]) ? $options[1] : 0;
  } else {
    $pattern = $options;
  }
  
  $value = "";
  if (is_array($pattern)) {
    foreach ($pattern as $p) {
      $value = RegexValue($p, $text);
      if ($value) break; 
    }
  } else
    $value = RegexValue($pattern, $text);

  switch ($mode) {

    case FINDMODE_NAMECASE:
    case FINDMODE_COMMALINKS:
      if (preg_match_all("#<a.*?</a>#", $value, $m)) {
        $values = array();
        foreach ($m[0] as $part) {
          $val = trim(Html2Text($part)); if (in_array($val, $skip)) continue;
          if ($mode & FINDMODE_NAMECASE) 
            $val = mb_convert_case($val, MB_CASE_TITLE, "UTF-8");
          $values[] = $val;
        }
        $value = implode(', ', $values);
      } else {
        $value = Html2Text($value);
        if ($mode & FINDMODE_NAMECASE) 
          $value = mb_convert_case($value, MB_CASE_TITLE, "UTF-8");
      }
      break;

    case FINDMODE_TIME:
      if (preg_match("#(\d+)\s*мин#", Html2Text($value), $m)) 
        $value = intval($m[1]) * 60;
      break;

    case FINDMODE_UNESCAPE:
      $value = stripcslashes(Html2Text($value));
      break;
     
    case FINDMODE_DATETIME:
      $value = Html2Text($value);
      $part_date = RegexValue("#(\d{2}.\d{2}.\d{4})#", $value);
      $part_time = RegexValue("#(\d{2}:\d{2})#"      , $value);
      $format = "d.m.Y H:i";
      if (strpos($value, "Сегодня")!==false) {
        $part_date = date("d.m.Y");
      }
      if (strpos($value, "Вчера")!==false) {
        $part_date = date("d.m.Y", time()-(60*60*24));
      }
      $date  = DateTime::createFromFormat($format, "$part_date $part_time");
      if ($date) $value = date("Y-m-d H:i:s", $date->getTimestamp());
      else $value = date("Y-m-d H:i:s");
      break;

    default:
      $value = Html2Text($value);
      break;
  }
  return trim($value);
}

///////////////////////////////////////////////////////////////////////////////
function Html2Text($html) { 
  return html_entity_decode(str_replace('&nbsp;', ' ', strip_tags($html)));
}

///////////////////////////////////////////////////////////////////////////////
function ErrorExit($msg, $email_me=false) { 
  if ($email_me && defined('ERROR_REPORTING_EMAIL') && ERROR_REPORTING_EMAIL) {
    $email_sent_file = 'email_sent';
    $email_sent_time = file_exists($email_sent_file) ? (int)file_get_contents($email_sent_file) : 0;
    $email_interval  = defined('ERROR_REPORTING_EMAIL_INTERVAL') ? ERROR_REPORTING_EMAIL_INTERVAL : (48 * 60 * 60);
    if ((time()-$email_sent_time) > $email_interval) {
      $email_from = defined('ERROR_REPORTING_EMAIL_FROM') ? ERROR_REPORTING_EMAIL_FROM : "api@hdkinoteatr";
      mail(ERROR_REPORTING_EMAIL, "Error in API script ".__FILE__, $msg, "From: $email_from", "-f$email_from");
      file_put_contents($email_sent_file, time());
    }
  }  
  ExitWithJson('error', $msg);
}

///////////////////////////////////////////////////////////////////////////////
function ExitOk($msg, $updated=0) { ExitWithJson('ok', $msg, $updated); }

///////////////////////////////////////////////////////////////////////////////
function ExitWithJson($status, $msg, $updated=0) {
  $data = array();
  $data['status' ] = $status;
  $data['message'] = $msg;
  $data['count'  ] = $updated;
  die(json_encode($data, JSON_NUMERIC_CHECK|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}

///////////////////////////////////////////////////////////////////////////////
function RegexValue($pattern, $text, $group_num=1, $default="", $check=false) {
  $found = preg_match($pattern, $text, $m);
  $groups_count = count($m)-1;
  if ($groups_count==0) return $found; // No groups - check it as boolean
  if (!$found || ($groups_count < $group_num)) return $default;
  return $m[$group_num];
}

///////////////////////////////////////////////////////////////////////////////
function LoadPage($url) {
  $headers = "Accept-Encoding: deflate\r\n" .
             "Accept: application/json, text/javascript, */*; q=0.01\r\n" .
             "Referer: http://www.hdkinoteatr.com/\r\n" .
             "User-Agent: Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/54.0.2840.99 Safari/537.36\r\n";
  $options = array('http'=>array('method'=>'GET', 'header'=>$headers, 'timeout'=>14));
  $page    = @file_get_contents($url, false, stream_context_create($options));
  return $page;
  if ($http_response_header) {
    foreach($http_response_header as $c => $h) {
      if (stristr($h, 'content-encoding') and stristr($h, 'gzip')) {
        $page = gzdecode($page);
        break;
      }
    }
  }
  return $page;
}

///////////////////////////////////////////////////////////////////////////////
function CheckMaxLen($data, $maxlen) { return mb_strlen($data) > $maxlen ? mb_substr($data, 0, $maxlen) : $data; }

///////////////////////////////////////////////////////////////////////////////
// Обновление таблицы с полями id и name, а также 
function UpdateTable($f3, $conn, $table, $names_str, $table_video, $field, $video_id, $name_maxlen) {
    // Получаем список имён, которые уже добавлены в базу данных
    $names_str = trim(str_replace("'", "", $names_str)); if ($names_str=="" || $names_str=="||") return;
    $is_people = ($table=="people");
    // Удаляем записи из таблицы связки video.id, othertable.id с таким video.id
    $conn->query("DELETE FROM `$table_video` WHERE video=$video_id");
    // Создаём массив переданных имён (значений поля `name`) с контролем максимальной длины
    $values = array();
    $names  = array();
    foreach(explode(',', $names_str) as $part) {
      $fields = explode('|', $part);
      $name = trim(mb_strlen($fields[0]) > $name_maxlen ? mb_substr($n, 0, $name_maxlen) : $fields[0]);
      $img  = isset($fields[1]) ? $fields[1] : "";
      $link = isset($fields[2]) ? $fields[2] : "";
      if (!$name) continue;
      array_push($values, [$name, $img, $link]);
      array_push($names, $name);
    }
    if (count($names)<1) return;
    // Ищем в таблице с именами уже присутствующие
    $data_array = array();
    $names_str  = implode(',', $names);
    $exists = $conn->query("SELECT * FROM `$table` WHERE FIND_IN_SET(`name`, '$names_str')");
    $rows   = $exists->fetchAll();
    // Составляем список имён, которых нет в таблице
    foreach($values as $value) {
      $found=false;
      foreach($rows as $r) if ($r['name']==$value[0]) { $found=1; break; }
      if (!$found) {
        if ($is_people) array_push($data_array, "('".$value[0]."','".$value[1]."','".$value[2]."')");
        else            array_push($data_array, "('".$value[0]."')");
      }
    }
    // Если есть имена для добавления в таблицу - добавляем
    if (count($data_array)>0) {
      if ($is_people) $stmt = $conn->prepare("INSERT INTO `$table` (`name`,`image`,`page`) VALUES " . implode(',', $data_array));
      else            $stmt = $conn->prepare("INSERT INTO `$table` (`name`) VALUES " . implode(',', $data_array));
      $stmt->execute();
      $f3->set("clear_cache_$table", true);
    }
    // Получаем список id переданных имён
    $data_array = array();
    $exists = $conn->query("SELECT * FROM `$table` WHERE FIND_IN_SET(`name`, '$names_str')");
    $rows   = $exists->fetchAll();
    // Добавляем в таблицу связки video.id, othertable.id записи с id наших имён
    foreach ($rows as $r) array_push($data_array, "($video_id,".$r['id'].")");
    if (count($data_array)>0) {
      $stmt = $conn->prepare("INSERT INTO `$table_video` (video,`$field`) VALUES " . implode(',', $data_array));
      $stmt->execute();
    }
}

///////////////////////////////////////////////////////////////////////////////
function Placeholders($text, $count=0, $separator=",") {
  $result = array();
  for($i=0; $i<$count; $i++) $result[] = $text;
  return implode($separator, $result);
}

///////////////////////////////////////////////////////////////////////////////
// Returned full url
function FullLink($link, $url_base) {
  if (trim($link)=="") return "";
  if (substr($link, 0, 4)=="http") return $link;
  if (substr($link, 0, 2)=="//"  ) return "http:".$link;
  if (substr($link, 0, 1)=="/"   ) return $url_base.$link;
  return $url_base."/".$link;
}

///////////////////////////////////////////////////////////////////////////////
function GetColumnsSizeTable($db, $table_name) {
  $columns = array();
  $result  = $db->query('SHOW COLUMNS FROM `videos`');
  foreach($result as $key => $col) {
    if (!preg_match('/\d+/', $col['Type'], $m)) continue; // get the number out of 'int(10)'
    $field_len  = (int)$m[0];           // length of the column
    $field_name = $col['Field'];        // name of the column
    $columns[$field_name] = $field_len; // save it in array 
  }
  return $columns;
}

///////////////////////////////////////////////////////////////////////////////
function InitDB($f3) {
  $token = isset($_REQUEST['token']) ? $_REQUEST['token'] : "";
  if ($token!=$f3->get('update_token')) ErrorExit("Forbitten. Update token not match.");

  $db = new DB\SQL($f3->get('conn_string'), $f3->get('db_username'), $f3->get('db_password'));

  $db->beginTransaction();
  $db->query("CREATE TABLE IF NOT EXISTS `categories` (`id` int(4) NOT NULL, `name` varchar(32) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8;");
  $db->query("CREATE TABLE IF NOT EXISTS `countries`  (`id` int(4) NOT NULL, `name` varchar(32) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8;");
  $db->query("CREATE TABLE IF NOT EXISTS `tags`       (`id` int(8) NOT NULL, `name` varchar(64) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8;");
  $db->query("CREATE TABLE IF NOT EXISTS `videos` (".
      "`id` int(8) NOT NULL,".
      "`name` varchar(64) NOT NULL,".
      "`name_eng` varchar(64) NOT NULL,".
      "`link` text NOT NULL,".
      "`page` varchar(255) NOT NULL,".
      "`year` int(4) NOT NULL,".
      "`kpid` int(8) NOT NULL,".
      "`image` varchar(255) NOT NULL,".
      "`director` varchar(255) NOT NULL,".
      "`actors` varchar(255) NOT NULL,".
      "`scenarist` varchar(255) NOT NULL,".
      "`producer` varchar(255) NOT NULL,".
      "`composer` varchar(255) NOT NULL,".
      "`premiere` varchar(32) DEFAULT NULL,".
      "`premiere_rf` varchar(32) DEFAULT NULL,".
      "`budget` varchar(32) NOT NULL,".
      "`time` int(8) NOT NULL,".
      "`translation` varchar(128) NOT NULL,".
      "`rating_hd` decimal(4,1) UNSIGNED NOT NULL DEFAULT '0.0',".
      "`rating_hd_votes` int(8) NOT NULL,".
      "`rating_kp` decimal(4,1) NOT NULL,".
      "`rating_kp_votes` int(8) NOT NULL,".
      "`rating_imdb` decimal(4,1) NOT NULL,".
      "`rating_imdb_votes` int(8) NOT NULL,".
      "`date` datetime DEFAULT NULL,".
      "`isserial` tinyint(1) NOT NULL,".
      "`descr` text".
    ") ENGINE=InnoDB DEFAULT CHARSET=utf8;");

  $db->query("CREATE TABLE IF NOT EXISTS `video_categories` (`video` int(8) NOT NULL, `category`  int(4) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8;");
  $db->query("CREATE TABLE IF NOT EXISTS `video_countries`  (`video` int(8) NOT NULL, `country`   int(4) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8;");
  $db->query("CREATE TABLE IF NOT EXISTS `video_tags`       (`video` int(8) NOT NULL, `tag`       int(8) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8;");

  $db->query("ALTER TABLE `categories` ADD PRIMARY KEY (`id`), ADD KEY `name` (`name`);");
  $db->query("ALTER TABLE `countries`  ADD PRIMARY KEY (`id`), ADD KEY `name` (`name`);");
  $db->query("ALTER TABLE `tags`       ADD PRIMARY KEY (`id`), ADD KEY `name` (`name`);");
  
  $db->query("ALTER TABLE `videos`".
      "ADD PRIMARY KEY (`id`),".
      "ADD KEY `name` (`name`),".
      "ADD KEY `name_eng` (`name_eng`),".
      "ADD KEY `isserial` (`isserial`);");

  $db->query("ALTER TABLE `video_categories` ADD KEY `video` (`video`), ADD KEY `category` (`category`);");
  $db->query("ALTER TABLE `video_countries`  ADD KEY `video` (`video`), ADD KEY `country` (`country`);");
  $db->query("ALTER TABLE `video_tags`       ADD KEY `video` (`video`), ADD KEY `tag` (`tag`);");

  $db->query("ALTER TABLE `categories` MODIFY `id` int(4) NOT NULL AUTO_INCREMENT;");
  $db->query("ALTER TABLE `countries`  MODIFY `id` int(4) NOT NULL AUTO_INCREMENT;");
  $db->query("ALTER TABLE `tags`       MODIFY `id` int(8) NOT NULL AUTO_INCREMENT;");
  $db->commit();

  $alltables = $db->prepare('show tables');
  $alltables->execute();

  ExitOk("Database initialised!", $alltables->rowCount());
}

///////////////////////////////////////////////////////////////////////////////
function GetSeriesInfoByKinopoiskID($f3, $kpid) {
  $info = array();
  $cache_name = "kp$kpid";
  if (LoadCacheSerialsInfo($f3, $cache_name, $info)) return $info;

  $data = LoadPage("https://www.kinopoisk.ru/film/$kpid/episodes/");
  $data = mb_convert_encoding($data, "utf-8", "windows-1251");
//  if (preg_match("#<td[^>]+class=\"news\">([^<]+),\s+(\d{4})#", $data, $m))
//    $info = GetTheTVDBInfo($f3, $m[1], $m[2]);
  if (preg_match_all("#(<h[^>]+moviename-big.*?)</h#s", $data, $matches)) {
    $season  = 1;
    $episode = 1;
    foreach ($matches[0] as $m) {
      $val  = RegexValue('#Сезон\s*(\d+)#i', $m);
      if ($val) { $season=intval($val); $episode=1; continue; }
      $name = isset($info[$season][$episode]["name"]) ? $info[$season][$episode]["name"] : "";
      if (!$name) $name = Html2Text($m);
      $info[$season][$episode]["name"] = $name;
      $episode++;
    }
  }
  if (count($info)>0) SaveCacheSerialsInfo($f3, $cache_name, $info);
  return $info;
}

///////////////////////////////////////////////////////////////////////////////
function GetTheTVDBInfo($f3, $name, $year) {
  $info = array();
  $tvdb_key = $f3->get('tvdb_api_key');
  if (!$tvdb_key) return $info; // exit quickly, if no tvdb api key
  $id   = "";
  $name = str_replace(' ', '_', $name);
  $xml  = LoadPage("http://thetvdb.com/api/GetSeries.php?seriesname=$name");
  if (preg_match_all("#<Series>.*?</Series>#is", $xml, $matches)) {
    foreach ($matches[0] as $m) {
      $aired = RegexValue('#<FirstAired>(\d+)#', $m);
      if ($aired==$year) { $id = RegexValue('#<seriesid>(\d+)#', $m); break; }
    }
  }
  if ($id) {

    $xml = LoadPage("http://thetvdb.com/api/$tvdb_key/series/$id/all/ru.xml", "GET", $headers);
    $poster = RegexValue('#<poster>(.*?)<#' , $xml);
    preg_match_all("#<Episode>.*?</Episode>#is", $xml, $matches);
    foreach ($matches[0] as $m) {
      $season  = RegexValue('#<SeasonNumber>(\d+)#'   , $m);
      $episode = RegexValue('#<EpisodeNumber>(\d+)#'  , $m);
      $number  = RegexValue('#<absolute_number>(\d+)#', $m);
      $title   = RegexValue('#<EpisodeName>(.*?)</#'  , $m);
      $image   = RegexValue('#<filename>(.*?)<#'      , $m);
      if (!$image) $image = $poster;
      $info[$season][$episode] = ["name"=>$title, "image"=>"http://thetvdb.com/banners/$image", "number"=>$number];
    }
  }
  return $info;
}

///////////////////////////////////////////////////////////////////////////////
function CompareBySecondField ($a,$b) {if ($a[1]>$b[1]) return -1; elseif($a[1]<$b[1]) return 1; return 0;}

///////////////////////////////////////////////////////////////////////////////
// Get size and info of all files in the directory
function GetDirSize($d, &$aFiles) {
  if (!file_exists($d)) return 0;
  $dh = opendir($d);
  $size = 0;
  while(($f = readdir($dh))!==false) {
    if ($f != "." && $f != "..") {
      $path = $d . "/" . $f;
      if(is_dir($path))      $size += dirsize($path, $aFiles);
      elseif(is_file($path)) {
        $fs = filesize($path);
        $size += $fs;
        $aFiles[] = array($path, filectime($path), $fs);
      }
    }
  }
  closedir($dh);
  return $size;
}

///////////////////////////////////////////////////////////////////////////////
function SetStateValue(&$data, $key, $value) {
  if (preg_match("/$key=[^\r\n]+/", $data, $m)) {
    $data = str_replace($m[0], "$key=$value", $data);
  } else {
    $data.= ($data=="" ? "" : "\n") . "$key=$value";
  }
}

///////////////////////////////////////////////////////////////////////////////
function GetStateValue($data, $key) {
  if (!preg_match("/$key=([^\r\n]+)/", $data, $m)) return "";
  return $m[1];
}

///////////////////////////////////////////////////////////////////////////////
