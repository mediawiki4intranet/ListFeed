<?php

/**
 * MediaWiki ListFeed extension
 * Copyright © 2009+ Vitaliy Filippov
 * http://wiki.4intra.net/ListFeed
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 59 Temple Place - Suite 330, Boston, MA 02111-1307, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */

/*

INSTALLATION

$egListFeedFeedUrlPrefix = "<URL location of generated static rss directory>"; // default "$wgScriptPath/extensions/ListFeed/rss"
$egListFeedFeedDir = "<Filesystem location of generated static rss directory>"; // default "$IP/extensions/ListFeed/rss"
require_once("$IP/extensions/ListFeed/ListFeed.php");

USAGE

This extension enables two new tags - <listfeed> and <endlistfeed>, and a
parser function - {{#listfeedurl:Feed Name}} which gives a URL location for feed
with a given name. With ListFeed, you can convert any bullet-list or numbered list
into an RSS feed which is automatically updated on page changes.

Feeds are identified by their names. So two <listfeed>'s with the same name will
overwrite each other on each page update.

To use ListFeed, you must add the following _before_ your list:
<listfeed name="<FEED NAME>" [OPTIONS]>
FEED DESCRIPTION (any wikitext - it gets parsed to HTML code and included into RSS feed <about> element)
</listfeed>
And also add the following _after_ your list:
<endlistfeed />

You can then use parser function {{#listfeedurl:<FEED NAME>}} to get the URL location of
your newly generated feed.

OPTIONS include:

date="<DATE REGEXP>"
    Specify custom regular expression for parsing item dates.
    DATE REGEXP is a special kind of regular expression - besides standard PCRE instructions,
    it can include strftime(2)-like format specifiers.

    The following format specifiers are supported by now:
    %Y              year (4 digits)
    %y              year (2 digits)
    %C              century (2 digits), optionally used in conjunction with %y and defaulted to current
    %m              month (number 1-12)
    %b or %B        month name - either long (January) or short (Jan)
    %d              day of month (2 digits)
    %e              day of month (1 or 2 digits)
    %H or %h        hour
    %M              minute
    %S              second
    %s              UNIX time - number of seconds passed since epoch (1970-01-01 00:00:00)
    %%              % character

headingdate="<DATE REGEXP>"
    Dates, or their parts, could also be specified between list items.
    Imagine an article with the following text:

    <listfeed name="News of extension" date="^%b %d:\s*" headingdate="%Y" />
    = 2010 news =
    * Jan 13: New version of ListFeed.
    * Jan 01: Happy New Year!
    = 2009 news =
    * Dec 17: ...
    * ...
    <endlistfeed />

    Here, item dates could not be taken from item text. So we also need to parse the text
    _between_ items and try to match headings taken from it against DATE REGEXP.
    For the example described above, headingdate="%Y" is absolutely correct.

*/

$wgExtensionCredits['parserhook'][] = array(
    'name'           => 'List Feed',
    'version'        => '1.12 (2012-02-02)',
    'author'         => 'Vitaliy Filippov',
    'url'            => 'http://wiki.4intra.net/ListFeed',
    'description'    => 'Allows to export Wiki lists into (static) RSS feeds with a minimal effort',
    'descriptionmsg' => 'listfeed-desc',
);
$wgExtensionMessagesFiles['ListFeed'] = dirname(__FILE__) . '/ListFeed.i18n.php';
$wgExtensionFunctions[] = 'MWListFeed::init';
$wgHooks['ParserFirstCallInit'][] = 'MWListFeed::initParser';
$wgHooks['ArticleSaveComplete'][] = 'MWListFeed::ArticleSaveComplete';

$egListFeedFeedUrlPrefix = "$wgScriptPath/extensions/ListFeed/rss";
$egListFeedFeedDir = "$IP/extensions/ListFeed/rss";

class RSSFeedWithoutHttpHeaders extends RSSFeed
{
    function httpHeaders() {}
}

class MWListFeed
{
    static $urlbase;
    static $monthkeys = array(
        'january'       => 1,
        'february'      => 2,
        'march'         => 3,
        'april'         => 4,
        'may_long'      => 5,
        'june'          => 6,
        'july'          => 7,
        'august'        => 8,
        'september'     => 9,
        'october'       => 10,
        'november'      => 11,
        'december'      => 12,
        'january-gen'   => 1,
        'february-gen'  => 2,
        'march-gen'     => 3,
        'april-gen'     => 4,
        'may-gen'       => 5,
        'june-gen'      => 6,
        'july-gen'      => 7,
        'august-gen'    => 8,
        'september-gen' => 9,
        'october-gen'   => 10,
        'november-gen'  => 11,
        'december-gen'  => 12,
        'jan' => 1,
        'feb' => 2,
        'mar' => 3,
        'apr' => 4,
        'may' => 5,
        'jun' => 6,
        'jul' => 7,
        'aug' => 8,
        'sep' => 9,
        'oct' => 10,
        'nov' => 11,
        'dec' => 12,
    );
    static $monthmsgs = array();
    static function init()
    {
        wfLoadExtensionMessages('ListFeed');
        foreach (self::$monthkeys as $key => $month)
            self::$monthmsgs[$key] = mb_strtolower(wfMsgReal($key, array(), false));
    }
    static function initParser($parser)
    {
        $parser->setHook('listfeed', array(__CLASS__, 'tag_listfeed'));
        $parser->setHook('endlistfeed', array(__CLASS__, 'tag_endlistfeed'));
        $parser->setFunctionHook('listfeedurl', array('MWListFeed', 'feedUrl'));
        return true;
    }
    static function feedFn($name, $prefix = NULL)
    {
        if (!$prefix)
        {
            global $egListFeedFeedDir;
            $prefix = $egListFeedFeedDir;
            if (!$prefix)
                $prefix = dirname(__FILE__).'/rss';
        }
        $prefix = preg_replace('#/+$#', '', $prefix);
        // preg_replace не работает как положено :-( не понимает юникодные классы символов
        mb_regex_encoding('utf-8');
        $name = mb_eregi_replace('[[:^alnum:]]+', '_', $name);
        $name = mb_eregi_replace('^_|_$', '', $name);
        $name = $prefix.'/'.$name.'.rss';
        return $name;
    }
    static function feedUrl($parser, $name)
    {
        global $egListFeedFeedUrlPrefix, $wgScriptPath, $wgServer;
        $p = $egListFeedFeedUrlPrefix;
        if (!$p)
        {
            $p = $wgServer . $wgScriptPath . '/extensions/ListFeed/rss';
            @mkdir(dirname(__FILE__).'/rss');
        }
        return self::feedFn($name, $p);
    }
    static function tag_listfeed($input, $args, $parser)
    {
        $r = '<link rel="alternate" type="application/rss+xml" title="'.$args['name'].
             '" href="'.self::feedUrl($parser, $args['name']).'"></link><!-- listfeed ';
        foreach ($args as $name => $value)
            $r .= htmlspecialchars($name).'="'.htmlspecialchars($value).'" ';
        $r .= '-->';
        if ($input)
            $r .= '<!-- listfeed_description -->'.$parser->recursiveTagParse($input).'<!-- /listfeed_description -->';
        return $r;
    }
    static function tag_endlistfeed($input, $args, $parser)
    {
        return '<!-- /listfeed -->';
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
                elseif (preg_match('#^[a-z0-9_]+://#is', $pre, $m))
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
        global $wgParser, $wgContLang;
        if (preg_match('/<listfeed[^<>]*>/is', $text))
        {
            // получаем HTML-код статьи с абсолютными ссылками
            $srv = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') ? 'https' : 'http';
            $srv .= '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
            self::$urlbase = $srv;
            $options = new ParserOptions;
            $options->setTidy(true);
            $options->setEditSection(false);
            $options->setRemoveComments(false);
            $options->setNumberHeadings(false);
            if (is_null($text))
            {
                $article->loadContent();
                $text = $article->mContent;
            }
            if (is_null($revision) && $article->mRevision)
                $revision = $article->mRevision->getId();
            $feedParser = clone $wgParser;
            $feedParser->mShowToc = false;
            $html = $feedParser->parse($text, $article->getTitle(), $options, true, true, $revision)->getText();
            $html = preg_replace_callback('/(<(?:a|img)[^<>]*(?:href|src)=")([^<>"\']*)/is', array(__CLASS__, 'normalize_url_callback'), $html);
            // вытаскиваем и обновляем каналы
            $feeds = array();
            $e = -1;
            while (preg_match('#<!--\s*listfeed\s*([^<>]*?)-->#is', $html, $m, PREG_OFFSET_CAPTURE, $e+1))
            {
                $p = $m[0][1];
                $prop = $m[1][0];
                $about = '';
                if (!preg_match('#<!--\s*/listfeed\s*-->#is', $html, $m, PREG_OFFSET_CAPTURE, $p+1))
                    break;
                $e = $m[0][1];
                $feed = substr($html, $p, $e-$p);
                if (preg_match('#<!--\s*listfeed_description\s*-->(.*)<!--\s*/listfeed_description\s*-->#is', $feed, $m, PREG_OFFSET_CAPTURE))
                {
                    $feed = substr($feed, 0, $m[0][1]) . substr($feed, $m[0][1]+strlen($m[0][0]));
                    $about = $m[1][0];
                }
                $feeds[] = array($feed, $prop, $about);
            }
            foreach ($feeds as $feed)
            {
                // сначала параметры канала
                $args = array();
                preg_match_all('/([^=\s]+)="([^"]*)"/is', $feed[1], $ar, PREG_SET_ORDER);
                foreach ($ar as $i)
                    $args[html_entity_decode($i[1])] = html_entity_decode($i[2]);
                if ($feed[2])
                    $args['about'] = $feed[2];
                $feed = $feed[0];
                if (!$args['name'])
                    continue;
                $date_re = '^(?:[^:]+|<[^<>]*>)*%H:%M(?::%S)?[\s,]*%e\s+%b\s+%Y(?:\s*\([A-Z]{3}\))?(?:\s*:?)';
                if (isset($args['date']))
                    $date_re = $args['date'];
                $headdate_re = '';
                if (isset($args['headingdate']))
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
                $i = 0;
                $maxdate = 0;
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
                                $d = self::parse_date($head, $headdate_re, false, $defdate, false);
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
                        if (!$d)
                            $d = wfTimestamp(TS_UNIX, $article->getTimestamp());
                        $hash = md5($itemnosign != $item ? $d.':'.$itemnosign : $item);
                        if (preg_match('#<a[^<>]*href=["\']([^<>"\']+)["\']#is', $itemnosign, $p))
                            $link = $p[1];
                        else
                            $link = $article->getTitle()->getFullUrl().'#feeditem'.$hash;
                        if ($d > $maxdate)
                            $maxdate = $d;
                        $author = '';
                        if (preg_match('#<a[^<>]*href=["\'][^<>\'"\s]*'.preg_quote(urlencode($wgContLang->getNsText(NS_USER))).':([^<>\'"\s\#]*)#is', $item, $m))
                            $author = urldecode($m[1]);
                        if (!$author && $user)
                            $author = $user->getName();
                        $items[] = array(
                            'feed'     => $args['name'],
                            'text'     => $item,
                            'title'    => $title,
                            'link'     => $link,
                            'hash'     => $hash,
                            'created'  => $d,
                            'modified' => $d,
                            'author'   => $author,
                            'i'        => $i++,
                        );
                    }
                }
                // сортируем элементы по убыванию даты
                usort($items, 'MWListFeed::item_compare');
                // генерируем RSS-ленту
                ob_start();
                $feedStream = new RSSFeedWithoutHttpHeaders($args['name'], $args['about'], self::feedUrl($wgParser, $args['name']), $maxdate);
                $feedStream->outHeader();
                foreach ($items as $item)
                    $feedStream->outItem(new FeedItem($item['title'], $item['text'], $item['link'], $item['created'], $item['author']));
                $feedStream->outFooter();
                $rss = ob_get_contents();
                ob_end_clean();
                // и сохраняем её в файл
                file_put_contents(self::feedFn($args['name']), $rss);
            }
        }
        return true;
    }
    // функция сравнения дат элементов
    static function item_compare($a, $b)
    {
        if ($a['created'] < $b['created'])
            return 1;
        elseif ($a['created'] > $b['created'])
            return -1;
        if ($a['i'] < $b['i'])
            return 1;
        elseif ($a['i'] > $b['i'])
            return -1;
        return 0;
    }
    static $date_pcre_cache;
    static function compile_date_re($date_re)
    {
        if (self::$date_pcre_cache[$date_re])
            return self::$date_pcre_cache[$date_re];
        // сначала определяем занятые ключи
        preg_match_all('/'.str_replace('/','\\/',$date_re).'/', '', $nonfree, PREG_PATTERN_ORDER);
        $key = 1;
        $pcre = '';
        $argv = array();
        $d = $date_re;
        $t = array('H' => 'hour', 'h' => 'hour', 'M' => 'minute', 'm' => 'month', 'S' => 'second', 'C' => 'century', 'd' => 'day');
        while (preg_match('/^([^%]*)%(.)/s', $d, $m))
        {
            while (array_key_exists('d'.$key, $nonfree))
                $key++;
            $arg = false;
            $pcre .= str_replace('/', '\\/', $m[1]);
            $d = substr($d, strlen($m[0]));
            if ($m[2] == 'H' || $m[2] == 'h' || $m[2] == 'M' ||
                $m[2] == 'm' || $m[2] == 'S' || $m[2] == 'C' ||
                $m[2] == 'd')
            {
                $pcre .= "(?<d$key>\\d{2})";
                $arg = $t[$m[2]];
            }
            elseif ($m[2] == 'Y')
            {
                $pcre .= "(?<d$key>\\d{4})";
                $arg = 'year';
            }
            elseif ($m[2] == 'y')
            {
                $pcre .= "(?<d$key>\\d{2})";
                $arg = 'year2digit';
            }
            elseif ($m[2] == 'b' || $m[2] == 'B')
            {
                $pcre .= "(?<d$key>\\S+)";
                $arg = 'monthname';
            }
            elseif ($m[2] == 'e')
            {
                $pcre .= "\s*(?<d$key>\\d\\d?)";
                $arg = 'day';
            }
            elseif ($m[2] == 's')
            {
                $pcre .= "(?<d$key>\\d+)";
                $arg = 'epoch';
            }
            elseif ($m[2] == '%')
                $pcre .= '%';
            else
            {
                /* Error - unknown format character */
            }
            if ($arg)
            {
                $argv[$key] = $arg;
                $key++;
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
            foreach ($argv as $k => $v)
                if (strlen($m[$k][0]))
                    $val[$v] = $m[$k][0];
        }
        if (!$val)
        {
            /* TODO желательно показывать это как-то в видимом месте. Например, в RSS'ке. */
            wfDebug(__CLASS__.": Unparsed date text: $text, date regexp is $date_re\n");
        }
        if (isset($val['epoch']))
            return $val['epoch'];
        elseif (isset($def['epoch']))
        {
            $t = $def['epoch'];
            $def['year']   = date('Y', $t);
            $def['month']  = date('m', $t);
            $def['day']    = date('d', $t);
            $def['hour']   = date('H', $t);
            $def['minute'] = date('i', $t);
            $def['second'] = date('s', $t);
        }
        if (isset($val['year']))
            $year = 0+$val['year'];
        elseif (isset($val['year2digit']))
        {
            $year = $val['year2digit'];
            if (isset($val['century']))
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
        elseif (isset($def['year']))
            $year = $def['year'];
        elseif ($anyway)
            $year = date('Y');
        $month = NULL;
        if (isset($val['month']))
            $month = 0+$val['month'];
        elseif (isset($val['monthname']))
        {
            $month = mb_strtolower($val['monthname']);
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
        if (!$month && isset($def['month']))
            $month = $def['month'];
        if (!$month && $anyway)
            $month = date('m');
        $day = NULL;
        if (isset($val['day']))
            $day = 0+$val['day'];
        elseif (isset($def['day']))
            $day = $def['day'];
        elseif ($anyway)
            $day = date('d');
        foreach (array('hour', 'minute', 'second') as $s)
        {
            if (isset($val[$s]))
                $$s = 0+$val[$s];
            elseif (isset($def[$s]))
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
