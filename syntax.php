<?php
/**
 * Plugin SQL:  executes SQL queries
 * 
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Slim Amamou <slim.amamou@gmail.com>
 */
 
if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../').'/');
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');
require_once(DOKU_INC.'inc/parserutils.php');
require_once('MDB2.php');
 
function property($prop, $xml)
{
	#print('PROPERTY: PROP='.$prop.', XML=' . $xml . "\n" . '<br/>');
	$match = FALSE;
	$pattern = $prop ."='([^']*)')";
	if (ereg($pattern, $xml, $matches)) {
		$match = $matches[1];
	}
	$pattern = $prop .'="([^"]*)"';
	if (ereg($pattern, $xml, $matches)) {
		$match = $matches[1];
	}
	return $match;
}

function _read_conf($filename) {
	$result = array();
	#print( '_read_conf(filename=' . $filename . ');<br/>' );

	$lines = file($filename, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
	#print( 'LINES='); var_dump($lines);
	foreach ($lines as $l) {
		$field = explode('=', $l, 2);
		$key = trim($field[0]);
		$val = trim($field[1]);
		$result[$key] = $val;
	}
	return $result;
}
 
/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class syntax_plugin_sql extends DokuWiki_Syntax_Plugin {
    var $databases = array();
	var $wikitext_enabled = TRUE;
	var $display_inline = FALSE;
	var $vertical_position = FALSE;
  
    /**
     * What kind of syntax are we?
     */
    function getType(){
        return 'substition';
    }
	 
    /**
     * Where to sort in?
     */ 
    function getSort(){
        return 555;
    }
 
 
    /**
     * Connect pattern to lexer
	 */
	# Usage:  <sql db=“fnt”>select * from table</sql>
	function connectTo($mode) {
		#FIXME: fix the regex for parsing the options
		$this->Lexer->addEntryPattern('<sql\b(?:\s+(?:db|par2)="[^">\r\n]*")*\s*>(?:.*?</sql>)', $mode,'plugin_sql');
    }
	
    function postConnect() {
      $this->Lexer->addExitPattern('</sql>','plugin_sql');
    }


    /**
     * Handle the match
     */
	function handle($match, $state, $pos, Doku_Handler $handler) {
		$data = array();
        switch ($state) {
          case DOKU_LEXER_ENTER : 
			$dsnid = property('db', $match);
			$wikitext = property('wikitext', $match);
			$display = property('display', $match);
			$position = property('position', $match);

			# from $match get out the SQL statement
			$sql = '';
			$pattern = "/>(.*?)<\/sql>/m";
			$rt = preg_match($pattern, $match, $matches);
			if ($rt == FALSE) {
				#print("\n<br/>ERROR: pattern match failed<br>\n");
				return array();
			}
			$sql = trim($matches[1]);

			# get DB data from file
			# FIXME: check for file existence and readability
			$conf_file = '/etc/opt/dw-plugin-sql/'."$dsnid".'.conf';
			$connect_data = _read_conf($conf_file);
			$data['dsn'] = $connect_data;
			$data['sql'] = $sql;
			$data['wikitext'] = $wikitext;
			$data['display'] = $display;
			$data['position'] = $position;
			#print("\nREAD-CONF (file=$conf_file): "); var_dump($connect_data);

			return $data;
			break;

			/* Ignore this case
          case DOKU_LEXER_UNMATCHED:
			$queries = explode(';', $match);
			if (trim(end($queries)) == "") {
				array_pop($queries);
			}
			print('<pre>DOKU_LEXER_UNMATCHED:'); var_dump($queries); print('</pre>');
			return array('sql' => $queries);
			break;
			 */

          case DOKU_LEXER_EXIT :
			$this->wikitext_enabled = TRUE;
			$this->display_inline = FALSE;
			$this->vertical_position = FALSE;
			#print('<pre>DOKU_LEXER_UNMATCHED:'); var_dump($match); print('</pre>');
			return array('wikitext' => 'enable', 'display' => 'block', 'position' => 'horizontal');
            break;
        }
        return array();
    }
 
    /**
     * Create output
     */
    function render($mode, Doku_Renderer $renderer, $data) {
		$renderer->info['cache'] = false;

        if($mode == 'xhtml' and $data['sql']) {
			#print("\n<pre>RENDER-DATA (mode=$mode): "); var_dump($data);
			#print("\n</pre>");
		
			if ($data['wikitext'] == 'disable') {
				$this->wikitext_enabled = FALSE;
			} else if ($data['wikitext'] == 'enable') {
				$this->wikitext_enabled = TRUE;
			}
			if ($data['display'] == 'inline') {
				$this->display_inline = TRUE;
			} else if ($data['display'] == 'block') {
				$this->display_inline = FALSE;
			}
			if ($data['position'] == 'vertical') {
				$this->vertical_position = TRUE;
			} else if ($data['position'] == 'horizontal') {
				$this->vertical_position = FALSE;
			}


			$options = array( 'debug' => 2 );
			$db =& MDB2::connect($data['dsn'], $options);
			if (PEAR::isError($db)) {
				# FIXME: use correct error handling
				echo "<pre>";
			    print('DEBUG: ' . $db->getDebugInfo() . "\n");
				print('USER: ' . $db->getUserInfo() . "\n");
				echo "</pre>";
				return true;
			    #die('get connetion: ' . $db->getMessage() . "\n");
			}
			# set default fetch mode
			$db->setFetchMode(DB_FETCHMODE_ASSOC);

			# start query and get the result object
			echo "<pre>";
			echo "STATEMENT: ";
			var_dump($data['sql']);
			echo "</pre>";

			$result =& $db->query($data['sql']);
			if (PEAR::isError($result)) {
				# FIXME: use correct error handling
				echo "<pre>";
				print('DEBUG: ' . $result->getDebugInfo() . "\n");
				print('USER: ' . $result->getUserInfo() . "\n");
				echo "</pre>";
				return true;
			}

			# BEGIN: table
			$renderer->doc .= "\n\n";
			$renderer->doc .= "<table>\n";

			# BEGIN: header
			$renderer->doc .= "<thead>";
			$renderer->doc .= "<tr>";
			foreach ($result->getColumnNames(TRUE) as $k ) {
				$renderer->doc .= "<th>$k</th>";
			}
			$renderer->doc .= "</tr>\n";
			# END: header
			$renderer->doc .= "</thead>\n";
	
			# print elements
			while (($row = $result->fetchRow(MDB2_FETCHMODE_ASSOC))) {
				$renderer->doc .= "<tr>";
				foreach ($row as $k => $val) {
					$renderer->doc .= "<th>$val</th>";
				}
				$renderer->doc .= "</tr>\n";
			}
			# END: table
			$renderer->doc .= "</table>\n";
		}
        return true;
    }
}
#EOF
