<?php

# Two configuration variables:
#$egListFeedFeedUrlPrefix = <URL location to generated static rss directory>
#$egListFeedFeedDir = <Filesystem location to generated static rss directory>

require_once $IP.'/includes/Feed.php';

class RSSFeedWithoutHttpHeaders extends RSSFeed
{
    function httpHeaders() {}
}

$wgExtensionCredits['ListFeed'][] = array(
    name           => 'List Feed',
    version        => '1.0',
    author         => 'Vitaliy Filippov',
    url            => 'http://yourcmc.ru/wiki/index.php/ListFeed_(MediaWiki)',
    description    => 'Allows to export Wiki lists into (static) RSS feeds with a minimal effort',
    descriptionmsg => 'listfeed-desc',
);
$wgHooks['ArticleSaveComplete'][] = 'MWListFeed::ArticleSaveComplete';
$wgHooks['LoadExtensionSchemaUpdates'][] = 'MWListFeed::LoadExtensionSchemaUpdates';
$wgExtensionFunctions[] = 'MWListFeed::init';

if (!$egListFeedFeedUrlPrefix || !$egListFeedFeedDir)
    die('Please set $egListFeedFeedUrlPrefix (url location of rss feed directory) and $egListFeedFeedDir (its local filesystem location) in your LocalSettings.php');

class MWListFeed
{
    static $urlbase;
    static $monthkeys = array(
        'january'       => 0,
        'february'      => 1,
        'march'         => 2,
        'april'         => 3,
        'may_long'      => 4,
        'june'          => 5,
        'july'          => 6,
        'august'        => 7,
        'september'     => 8,
        'october'       => 9,
        'november'      => 10,
        'december'      => 11,
        'january-gen'   => 0,
        'february-gen'  => 1,
        'march-gen'     => 2,
        'april-gen'     => 3,
        'may-gen'       => 4,
        'june-gen'      => 5,
        'july-gen'      => 6,
        'august-gen'    => 7,
        'september-gen' => 8,
        'october-gen'   => 9,
        'november-gen'  => 10,
        'december-gen'  => 11,
        'jan' => 0,
        'feb' => 1,
        'mar' => 2,
        'apr' => 3,
        'may' => 4,
        'jun' => 5,
        'jul' => 6,
        'aug' => 7,
        'sep' => 8,
        'oct' => 9,
        'nov' => 10,
        'dec' => 11,
    );
    static $monthmsgs = array();
    static function init()
    {
        global $wgParser;
        foreach (self::$monthkeys as $key => $month)
            self::$monthmsgs[$key] = mb_strtolower(wfMsg($key));
        $wgParser->setHook('listfeed', array(__CLASS__, 'feedParse'));
        $wgParser->needPreSave['listfeed'] = true;
    }
    static function LoadExtensionSchemaUpdates()
    {
        $dbw = wfGetDB(DB_MASTER);
        if (!$dbw->tableExists('listfeed_items'))
            $dbw->sourceFile(dirname(__FILE__) . '/ListFeed.sql');
        return true;
    }
    static function feedParse($input, $args, $parser)
    {
        global $wgServer, $wgScript, $wgTitle, $egListFeedFeedUrlPrefix;
        $str = $parser->recursiveTagParse($input);
        // добавляем ссылку на ленту в статью
        $header =
            '<link rel="alternate" type="application/rss+xml" title="'.$args['name'].
            '" href="'.$egListFeedFeedUrlPrefix.'/'.str_replace(' ','_',$args['name']).
            '.rss'.'"></link><!-- LISTFEED_START --><!-- ';
        foreach ($args as $name => $value)
            $header .= htmlspecialchars($name).'="'.htmlspecialchars($value).'" ';
        $header .= '-->';
        return $header.$str.'<!-- LISTFEED_END -->';
    }
    static function falsemin($a, $b)
    {
        if ($a === false || $b !== false && $b < $a)
            return $b;
        return $a;
    }
    static function normalize_url($l, $pre)
    {
        if (!empty($l) && !empty($pre))
        {
            if (substr($l, 0, 2) == './')
                $l = substr($l, 2);
            if (!preg_match('#^[a-z0-9_]+://#is', $l))
            {
                if ($l{0} != '/')
                {
                    if ($pre{strlen($pre)-1} != '/')
                        $pre .= '/';
                    $l = $pre . $l;
                }
                else if (preg_match('#^[a-z0-9_]+://#is', $pre, $m))
                    $l = substr($pre, 0, strpos($pre, '/', strlen($m[0]))) . $l;
            }
            return $l;
        }
        return $l;
    }
    static function normalize_url_callback($m)
    {
        return $m[1].self::normalize_url($m[2], self::$urlbase);
    }
    static function ArticleSaveComplete(&$article, &$user, $text, $summary, $minoredit, $watchthis, $sectionanchor, &$flags, $revision)
    {
        global $wgParser, $egListFeedFeedDir, $egListFeedFeedUrlPrefix;
        if ($egListFeedFeedDir && preg_match('/<listfeed[^<>]*>/is', $text))
        {
            // получаем HTML-код статьи с абсолютными ссылками
            $srv = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') ? 'https' : 'http';
            $srv .= '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
            self::$urlbase = $srv;
            $options = new ParserOptions;
            $options->setTidy(true);
            $options->setEditSection(false);
            $options->setRemoveComments(false);
            if (is_null($text))
            {
                $article->loadContent();
                $text = $article->mContent;
            }
            if (is_null($revision) && $article->mRevision)
                $revision = $article->mRevision->getId();
            $oldShowToc = $wgParser->mShowToc;
            $wgParser->mShowToc = false;
            $html = $wgParser->parse($text, $article->getTitle(), $options, true, false, $revision)->getText();
            $wgParser->mShowToc = $oldShowToc;
            $html = preg_replace_callback('/(<(?:a|img)[^<>]*(?:href|src)=")([^<>"\']*)/is', array(__CLASS__, 'normalize_url_callback'), $html);
            // вытаскиваем и обновляем каналы
            $feeds = array();
            $e = 0;
            while (($p = strpos($html, '<!-- LISTFEED_START -->', $e+1)) !== false)
            {
                if (($e = strpos($html, '<!-- LISTFEED_END -->', $p+1)) === false)
                    break;
                $feeds[] = substr($html, $p+23, $e-$p-23);
            }
            foreach ($feeds as $feed)
            {
                // сначала параметры канала
                $args = array();
                if (preg_match('/^\s*<!--\s*(.*?)\s*-->/is', $feed, $s))
                {
                    preg_match_all('/([^=\s]+)="([^"]*)"/is', $s[1], $ar, PREG_SET_ORDER);
                    foreach ($ar as $i)
                        $args[html_entity_decode($i[1])] = html_entity_decode($i[2]);
                    $feed = substr($feed, strlen($s[0]));
                }
                if (!$args['name'])
                    continue;
                $date_re = '^[^:]*%H:%M(?::%S)?,\s*%d\s+%b\s+%Y';
                if ($args['date'])
                    $date_re = $args['date'];
                $headdate_re = '';
                if ($args['headingdate'])
                    $headdate_re = $args['headingdate'];
                // потом вытаскиваем элементы с учётом вложенных списков...
                $items = array();
                $feed = htmlspecialchars_decode($feed);
                $feed = preg_replace('#^(\s*</[^>]+>)+#is', '', $feed);
                $feed = preg_replace('#(<[^/][^>]*>\s*)+$#is', '', $feed);
                $in = 0;
                $s = 0;
                $lastend = 0;
                $defdate = array();
                while (($s = self::falsemin(strpos($feed, '<li', $s), strpos($feed, '</li', $s))) !== false)
                {
                    $pp = $s;
                    $neg = substr($feed, $s+1, 1) == '/' ? -1 : 1;
                    if (($s = strpos($feed, '>', $s)) === false)
                        break;
                    $s++;
                    if ($in == 0)
                        $start = $s;
                    $in += $neg;
                    if ($in == 0)
                    {
                        if ($headdate_re)
                        {
                            preg_match_all('/<h([1-6])(?:\s+[^<>]+)?>(.*?)<\/h\1\s*>/', substr($feed, $lastend, $start-$lastend), $m, PREG_PATTERN_ORDER);
                            foreach ($m[2] as $head)
                            {
                                $d = self::parse_date($head, $headdate_re, false, array(), false);
                                if (is_array($d))
                                    $defdate = $d;
                                else
                                    $defdate['epoch'] = $d;
                            }
                        }
                        $lastend = $pp;
                        $item = substr($feed, $start, $pp-$start);
                        // вытаскиваем заголовок, ссылку, хеш
                        if (($p = self::falsemin(strpos($item, '<dl>'), strpos($item, '<br>'))) !== false)
                            $title = substr($item, 0, $p);
                        else
                            $title = $item;
                        $title = strip_tags($title);
                        self::parse_date($title, $date_re, true);
                        $itemnosign = $item;
                        $d = self::parse_date($itemnosign, $date_re, true, $defdate);
                        if (preg_match('#<a[^<>]*href=["\']([^<>"\']+)["\']#is', $itemnosign, $p))
                            $link = $p[1];
                        else
                            $link = '';
                        $items[] = array(
                            feed  => $args['name'],
                            text  => $item,
                            title => $title,
                            link  => $link,
                            hash  => md5($itemnosign != $item ? $d.':'.$itemnosign : $item),
                            created => $d,
                        );
                    }
                }
                // всасываем старый список в $olditems
                $dbw = wfGetDB(DB_MASTER);
                $olditems = array();
                $res = $dbw->select('listfeed_items', '*', array('feed' => $args['name']), __METHOD__, array('ORDER BY' => 'created DESC'));
                while ($row = $dbw->fetchRow($res))
                    $olditems[] = $row;
                $dbw->freeResult($res);
                // сравниваем старый список элементов с новым
                $olditemsbyhash = array();
                for ($i = 0; $i < count($olditems); $i++)
                {
                    $olditems[$i][position] = $i;
                    $olditemsbyhash[$olditems[$i][hash]] = &$olditems[$i];
                }
                $add = array();
                $remove = array();
                $allnew = true;
                for ($k = 0; $k < count($items); $k++)
                {
                    if ($olditemsbyhash[$items[$k][hash]])
                    {
                        $olditemsbyhash[$items[$k][hash]][found] = true;
                        $items[$k][found] = true;
                        $allnew = false;
                    }
                }
                // если ни одного идентичного старым, значит все новые...
                if (!$allnew)
                {
                    // сначала ищем по контексту размера 1 вперёд
                    for ($k = 0; $k < count($items); $k++)
                    {
                        if (!$items[$k][found])
                        {
                            if ($k > 0 && ($p = $items[$k-1]) &&
                                $p[found] && ($np = $olditemsbyhash[$p[hash]][position]+1) < count($olditems) &&
                                !$olditems[$np][found])
                            {
                                if ($items[$k][created] == $olditems[$np][created])
                                    $olditemsbyhash[$items[$k][hash]] = $olditems[$np];
                                else
                                    $items[$k][created] = $olditems[$np][created];
                                $items[$k][modified] = time();
                                $items[$k][found] = true;
                                $olditems[$np][found] = true;
                                $remove[] = $olditems[$np][hash];
                                $add[] = $items[$k];
                            }
                        }
                    }
                    // потом назад
                    for ($k = count($items)-1; $k >= 0; $k--)
                    {
                        if (!$items[$k][found])
                        {
                            if ($k+1 < count($items) && ($p = $items[$k+1]) &&
                                $p[found] && ($np = $olditemsbyhash[$p[hash]][position]-1) >= 0 &&
                                !$olditems[$np][found])
                            {
                                if ($items[$k][created] == $olditems[$np][created])
                                    $olditemsbyhash[$items[$k][hash]] = $olditems[$np];
                                else
                                    $items[$k][created] = $olditems[$np][created];
                                $items[$k][modified] = time();
                                $items[$k][found] = true;
                                $olditems[$np][found] = true;
                                $remove[] = $olditems[$np][hash];
                                $add[] = $items[$k];
                            }
                        }
                    }
                }
                // и потом всё несопоставленное принимаем за новое
                for ($k = count($items)-1; $k >= 0; $k--)
                {
                    if (!$items[$k][found])
                    {
                        if (!$items[$k][created])
                            $items[$k][created] = time()-30;
                        if (!$items[$k][modified])
                            $items[$k][modified] = $items[$k][created];
                        $items[$k][author] = $user ? $user->getName() : '';
                        $items[$k][found] = true;
                        $add[] = $items[$k];
                    }
                }
                // удаляем $remove и добавляем $add
                foreach ($add as $i)
                {
                    unset($i[found]);
                    $dbw->insert('listfeed_items', $i, __METHOD__);
                }
                foreach ($remove as $i)
                    $dbw->delete('listfeed_items', array(feed => $args['name'], hash => $i), __METHOD__);
                // перезасасываем элементы из базы и генерируем статические RSS-файлы
                $items = array();
                $res = $dbw->select('listfeed_items', '*', array('feed' => $args['name']), __METHOD__, array('ORDER BY' => 'created DESC'));
                while ($row = $dbw->fetchRow($res))
                    $items[] = $row;
                $dbw->freeResult($res);
                $maxcreated = 0;
                foreach ($items as $i)
                    if ($i[created] > $maxcreated)
                        $maxcreated = $i[created];
                // генерируем RSS-ленту
                ob_start();
                $feedStream = new RSSFeedWithoutHttpHeaders($args['name'], $args['about'], $egListFeedFeedUrlPrefix.'/'.str_replace(' ','_',$args['name']).'.rss', $maxcreated);
                $feedStream->outHeader();
                foreach ($items as $item)
                    $feedStream->outItem(new FeedItem($item['title'], $item['text'], $item['link'], $item['created'], $item['author']));
                $feedStream->outFooter();
                $rss = ob_get_contents();
                ob_end_clean();
                // и сохраняем её в файл
                file_put_contents($egListFeedFeedDir.'/'.str_replace(' ','_',$args['name']).'.rss', $rss);
            }
        }
        return true;
    }
    static $date_pcre_cache;
    static function compile_date_re($date_re)
    {
        if ($date_pcre_cache[$date_re])
            return $date_pcre_cache[$date_re];
        $pcre = '';
        $argv = array();
        $d = $date_re;
        $t = array('H' => 'hour', 'h' => 'hour', 'M' => 'minute', 'm' => 'month', 'S' => 'second', 'C' => 'century', 'd' => 'day');
        while (preg_match('/^([^%]*)%(.)/s', $d, $m))
        {
            $pcre .= str_replace('/', '\\/', $m[1]);
            $d = substr($d, strlen($m[0]));
            if ($m[2] == 'H' || $m[2] == 'h' || $m[2] == 'M' ||
                $m[2] == 'm' || $m[2] == 'S' || $m[2] == 'C' ||
                $m[2] == 'd')
            {
                $pcre .= '(\d{2})';
                $argv[] = $t[$m[2]];
            }
            else if ($m[2] == 'Y')
            {
                $pcre .= '(\d{4})';
                $argv[] = 'year';
            }
            else if ($m[2] == 'y')
            {
                $pcre .= '(\d{2})';
                $argv[] = 'year2digit';
            }
            else if ($m[2] == 'b' || $m[2] == 'B')
            {
                $pcre .= '(\S+)';
                $argv[] = 'monthname';
            }
            else if ($m[2] == 'e')
            {
                $pcre .= '\s*(\d\d?)';
                $argv[] = 'day';
            }
            else if ($m[2] == 's')
            {
                $pcre .= '(\d+)';
                $argv[] = 'epoch';
            }
            else if ($m[2] == '%')
                $pcre .= '%';
            else
            {
                /* Error - unknown format character */
            }
        }
        $pcre .= str_replace('/', '\\/', $d);
        return $date_pcre_cache[$date_re] = array($pcre, $argv);
    }
    static function parse_date(&$text, $date_re, $strip = false, $def = array(), $anyway = true)
    {
        list($pcre, $argv) = self::compile_date_re($date_re);
        $val = array();
        if (preg_match("/$pcre/is", $text, $m, PREG_OFFSET_CAPTURE))
        {
            if ($strip)
                $text = mb_substr($text, 0, $m[0][1]) . mb_substr($text, $m[0][1]+mb_strlen($m[0][0]));
            for ($i = 1; $i < count($m); $i++)
                $val[$argv[$i-1]] = $m[$i][0];
        }
        if ($val['epoch'])
            return $val['epoch'];
        else if ($t = $def['epoch'])
        {
            $def['year']   = date('Y', $t);
            $def['month']  = date('m', $t);
            $def['day']    = date('d', $t);
            $def['hour']   = date('H', $t);
            $def['minute'] = date('i', $t);
            $def['second'] = date('s', $t);
        }
        if ($val['year'])
            $year = 0+$val['year'];
        else if (strlen($year = $val['year2digit']))
        {
            if ($val['century'])
                $year = $val['century'] . $year;
            else
            {
                $c = date('Y') % 100;
                if (($c < 50) == ($year < 50))
                    $year = $c . $year;
                else
                    $year = ($c-1) . $year;
            }
        }
        else if ($def['year'])
            $year = $def['year'];
        else if ($anyway)
            $year = date('Y');
        if ($val['month'])
            $month = 0+$val['month'];
        else if ($month = $val['monthname'])
        {
            $month = mb_strtolower($month);
            foreach (self::$monthmsgs as $key => $msg)
            {
                if ($month == $msg ||
                    $month == mb_substr($msg, 0, 3) ||
                    $month == $key)
                {
                    $month = self::$monthkeys[$key];
                    break;
                }
            }
            if (!is_numeric($month))
                $month = NULL;
        }
        if (!$month)
            $month = $def['month'];
        if (!$month && $anyway)
            $month = date('m');
        if ($val['day'])
            $day = 0+$val['day'];
        else if ($def['day'])
            $day = $def['day'];
        else if ($anyway)
            $day = date('d');
        foreach (array('hour', 'minute', 'second') as $s)
        {
            if ($val[$s])
                $$s = 0+$val[$s];
            else if ($def[$s])
                $$s = $def[$s];
            else
                $$s = 0;
        }
        if (!$anyway && (!$month || !$day || !$year))
            return array(
                'day'    => $day,
                'month'  => $month,
                'year'   => $year,
                'hour'   => $hour,
                'minute' => $minute,
                'second' => $second,
            );
        return mktime($hour, $minute, $second, $month, $day, $year);
    }
};

?>
