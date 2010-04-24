#!/usr/bin/env php
<?php

/**
 *  @author      Ben XO (me@ben-xo.com)
 *  @copyright   Copyright (c) 2010 Ben XO
 *  @license     MIT License (http://www.opensource.org/licenses/mit-license.html)
 *  
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is
 *  furnished to do so, subject to the following conditions:
 *  
 *  The above copyright notice and this permission notice shall be included in
 *  all copies or substantial portions of the Software.
 *  
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *  FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 *  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 *  THE SOFTWARE.
 */

require_once('keys.php');

class CastCloud
{
    protected $debug = false;
    protected $save_date = true;
    
    public function main($argc, array $argv)
    {
        error_reporting(E_ALL & ~E_STRICT & ~E_DEPRECATED);
        date_default_timezone_set('UTC');
        
        require_once('SimplePie/simplepie.class.php');
        require_once('php-soundcloud/oauth.php');
        require_once('php-soundcloud/soundcloud.php');
        
        try
        {
            $appname = array_shift($argv);
            while($arg = array_shift($argv))
            {
                if($arg == '--help')
                {
                    $this->usage($appname, $argv);
                    return;
                }
                
                if($arg == '--debug')
                {
                    $this->debug = true;
                    continue;
                }

                if($arg == '--dont-save-date')
                {
                    $this->save_date = false;
                    continue;
                }                
                
                if($arg == '--since')
                {
                    $last_published = new DateTime(array_shift($argv));
                    continue;
                }
                else
                {
                    $last_published = $this->getLastPublishedDate();
                }
                
                $podcast_url = $arg;
                break;
            }
            
            if(empty($podcast_url))
            {
                throw new InvalidArgumentException('No podcast URL supplied.');
            }
            
            $new_mp3s = $this->getNewMP3sFromRSS($podcast_url, $last_published);
            $this->downloadMP3s($new_mp3s);
            $this->publishToSoundcloud($new_mp3s);
            
            if($this->save_date) 
            {
                $this->saveLastPublishedDate(new DateTime());   
            }
            
        }
        catch(Exception $e)
        {   
            echo $e->getMessage() . "\n";  
            echo $e->getTraceAsString() . "\n";  
            $this->usage($appname, $argv);
        }
    }
        
    public function usage($appname, array $argv)
    {
        echo "Usage: {$appname} [--debug] [--since 'date'] [--dont-save-date] <podcast URL>\n";
    }

    /**
     * TODO: get this and save this!
     * 
     * @return DateTime
     */
    protected function getLastPublishedDate()
    {
        return new DateTime('2010-04-17 00:00:00 +0000');
    }

    /**
     * TODO: get this and save this!
     * 
     * @return DateTime
     */
    protected function saveLastPublishedDate(DateTime $dt)
    {
           return true;
    }
    
    protected function getNewMP3sFromRSS($podcast_url, DateTime $last_published)
    {
        $mp3s = array();
        
        $feed = $this->getRSS($podcast_url);
        foreach($feed->get_items() as $item)
        {
            /* @var $item SimplePie_Item */
            $item_date = new DateTime($item->get_date());
            $item_date_string = $item_date->format('Y-m-d H:i:s');
            if($item_date->format('U') >= $last_published->format('U'))
            {
                echo "New entry at $item_date_string: ";
                
            	/* @var $mp3 SimplePie_Enclosure */
                $mp3 = $item->get_enclosure(0);
                if($mp3)
                {
                    echo ">> " . $this->getLinkFromItem($item) . "\n";
                }
                else
                {
                    echo "!! No mp3 enclosure\n";
                }
                $mp3s[] = $item;
            }
        }
        
        return $mp3s;
    }
    
    protected function publishToSoundcloud(array $new_mp3s)
    {
        if(is_null(SC_TOKEN) && is_null(SC_TOKEN_SECRET))
        {
            $soundcloud = new Soundcloud(SC_CONSUMER_KEY, SC_CONSUMER_SECRET);
            $token = $soundcloud->get_request_token('http://a.callback.url.com/');
            echo ">> Your SC_TOKEN is : " . $token['oauth_token'] . "\n";
            echo ">> Your SC_TOKEN_SECRET is : " . $token['oauth_token_secret'] . "\n";
            
            $login = $soundcloud->get_authorize_url($token['oauth_token']);

            echo ">> Please fill in the file, and visit $login\n";
            return;
        }
        else
        {
            $sc = new Soundcloud(SC_CONSUMER_KEY, SC_CONSUMER_SECRET, SC_TOKEN, SC_TOKEN_SECRET);
            
            foreach($new_mp3s as $item)
            {
                /* @var $item SimplePie_Item */
                /* @var $mp3 SimplePie_Enclosure */
                
                $mp3 = $item->get_enclosure(0);
                $title = $item->get_title();
                
                echo "Publishing $title...\n";
                $filename = basename($this->getLinkFromItem($item));
                
                $post_data = array(
                    'track[title]' => $item->get_title(),
                    'track[asset_data]' => $filename,
                    'track[sharing]' => 'private',
                    'track[description]' => strip_tags($item->get_description())
                );
                                       
                $sc->upload_track($post_data);
            }
        }
    }
    
    /**
     * @return SimplePie
     */
    protected function getRSS($url)
    {
        $feed = new SimplePie();
        $feed->set_feed_url($url);
        $feed->init();
        return $feed;
    }
    
    protected function downloadMP3s(array $mp3s)
    {
        foreach($mp3s as $item)
        {
            /* @var $item SimplePie_Item */
            $link = $this->getLinkFromItem($item);
            $filename = basename($link);
            if(!file_exists($filename))
            {
                // TODO: replace this with an actual download...
                throw new RuntimeException("FILE MISSING: $filename");  
            }
        }
    }
    
    protected function getLinkFromItem(SimplePie_Item $i)
    {
        /* @var $e SimplePie_Enclosure */
        $e = $i->get_enclosure(0);
        if(empty($e))
        {
            return '';
        }
        
        return $e->get_link();
    }
}

$h = new CastCloud();
$h->main($argc, $argv);
