<?php
defined('ROOT_DIR') or die;

require ROOT_DIR.'includes/db.php';

class toto_db extends ATCore_DB_MySQLi {
	const repl_db = 'toto_repl';
	private static function replicated_table($table) {
		switch($table) {
			case 'toto':
			case 'files':
			case 'fileinfo':
			case 'filelinks':
			case 'attachment_files':
			case 'attachments':
			case 'trackers':
			case 'tracker_stats':
			case 'anidb_tvdb':
				return true;
		}
		return false;
	}
	
	
	public function tableName($table) {
		if(self::replicated_table($table))
			return '`'.self::repl_db.'`.`'.$this->table_prefix.$table.'`';
		return parent::tableName($table);
	}
	
	private function swap_db_op($db, $op, $args) {
		$link = $this->getLink(true);
		if(!mysqli_select_db($link, $db) /*&& $this->errno() == 2006*/ ) {
			// failure because server went away  try reconnecting
			$this->link = $this->last_link = null;
			$link = $this->getLink(true);
			if(!$link) return false;
			mysqli_select_db($link, $db);
		}
		
		$ret = call_user_func_array(array('parent', $op), $args);
		
		mysqli_select_db($link, $this->config['dbname']);
		return $ret;
	}
	
	// $args[0] assumed to be the table
	private function update_swap_db($op, $args) {
		if(self::replicated_table($args[0]))
			return $this->swap_db_op(self::repl_db, $op, $args);
		elseif(substr($args[0], 0, 10) == 'arcscrape.')
			return $this->swap_db_op('arcscrape', $op, $args);
		return call_user_func_array(array('parent', $op), $args);
	}
	
	public function insert($table, $row, $replace = false, $ignore = false) {
		$args = func_get_args();
		return $this->update_swap_db('insert', $args);
	}
	public function insertMulti($table, $rows, $replace = false, $ignore = false) {
		$args = func_get_args();
		return $this->update_swap_db('insertMulti', $args);
	}
	public function delete($table, $where='', $limit=0, $order='') {
		$args = func_get_args();
		return $this->update_swap_db('delete', $args);
	}
	public function update($table, $data, $where='', $expr = false, $limit=0) {
		$args = func_get_args();
		return $this->update_swap_db('update', $args);
	}
	
	public function write_query($db, $str, $ignore_error=false) {
		$args = [$str, true, $ignore_error];
		return $this->swap_db_op($db ?: self::repl_db, 'query', $args);
	}
}
