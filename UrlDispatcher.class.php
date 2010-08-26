<?php

/*
 *      UrlDispatcher.php
 * 
 *      A Django like URL dispatcher
 *      
 *      Copyright 2010 Kévin Gomez <geek63@gmail.com>
 *      
 *      This program is free software; you can redistribute it and/or modify
 *      it under the terms of the GNU General Public License as published by
 *      the Free Software Foundation; either version 2 of the License, or
 *      (at your option) any later version.
 *      
 *      This program is distributed in the hope that it will be useful,
 *      but WITHOUT ANY WARRANTY; without even the implied warranty of
 *      MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *      GNU General Public License for more details.
 *      
 *      You should have received a copy of the GNU General Public License
 *      along with this program; if not, write to the Free Software
 *      Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
 *      MA 02110-1301, USA.
 */


// raised when no match is found between patterns and the requested url
class Error404 extends Exception {
    
    protected $requested_url;
    
    public function __construct($requested_url)
    {
        $this->requested_url = $requested_url;
        $this->message = $requested_url.' not found';
    }
    
    public function getRequestedURL()
    {
        return $this->requested_url;
    }
}


// raised when the linked file is not found
class Error500 extends Exception { }


class Rule {
    
    const TYPE_CALLBACK = 1;
    const TYPE_FILE     = 2;
    
    protected $pattern;
    protected $target;
    protected $name;
    protected $url;
    protected $type = Null;  // cb (for callback) or file
    protected $extra_data = array();
    
    public function __construct($name, $pattern, $url, $target, $options)
    {
        $this->name = $name;
        $this->url = $url;
        $this->pattern = $pattern;
        $this->target = $target;
        $this->extra_data = $options;
    }
    
    /**
    * Merge an option array with the existing option array
    * !! Existing keys will be overwritten !!
    * 
    * @param &$array Option array ($key -> $value)
    * 
    * @return void
    */
    public function mergeExtraData(array $array)
    {
        $this->extra_data = array_merge($this->extra_data, $array);
    }
    
    /**
    * Get an option named $key
    * 
    * @param $key Option name
    * @param $default Defaut value if the $key option doesn't exists
    * 
    * @return void
    */
    public function getExtraData($key, $default)
    {
        return isset($this->extra_data[$key]) ? $this->extra_data[$key] : $default;
    }
    
    /**
     * Return the url corresponding to the rule
     * 
     * @param &$params The parameters to use to format the URL with
     * 
     * @return string
     */
    public function getURL(array $params=array())
    {
        return vsprintf($this->url, $params);
    }
    
    /**
    * Return the rewriting rule's name
    * 
    * @return string
    */
    public function getName()
    {
        return $this->name;
    }
    
    /**
    * Return the pattern
    * 
    * @return string
    */
    public function getPattern()
    {
        return $this->pattern;
    }
    
    /**
    * Return the target file or callback
    * 
    * @return string|callback
    */
    public function getTarget()
    {
        return $this->target;
    }
    
    /**
    * Return the target type (use class constants TYPE_FILE and
    * TYPE_CALLBACK to compare with the return value)
    * 
    * @return int (TYPE_FILE || TYPE_CALLBACK)
    **/
    public function gettargetType()
    {
        if($this->type !== Null)
            return $this->type;
        
        $callable_name = '';
        if(!is_callable($this->target, False, $callable_name))
            $this->type = self::TYPE_FILE;
        else
        {
            $this->type = self::TYPE_CALLBACK;
            $this->target = $callable_name;
        }
        
        return $this->type;
    }
}


class UrlDispatcher {
    
    protected $rules = array();
    protected $added_data = array();
    protected $auto_trailing_slash = True;
    protected $base_dir = '';
    protected $cur = Null;
    protected $files_dir = '';
    
    
    public function __construct(array $patterns=array(), $base_dir='', $files_dir='')
    {
        if(!empty($base_dir))
            $this->base_dir = $base_dir;
        
        if(!empty($patterns))
            $this->addPatterns($patterns);
        
        if(!empty($files_dir))
            $this->setFilesDir($files_dir);
    }
    
    /**
     * Accessor for the files' directory
     * 
     * @return string
     */
    public function getFilesDir()
    {
        return $this->files_dir;
    }
    
    /**
     * Define the files' directory
     * 
     * @throws Error500
     * 
     * @params $dir Directory in wich are located the files
     * 
     * @return void
     */
    public function setFilesDir($dir='')
    {
        if(!empty($dir) AND !is_dir($dir))
            throw new Error500('Directory « '.$dir.' » doesn\'t exist');
        
        $this->files_dir = rtrim($dir, '/');
    }
    
    /**
    *   Add mapping rules
    * 
    *   array $array_patterns :: rules to add
    *   
    *   return void
    **/
    public function addPatterns(array $array_patterns)
    {
        foreach($array_patterns as $name => $rule_data)
            $this->added_data[$name] = $rule_data;
    }
    
    /**
    * Add an unique Rule to the dispatcher
    * 
    * @param $name Rule's name
    * @param $data Rule's infos
    *   
    * @return void
    */
    public function addPattern($name, array $data)
    {
        $this->added_data[$name] = $data;
    }
    
    /**
     * Returns the URL corresponding to a rule
     * 
     * \warning Do not call this method before the handle() method has been
     *          called !
     * 
     * @param $name Rule's name
     * @param &$params The parameters to use to format the URL with
     * 
     * @return string
     */
    public function getURL($name, $params=array())
    {
        if(!isset($this->rules[$name]))
        {
            if(!isset($this->added_data[$name]))
                throw new Error500('No match found for a rule named « '.$name.' ».');
            
            $this->_makeRule($name, $this->added_data[$name]);
        }
        
        if(!is_array($params))
            $params = (array) $params;
        
        return $this->rules[$name]->getURL($params);
    }
    
    /**
     * Returns the current rule name
     * 
     * @return string
     */
    public function getCurrent()
    {
        return $this->cur;
    }
    
    /**
    * Used to create and stock a mapping rule with the given options
    * 
    * @param $name Rule name
    * @param $options Options (like this :
    *                   'rule-name' => array(
    *                                   'regex'  => '^foo/bar/(?P<baz>\d+)/barz/$',
    *                                   'url'    => 'foo/bar/(?P<baz>\d+)/barz/',
    *                                   'target' => 'some_folder/file.php',
    *                                   'action' => 'exec',
    *                                   'GET' => array('var' => True)
    *                            ),
    *                   'minimalist-rule' => array(
    *                                   'regex'  => '^foo/bar/$',
    *                                   'target' => 'foo.php' // the url will be guessed using the regex
    *                           )
    * 
    * @return Rule : the created rule
    */
    private function _makeRule($name, array $options)
    {
        static $needed_keys = array('regex', 'target');
        
        // all the needed keys are here
        foreach($needed_keys as $key)
            if(!isset($options[$key]))
                throw new Error500('Missing « '.$key.' » option for the rule « '.$name.' »');
        
        // check the existence of a rule with the same name
        if(isset($this->rules[$name]))
            throw new Error500('A rule named « '.$name.' » already exists.');
        
        // define the rule's url
        $url = isset($options['url']) ? $options['url'] : $this->guessURL($options['regex']);
        
        // try to parse the parameters to add to the rule
        $params = array();
        if(isset($options['params']))
        {
            if(!is_array($options['params']))
                throw new Error500('Rule « '.$name.' » is malformed (« params » parameter must be an array).');
            
            $params = $options['params'];
        }
        
        if(isset($options['GET']))
        {
            if(!is_array($options['GET']))
                throw new Error500('Rule « '.$name.' » is malformed (« GET » parameter must be an array).');
            
            $params['GET'] = $options['GET'];
        }
        
        $rule = new Rule($name, $options['regex'], $url, $options['target'], $params);
        
        $this->rules[$name] = $rule;
        
        return $rule;
    }

    /**
    * We handle the requested URl with the previously given patterns.
    * 
    * string $requested_url :: requested URL
    * bool $args_in_GET :: if True, matched params will be merged with
    *                      $_GET. Else they'll be send to a callback
    *   
    * return void
    */
    public function handle($requested_url='', $args_in_GET=False)
    {
        if(empty($requested_url))
            $requested_url = $_SERVER['REQUEST_URI'];
        
        $requested_url = $this->_parseURL($requested_url);
        
        // we add the trailing slash to the URL (if needed and asked)
        if($this->isAutoTrailingSlash())
            $requested_url = $this->_addTrailingSlash($requested_url);
        
        foreach($this->added_data as $name => $data)
        {
            $rule = $this->_makeRule($name, $data);
            $matches = array();
            
            // if the URL doesn't match the pattern -> next !
            if(!preg_match('`'.$rule->getPattern().'`', $requested_url, $matches))
                continue;
            
            // we define the current accepted rule
            $this->cur = $name;
            
            // we "clean" the parameters
            $args = $this->_filterCallbackParams($matches);
            // we add the "params" to the matched parameters
            $get_args = array_merge($args, $rule->getExtraData('GET', array()));
            $_GET = $_GET ?: array();
            
            if($rule->getTargetType() == Rule::TYPE_CALLBACK)
            {
                // we call ... the callback !
                if(!$args_in_GET OR $rule->getExtraData('args_in_get', True) === False)
                    // we send the params to the callback
                    call_user_func_array($rule->getTarget(), $args);
                else
                {
                    // or we put the params into $_GET
                    $_GET = array_merge($_GET, $get_args);
                    
                    call_user_func($rule->getTarget());
                }
            }
            // a file is associated with the pattern
            else
            {
                $file = (!$this->files_dir ? '' : $this->files_dir.'/').$rule->getTarget();
                
                if(!is_file($file))
                    throw new Error500('file « '.$file.' » not found.');
                
                // read the file
                if($rule->getExtraData('action', 'exec') == 'read')
                    echo file_get_contents($file);
                // default: exec the file (we always populate $_GET in this case)
                else
                {
                    // we put the params into $_GET
                    $_GET = array_merge($_GET, $get_args);
                    
                    require $file;
                }
            }
            
            return;
        }
        
        throw new Error404($requested_url);
    }
    
    /**
    *   Allow to enable or disable the automatic trailing slash adding
    * 
    *   bool $bool :: activation state
    * 
    *   return void
    **/
    public function setAutoTrailingSlash($bool)
    {
        $this->auto_trailing_slash = (bool) $bool;
    }
    
    /**
    *   Return the activation state of the automatic trailing slash adding
    * 
    *   return bool
    **/
    public function isAutoTrailingSlash()
    {
        return $this->auto_trailing_slash;
    }
    
    /**
    *   Create a wordpress-like htaccess
    * 
    *   string $base_dir :: website's root dir
    *   string $receiver_file :: file wich will receive the redirected
    *                            requests (index.php is fine)
    * 
    *   return string
    **/
    public static function createHtaccess($base_dir='', $receiver_file='index.php')
    {
        if(!empty($base_dir) AND substr($base_dir, -1) == '/')
            $base_dir = substr($base_dir, 0, -1); // we delete the ending /
        
        $receiver_file = implode('/', array($base_dir, $receiver_file));
        
        return <<<TXT
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . $receiver_file [L]
</IfModule>
TXT;
    }
    
    
    /**
    *
    *   Parse the query string in the URL (?foo=bar&baz) and store the
    *   parsed data in $_GET
    * 
    *   return string :: the url without the query string
    **/
    private function _parseURL($url)
    {
        // to delete $this->base_dir in $requested_url
        $url = (empty($this->base_dir) OR $this->base_dir == '/') ? substr($url, 1) /* remove the starting / */ 
                                      : str_replace($this->base_dir, '', $url);
        
        $data = explode('?', $url);
        
        if(isset($data[1]))
            parse_str($data[1], $_GET);
        
        return $data[0];
    }
    
    /**
    *   Add a trailing slash (only if needed)
    * 
    *   string $url :: an URL
    * 
    *   return string :: the same URL (+ /)
    **/
    private function _addTrailingSlash($url)
    {
        return (substr($url, -1) == '/') ? $url : $url.'/';
    }
    
    /**
    *   Clean an array from its numeric keys
    * 
    *   array $params :: array to clean
    * 
    *   return array :: array cleaned
    **/
    private function _filterCallbackParams(array $params)
    {
        foreach($params as $key => $value)
            if(is_int($key))
                unset($params[$key]);
        
        return $params;
    }
    
    /**
     * Try to guess the URL corresponding to a pattern (it just try removing
     * the starting "^" and the ending "$")
     * 
     * \todo Do some checks !
     * 
     * @param $pattern Pattern to use to guess the URL
     * 
     * @return string
     */
    private function guessURL($pattern)
    {
        $url = substr($pattern, 0, -1); // we remove the $
        $url = substr($url, 1); // we remove the ^
        
        return $url;
    }
}