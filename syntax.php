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
require_once('DB.php');
 
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
	print( '_read_conf(filename=' . $filename . ');<br/>' );

	$lines = file($filename, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
	print( 'LINES='); var_dump($lines);
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
        switch ($state) {
          case DOKU_LEXER_ENTER : 
			$dsnid = property('db', $match);
			$wikitext = property('wikitext', $match);
			$display = property('display', $match);
			$position = property('position', $match);

			# from $match get out the SQL statement
			$sql = '';
			$pattern = $prop . '>(.*?)</sql>';
			$rt = preg_match($pattern, $match, $matches);
			if ($rt == FALSE) {
				print("\n<br/>ERROR: pattern match failed<br>\n");
				return array();
			}
			var_dump($matches);
			$sql = $matches[1];
			print("\n<br/>SQL='$sql'<br/>\n");

			# get DB data from file
			# FIXME: check for file existence and readability
			$conf_file = '/etc/opt/dw-plugin-sql/'."$dsnid".'.conf';
			$connect_data = _read_conf($conf_file);
			$connect_data['sql'] = $sql;
			$connect_data['wikitext'] = $wikitext;
			$connect_data['display'] = $display;
			$connect_data['position'] = $position;
			print("\nREAD-CONF (file=$conf_file): "); var_dump($connect_data);

			return $connect_data;
            break;
          case DOKU_LEXER_UNMATCHED :
			$queries = explode(';', $match);
			if (trim(end($queries)) == "") {
				array_pop($queries);
			}
			return array('sql' => $queries);
            break;
          case DOKU_LEXER_EXIT :
			$this->wikitext_enabled = TRUE;
			$this->display_inline = FALSE;
			$this->vertical_position = FALSE;
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

		print("\n<pre>RENDER-DATA (mode=$mode): "); var_dump($data);
		print("\n</pre>");
		return true;
		
        if($mode == 'xhtml') {
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
			if ($data['urn'] != "") {
				$db =& DB::connect($data['urn']);
				if (DB::isError($db)) {
					$error = $db->getMessage();
					$renderer->doc .= '<div class="error">'. $error .'</div>';
					return TRUE;
				}
				else {
					array_push($this->databases, $db);
				}
			}
			elseif (!empty($data['sql'])) {
			    $db =& array_pop($this->databases);
				if (!empty($db)) {
					foreach ($data['sql'] as $query) {
						$db->setFetchMode(DB_FETCHMODE_ASSOC);
						$result =& $db->getAll($query);
						if (DB::isError($result)) {
							$error = $result->getMessage();
							$renderer->doc .= '<div class="error">'. $error .'</div>';
							return TRUE;
						}
						elseif ($result == DB_OK or empty($result)) {
						}
						else {

							if (! $this->vertical_position) {
								if ($this->display_inline) {
									$renderer->doc .= '<table class="inline" style="display:inline"><tbody>';
								} else {
									$renderer->doc .= '<table class="inline"><tbody>';
								}
								$renderer->doc .= '<tr>';
								foreach (array_keys($result[0]) as $header) {
									$renderer->doc .= '<th>';
									if ($this->wikitext_enabled) {
										$renderer->nest(p_get_instructions($header));
									} else {
										$renderer->cdata($header);
									}
									$renderer->doc .= '</th>';
								}
								$renderer->doc .= '</tr>';
								foreach ($result as $row) {
									$renderer->doc .= '<tr>';
									foreach ($row as $cell) {
										$renderer->doc .= '<td>';
										if ($this->wikitext_enabled) {
											$renderer->nest(p_get_instructions($cell));
										} else {
											$renderer->cdata($cell);
										}
										$renderer->doc .= '</td>';
									}
									$renderer->doc .= '</tr>';
								}
								$renderer->doc .= '</tbody></table>';
							} else {
								foreach ($result as $row) {
									$renderer->doc .= '<table class="inline"><tbody>';
									foreach ($row as $name => $cell) {
										$renderer->doc .= '<tr>';
										$renderer->doc .= "<th>$name</th>";
										$renderer->doc .= '<td>';
										if ($this->wikitext_enabled) {
											$renderer->nest(p_get_instructions($cell));
										} else {
											$renderer->cdata($cell);
										}
										$renderer->doc .= '</td>';
										$renderer->doc .= '</tr>';
									}
									$renderer->doc .= '</tbody></table>';
								}
							}
						}
					}
				}
			}
            return true;
        }
        return false;
    }
}
#EOF
