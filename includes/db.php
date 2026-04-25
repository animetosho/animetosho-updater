<?php

abstract class ATCore_DB
{
	protected $table_prefix = '';
	public $last_result = null;
	
	abstract public function query($str, $write=false);
	abstract public function fetchArray();
	public function fetchField($field) {
		$array = $this->fetchArray();
		return $array[$field];
	}
	abstract public function numRows();
	abstract protected function insertId();
	abstract protected function affectedRows();
	public function escape($str, $noquote=false) {
		if($str === null)
			return 'NULL';
		if(is_string($str)) {
			if($noquote)
				return $this->_escape($str);
			return '"'.$this->_escape($str).'"';
		}
		if(is_int($str) || is_float($str))
			return "$str";
		if(is_bool($str))
			return ($str ? '1' : '0');
		// still here?
		assert(false);
	}
	abstract protected function _escape($str);
	abstract public function freeResult();
	abstract protected function getLink($write=false);
	abstract protected function closeLink($link);
	
	public function testConnection() {
		return $this->getLink(false);
	}
	
	// escape hex->bin
	public function escapeHexBin($s) {
		return $this->escape(pack('H*', $s));
	}
	public function tableName($table) {
		if(strpos($table, '.')) // hack for external DB access
			return $table;
		return '`'.$this->table_prefix.$table.'`';
	}
	
	public function select($table, $where='', $fields='*', $options=array()) {
		if(is_array($fields))
			$fields = '`'.implode('`,`', $fields).'`';
		
		if($where) $where = ' WHERE '.$this->buildWhere($where);
		if(isset($options['group']))
		{
			$where .= ' GROUP BY '.$options['group'];
			if(isset($options['having']))
				$where .= ' HAVING '.$this->buildWhere($options['having']);
		}
		if(isset($options['order']))
			$where .= ' ORDER BY '.$options['order'];
		if(isset($options['limit']))
		{
			$where .= ' LIMIT '.$options['limit'];
			if(isset($options['limit_start']))
				$where .= ' OFFSET '.$options['limit_start'];
		}
		if(isset($options['joins']))
		{
			$joins = '';
			foreach($options['joins'] as &$j) {
				if(is_string($j))
					$joins .= ' '.$j;
				else
					$joins .= ' '.strtoupper($j[0]).' JOIN '.$this->tableName($j[1]).' AS `'.$j[1].'` ON `'.$table.'`.`'.$j[2].'`=`'.$j[1].'`.`'.(isset($j[3]) ? $j[3] : $j[2]).'`';
			}
			$where = $joins.$where;
		}
		if(isset($options['use_index']))
			$where = ' USE INDEX '.$options['use_index'].$where;
		return $this->query('SELECT '.$fields.' FROM '.$this->tableName($table).' AS `'.$table.'`'.$where);
	}
	
	// grabs a single row from the DB
	public function selectGetArray($table, $where='', $fields='*', $options=array()) {
		$options['limit'] = 1;
		if(!$this->select($table, $where, $fields, $options))
			return null;
		$res = $this->fetchArray();
		$this->freeResult();
		return $res;
	}
	public function selectGetField($table, $field, $where='', $options=array()) {
		$options['limit'] = 1;
		if(!$this->select($table, $where, $field, $options))
			return null;
		if(!($res = $this->fetchArray()))
			return null;
		$this->freeResult();
		return reset($res);
	}
	// grabs all rows from the DB into an array
	public function selectGetAll($table, $key='', $where='', $fields='*', $options=array()) {
		if(!$this->select($table, $where, $fields, $options))
			return null;
		$ret = array();
		while($res = $this->fetchArray())
		{
			if($key)
				$ret[$res[$key]] = $res;
			else
				$ret[] = $res;
		}
		$this->freeResult();
		return $ret;
	}
	
	// $ignore: true = INSERT IGNORE, 1 (or other truthy value) = regular INSERT but doesn't throw error
	public function insert($table, $row, $replace = false, $ignore = false)
	{
		$query = ($replace ? 'REPLACE' : 'INSERT'.($ignore===true ? ' IGNORE':'')).' INTO '.$this->tableName($table);
		if(is_array($row))
		{
			$fields = $values = '';
			$d = '';
			$row = array_map(array(&$this, 'escape'), $row);
			$query .= '(`'.implode('`, `', array_keys($row)).'`) VALUES ('.implode(', ', $row).')';
		}
		else
			$query .= $row;
		return $this->query($query, true, $ignore);
	}
	
	public function insertMulti($table, $rows, $replace = false, $ignore = false)
	{
		$insertsql = '';
		$query = ($replace ? 'REPLACE' : 'INSERT'.($ignore ? ' IGNORE':'')).' INTO '.$this->tableName($table).' (`'.implode('`, `', array_keys(reset($rows))).'`) VALUES ';
		$d = '';
		foreach($rows as &$row)
		{
			$query .= $d.'('.implode(', ', array_map(array(&$this, 'escape'), $row)).')';
			if(!$d) $d = ', ';
		}
		return $this->query($query, true, $ignore);
	}
	
	public function upsert($table, $id_row, $row)
	{
		$query = 'INSERT INTO '.$this->tableName($table);
		$fullrow = array_map(array(&$this, 'escape'), array_merge($id_row, $row));
		$query .= '(`'.implode('`, `', array_keys($fullrow)).'`) VALUES ('.implode(', ', $fullrow).')';
		$query .= ' ON DUPLICATE KEY UPDATE ';
		$query .= implode(', ', array_map(function($col) {
			return '`'.$col.'`=VALUES(`'.$col.'`)';
		}, array_keys($row)));
		return $this->query($query, true);
	}
	
	// TODO: make WHERE clause for delete/update mandatory (prevent accidental omission)
	public function delete($table, $where='', $limit=0, $order='')
	{
		$query = 'DELETE FROM '.$this->tableName($table);
		if($where) $query .= ' WHERE '.$this->buildWhere($where);
		if($order) $query .= ' ORDER BY '.$order;
		if($limit) $query .= ' LIMIT '.$limit;
		$success = $this->query($query, true);
		if($success)
			return $this->affectedRows();
		else
			return $success;
	}
	
	public function update($table, $data, $where='', $expr = false, $limit=0)
	{
		if(is_array($data))
		{
			$d = $query = '';
			foreach($data as $field => &$value)
			{
				$pre = $d.'`'.$field.'`=';
				if($value === null)
					$query .= $pre.'NULL';
				elseif($expr)
					$query .= $pre.$value;
				else
					$query .= $pre.$this->escape($value);
				if(!$d) $d = ', ';
			}
		}
		else
			$query = $data;
		
		if($where) $query .= ' WHERE '.$this->buildWhere($where);
		if($limit) $query .= ' LIMIT '.$limit;
		
		$success = $this->query('UPDATE '.$this->tableName($table).' SET '.$query, true);
		if($success)
			return $this->affectedRows();
		else
			return $success;
	}
	
	private function buildWhere($a) {
		if(!is_array($a))
			return $a; // allow pure string WHEREs
		if(count($a) == 1)
			return reset($a);
		
		$first = true;
		foreach($a as &$s) {
			if($first)
				$first = false;
			else
				$s = $this->escape($s);
		}
		return call_user_func_array('sprintf', $a);
	}
}


// DB stuff
class ATCore_DB_MySQLi extends ATCore_DB
{
	protected $link;
	protected $last_link;
	protected $config;
	protected $debugMode = false;
	public $dbtime = 0;
	public $numqueries = 0;
	
	function __construct($config) {
		$this->config = $config;
		$this->table_prefix = $this->config['dbprefix'];
		
		$this->debugMode = $this->config['dbdebug'];
		mysqli_report(MYSQLI_REPORT_OFF);
		if($this->debugMode)
			mysqli_report(MYSQLI_REPORT_ERROR);
	}
	
	protected function getLink($write=false) {
		if(empty($this->link))
			$this->connect();
		
		if(empty($this->link)) return null;
		
		$this->last_link = $this->link;
		return $this->last_link;
	}
	protected function closeLink($link) {
		return @mysqli_close($link);
	}
	
	private function connect()
	{
		if($this->debugMode) $this->dbtime -= microtime(true);
		$this->link = @mysqli_connect($this->config['dbhost'], $this->config['dbuser'], $this->config['dbpassword'], $this->config['dbname'], $this->config['dbport']);
		if($this->debugMode) $this->dbtime += microtime(true);
		if(!$this->link)
		{
			if($this->debugMode)
				trigger_error('Unable to connect to MySQLi server! (Error #'.mysqli_connect_errno().': '.mysqli_connect_error().')', E_USER_WARNING);
			
			sleep(60); // we'll keep retrying until we either succeed, or get killed (or overflow the stack)
			return $this->connect();
		}
		//@mysqli_autocommit($this->link, false);
		mysqli_set_charset($this->link, 'utf8mb4');
		
		return $this->link;
	}
	
	public function query($str, $write=false, $ignore_error=false)
	{
		if(!empty($this->last_result)) // try to prevent errors
			$this->freeResult();
		
		if($this->debugMode) $this->dbtime -= microtime(true);
		$link = $this->getLink($write);
		$result = @mysqli_query($link, $str);
		if($this->debugMode) $this->dbtime += microtime(true);
		if($this->debugMode) ++$this->numqueries;
		$errno = $this->errno();
		//// temporarily disabled because SQL should auto-reconnect?  this code could be used later
		if($errno == 2006) { // SQL server gone away
			trigger_error('MySQLi Warning: Lost connection to MySQL database (2006) - auto-attempting reconnect...', E_USER_WARNING);
			$this->connect();
			if($this->debugMode) $this->dbtime -= microtime(true);
			$link = $this->getLink($write);
			$result = @mysqli_query($link, $str);
			if($this->debugMode) $this->dbtime += microtime(true);
			$errno = $this->errno();
		}
		
		// TODO: error out if $this->last_link is false?
		
		if(!$ignore_error) {
			if($errno) {
				if(PHP_SAPI == 'cli')
					$this->dberror($errno, "\n".'Query executed: '.$str);
				else
					$this->dberror($errno, '<br />Query executed: '.htmlspecialchars($str));
				return;
			}
			elseif($this->debugMode && $this->last_link && mysqli_warning_count($this->last_link)) {
				$this->freeResult($result);
				$warning = mysqli_fetch_row(mysqli_query($this->last_link, 'SHOW WARNINGS'));
				trigger_error('MySQLi Warning: '.sprintf('%s (%d): %s\n', $warning[0], $warning[1], $warning[2]), E_USER_WARNING);
			}
		}
		if(!is_bool($result))
		{
			if($write) // failsafe
				$this->freeResult($result);
			else
				$this->last_result = &$result;
		}
		return $result;
	}
	
	public function fetchArray() {
		return mysqli_fetch_array($this->last_result, MYSQLI_ASSOC);
	}
	
	public function numRows() {
		return mysqli_num_rows($this->last_result);
	}
	
	public function insertId() {
		return mysqli_insert_id($this->last_link);
	}
	public function affectedRows() {
		return mysqli_affected_rows($this->last_link);
	}
	
	protected function _escape($str) {
		return mysqli_real_escape_string($this->getLink(false), $str);
	}
	
	public function freeResult() {
		if(empty($this->last_result))
			return false;
		$return = @mysqli_free_result($this->last_result);
		$this->last_result = null;
		return $return;
	}
	
	private function errno() {
		if(!$this->last_link) return false;
		return mysqli_errno($this->last_link);
	}
	private function errstr() {
		return mysqli_error($this->last_link);
	}
	private function dberror($errno, $err='') {
		if($this->debugMode) {
			trigger_error('MySQLi error '.$errno.' occurred!<br />'. $this->errstr().'<br />'.$err, E_USER_WARNING);
		}
	}
	
	public function suppressNotices($s=true) {
		if(!$this->debugMode) return;
		mysqli_report($s ? MYSQLI_REPORT_ERROR : MYSQLI_REPORT_ERROR);
	}
	
	function __destruct()
	{
		// turn off reporting for other apps - NOTE, this isn't thread safe! (but it's better than nothing)
		if($this->debugMode) {
			mysqli_report(MYSQLI_REPORT_ERROR);
			mysqli_report(MYSQLI_REPORT_OFF);
		}
		
		// commit all changes, and close all links
		if(empty($this->link)) return;
		//@mysqli_commit($this->link);
		$this->closeLink($this->link);
		unset($this->link);
	}
}

?>
