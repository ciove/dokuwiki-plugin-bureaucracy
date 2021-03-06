<?php
/**
 * Simple template replacement action for the bureaucracy plugin
 *
 * @author Michael Klier <chi@chimeric.de>
 */

class syntax_plugin_bureaucracy_action_template extends syntax_plugin_bureaucracy_action {

    function run($data, $thanks, $argv) {
        global $ID;
        global $conf;
        global $USERINFO;

        list($tpl, $pagename, $sep) = $argv;
        if(is_null($sep)) $sep = $conf['sepchar'];

        $runas = $this->getConf('runas');
        $patterns = array();
        $values   = array();
        $templates = array();
        // run through fields
        foreach($data as $opt) {
            $label = $opt->getParam('label');
            $value = $opt->getParam('value');

            // prepare replacements
            if(!is_null($label)) {
                $patterns[$label] = '/(@@|##)' . preg_quote($label, '/') .
                              '(?:\|(.*?))' . (is_null($value) ? '' : '?') .
                              '\1/si';
                $values[$label] = is_null($value) ? '$2' : $value;
            }

            // handle pagenames
            $pname = $opt->getParam('pagename');
            if(!is_null($pname)){
                $pagename .= $sep . $pname;
            }

            if (!is_null($opt->getParam('page_tpl')) &&
                !is_null($opt->getParam('page_tgt'))) {
                $page_tpl = $this->replace($patterns, $values, $opt->getParam('page_tpl'));
                if (auth_aclcheck($page_tpl, $runas ? $runas : $_SERVER['REMOTE_USER'],
                                  $USERINFO['grps']) >= AUTH_READ) {
                    $templates[$opt->getParam('page_tgt')] = rawWiki($page_tpl);
                }
            }
        }

        $pagename = $this->replace($patterns, $values, $pagename);
        // check pagename
        $pagename = cleanID($pagename);
        if ($pagename === '') {
            throw new Exception($this->getLang('e_pagename'));
        }
        if(page_exists($pagename)) {
            throw new Exception(sprintf($this->getLang('e_pageexists'), html_wikilink($pagename)));
        }

        $_templates = array();
        foreach($templates as $k => $v) {
            $_templates[cleanID("$pagename:$k")] = $v;
        }
        $templates = $_templates;

        // check auth
        if($runas){
            $auth = auth_aclcheck($pagename,$runas,array());
        }else{
            $auth = auth_quickaclcheck($pagename);
        }
        if($auth < AUTH_CREATE) {
            throw new Exception($this->getLang('e_denied'));
        }

        // get templates
        if($tpl == '_'){
            // use namespace template
            if (!isset($templates[$pagename])) {
                $templates[$pagename] = pageTemplate(array($pagename));
            }
        } elseif($tpl !== '!') {
            // Namespace link
            require_once DOKU_INC.'inc/search.php';
            if ($runas) {
                // Hack user credentials.
                global $USERINFO;
                $backup = array($_SERVER['REMOTE_USER'],$USERINFO['grps']);
                $_SERVER['REMOTE_USER'] = $runas;
                $USERINFO['grps'] = array();
            }
            $t_pages = array();
            search($t_pages, $conf['datadir'], 'search_universal',
                   array('depth' => 0, 'listfiles' => true),
                   str_replace(':', '/', getNS($tpl)));
            foreach($t_pages as $t_page) {
                $t_name = cleanID($t_page['id']);
                $p_name = preg_replace('/^' . preg_quote_cb(cleanID($tpl)) . '($|:)/', $pagename . '$1', $t_name);
                if ($p_name === $t_name) {
                    // When using a single-page template, ignore other pages
                    // in the same namespace.
                    continue;
                }
                if (!isset($templates[$p_name])) {
                    $templates[$p_name] = rawWiki($t_name);
                }
            }

            if ($runas) {
                /* Restore user credentials. */
                global $USERINFO;
                list($_SERVER['REMOTE_USER'],$USERINFO['grps']) = $backup;
            }
        }
        if(empty($templates)) {
            throw new Exception(sprintf($this->getLang('e_template'), $tpl));
        }

        foreach($templates as $pname => $template) {
            // save page
            saveWikiText($pname,
                         $this->replace($patterns, $values, $template),
                         sprintf($this->getLang('summary'), $ID));
        }

        // Build result tree
        $pages = array_keys($templates);
        usort($pages, array($this, '_sort'));

        $data = array();
        $last_folder = array();
        foreach($pages as $page) {
            $lvl = substr_count($page, ':');
            for ($n = 0 ; $n < $lvl ; ++$n) {
                if (!isset($last_folder[$n]) || strpos($page, $last_folder[$n]['id']) !== 0) {
                    $last_folder[$n] = array('id' => substr($page, 0, strpos($page, ':', ($n > 0 ? strlen($last_folder[$n - 1]['id']) : 0) + 1) + 1),
                                             'level' => $n + 1,
                                             'open' => 1);
                    $data[] = $last_folder[$n];
                }
            }
            $data[] = array('id' => $page, 'level' => 1 + substr_count($page, ':'), 'type' => 'f');
        }
        return '<p>' . $thanks . '</p>' . html_buildlist($data, 'idx', array($this, 'html_list_index'), 'html_li_index');
    }

    static function _sort($a, $b) {
        $ns_diff = substr_count($a, ':') - substr_count($b, ':');
        return ($ns_diff === 0) ? strcmp($a, $b) : ($ns_diff > 0 ? -1 : 1);
    }

    static function html_list_index($item){
        global $ID;
        $ret = '';
        $base = ':'.$item['id'];
        $base = substr($base,strrpos($base,':')+1);
        if($item['type']=='f'){
            $ret .= html_wikilink(':'.$item['id']);
        } else {
            $ret .= '<strong>' . trim(substr($item['id'], strrpos($item['id'], ':', -2)), ':') . '</strong>';
        }
        return $ret;
    }

    function replace($patterns, $values, $input) {
        return preg_replace_callback('/%./',
                                     create_function('$m','return strftime($m[0]);'),
                                     preg_replace($patterns, $values, $input));

    }

}
// vim:ts=4:sw=4:et:enc=utf-8:
