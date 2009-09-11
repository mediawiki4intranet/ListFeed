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
$wgHooks['ArticleSaveComplete'][] = 'fnListFeedArticleSaveComplete';
$wgHooks['LoadExtensionSchemaUpdates'][] = 'fnListFeedCheckSchema';
$wgExtensionFunctions[] = 'fnListFeedSetup';

if (!$egListFeedFeedUrlPrefix || !$egListFeedFeedDir)
    die('Please set $egListFeedFeedUrlPrefix (url location of rss feed directory) and $egListFeedFeedDir (its local filesystem location) in your LocalSettings.php');

$evListFeedMonthMsgKeys = array(
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

$evListFeedMonthMsgs = array();
function fnListFeedSetup()
{
    global $wgParser, $evListFeedMonthMsgKeys, $evListFeedMonthMsgs;
    foreach ($evListFeedMonthMsgKeys as $key => $month)
        $evListFeedMonthMsgs[$key] = wfMsg($key);
    $wgParser->setHook('listfeed', 'fnListFeedFeedParse');
}

function fnListFeedCheckSchema()
{
    $db = wfGetDB(DB_MASTER);
    if (!$db->tableExists('listfeed_items'))
        $db->sourceFile(dirname(__FILE__) . '/ListFeed.sql');
    return true;
}

function fnListFeedFeedParse($input, $args, $parser)
{
    global $wgServer, $wgScript, $wgTitle, $egListFeedFeedUrlPrefix;
    $str = $parser->recursiveTagParse($input);
    // добавляем ссылку на ленту в статью
    $header = '<link rel="alternate" type="application/rss+xml" title="'.$args['name'].'" href="'.$egListFeedFeedUrlPrefix.'/'.str_replace(' ','_',$args['name']).'.rss'.'"></link><!-- LISTFEED_START --><!-- ';
    foreach ($args as $name => $value)
        $header .= htmlspecialchars($name).'="'.htmlspecialchars($value).'" ';
    $header .= '-->';
    return $header.$str.'<!-- LISTFEED_END -->';
}

function falsemin($a, $b)
{
    if ($a === false || $b !== false && $b < $a)
        return $b;
    return $a;
}

function fnListFeedNormalizeUrl($m)
{
    global $evListFeedSRV;
    return $m[1].normalize_url($m[2], $evListFeedSRV);
}

function fnListFeedArticleSaveComplete (&$article, &$user, $text, $summary, $minoredit, $watchthis, $sectionanchor, &$flags, $revision)
{
    global $wgParser, $egListFeedFeedDir, $egListFeedFeedUrlPrefix, $evListFeedSRV;
    if ($egListFeedFeedDir && preg_match('/<listfeed[^<>]*>/is', $text))
    {
        // получаем HTML-код статьи с абсолютными ссылками
        $srv = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') ? 'https' : 'http';
        $srv .= '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $evListFeedSRV = $srv;
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
        $html = $wgParser->parse($text, $title, $options, true, true, $revision)->getText();
        $html = preg_replace_callback('/(<(?:a|img)[^<>]*(?:href|src)=")([^<>"\']*)/is', 'fnListFeedNormalizeUrl', $html);
        // вытаскиваем и обновляем каналы
        preg_match_all('/<!--\s*LISTFEED_START\s*-->(.*?)<!--\s*LISTFEED_END\s*-->/is', $html, $m, PREG_PATTERN_ORDER);
        foreach ($m[1] as $feed)
        {
            // сначала параметры канала
            $args = array();
            if (preg_match('/^\s*<!--\s*(.*?)\s*-->/is', $feed, $s))
            {
                preg_match_all('/([^=\s]+)="([^"]*)"/is', $s[1], $ar, PREG_SET_ORDER);
                foreach ($ar as $i)
                    $args[htmlspecialchars_decode($i[1])] = htmlspecialchars_decode($i[2]);
                $feed = substr($feed, strlen($s[0]));
            }
            if (!$args['name'])
                continue;
            // потом вытаскиваем элементы с учётом вложенных списков...
            $items = array();
            $feed = htmlspecialchars_decode($feed);
            $feed = preg_replace('#^(\s*</[^>]+>)+#is', '', $feed);
            $feed = preg_replace('#(<[^/][^>]*>\s*)+$#is', '', $feed);
            $in = 0;
            $s = 0;
            while (($s = falsemin(strpos($feed, '<li', $s), strpos($feed, '</li', $s))) !== false)
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
                    $item = substr($feed, $start, $pp-$start);
                    // вытаскиваем заголовок, ссылку, хеш
                    if (($p = falsemin(strpos($item, '<dl>'), strpos($item, '<br>'))) !== false)
                        $title = substr($item, 0, $p);
                    else
                        $title = $item;
                    $title = strip_tags($title);
                    $title = preg_replace('#^(\s*\d{2}\s*[\./]\s*\d{2}[\./]\s*\d{4}\s*|.*?\s+\d{2}:\d{2}(:\d{2})?\s*,\s*\d+\s+\S+\s+\d{4}\s*(\(\S+\))?):?\s*#is', '', $title);
                    $itemnosign = preg_replace('#^(\s*\d{2}\s*[\./]\s*\d{2}[\./]\s*\d{4}\s*|.*?\s+\d{2}:\d{2}(:\d{2})?\s*,\s*\d+\s+\S+\s+\d{4}\s*(\(\S+\))?):?\s*#is', '', $item);
                    if (preg_match('#<a[^<>]*href=["\']([^<>"\']+)["\']#is', $itemnosign, $p))
                        $link = $p[1];
                    else
                        $link = '';
                    $items[] = array(
                        feed  => $args['name'],
                        text  => $item,
                        title => $title,
                        link  => $link,
                        hash  => md5($item),
                    );
                }
            }
            // всасываем старый список в $olditems
            $dbr = wfGetDB(DB_MASTER);
            $olditems = array();
            $res = $dbr->select('listfeed_items', '*', array('feed' => $args['name']), __METHOD__, array('ORDER BY' => 'created DESC'));
            while ($row = $dbr->fetchRow($res))
                $olditems[] = $row;
            $dbr->freeResult($res);
            // сравниваем старый список элементов с новым
            $olditemsbyhash = array();
            for ($i = 0; $i < count($olditems); $i++)
            {
                $olditems[$i][position] = $i;
                $olditemsbyhash[$olditems[$i][hash]] = $olditems[$i];
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
                    if (preg_match('#\d{2}:\d{2}(:\d{2})?\s*,\s*\d+\s+\S+\s+\d{4}#is', $items[$k][text], $m))
                        $items[$k][created] = $items[$k][modified] = fnListFeedParseSignDate($m[0]);
                    if (!$items[$k][created])
                        $items[$k][created] = $items[$k][modified] = time()-30;
                    $items[$k][author] = $user ? $user->getName() : '';
                    $items[$k][found] = true;
                    $add[] = $items[$k];
                }
            }
            // удаляем $remove и добавляем $add
            foreach ($add as $i)
            {
                unset($i[found]);
                $dbr->insert('listfeed_items', $i, __METHOD__);
            }
            foreach ($remove as $i)
                $dbr->delete('listfeed_items', array(feed => $args['name'], hash => $i), __METHOD__);
            // перезасасываем элементы из базы и генерируем статические RSS-файлы
            $items = array();
            $res = $dbr->select('listfeed_items', '*', array('feed' => $args['name']), __METHOD__, array('ORDER BY' => 'created DESC'));
            while ($row = $dbr->fetchRow($res))
                $items[] = $row;
            $dbr->freeResult($res);
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

function normalize_url($l, $pre)
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

function fnListFeedParseSignDate($d)
{
    global $evListFeedMonthMsgKeys, $evListFeedMonthMsgs;
    if (preg_match('#(\d{2}):(\d{2})(?::(\d{2}))?\s*,\s*(\d+)\s+(\S+)\s+(\d{4})#is', $d, $m))
    {
        $month = false;
        foreach ($evListFeedMonthMsgs as $key => $msg)
        {
            if (strtolower($m[5]) == $msg || strtolower($m[5]) == $key)
            {
                $month = $evListFeedMonthMsgKeys[$key];
                break;
            }
        }
        if ($month === false)
            return 0;
        return mktime(0+$m[1], 0+$m[2], 0+$m[3], $month, 0+$m[4], 0+$m[6]);
    }
    return 0;
}

?>
