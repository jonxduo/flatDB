<?php
include(__DIR__.'/ff.settings.php');

class ff_database{

	private $access_id;
	public $tables;

	public function __construct ($access_request_id){
		global $access_id;
		if(md5($access_request_id)!=md5($access_id)) return false;

		$this->access_id=md5($access_id);
		$this->tables=new stdClass();
	}

	public function load_table($table_name){
		$this->tables->{$table_name}=new ff_table($this->access_id, $table_name);
	}

	public function load_tables($array){
		foreach ($array as $v){
			$this->load_table($v);
		}
	}

	public function save(){
		foreach($this->tables as $table){
			$table->save();
		}
	}

	public function select($table_name, $where=NULL){
		$this->loaded($table_name);
		if($where!=NULL){
			$key= (isset($where[0])) ? $where[0] : NULL;
			$value= (isset($where[1])) ? $where[1] : NULL;
			$operator= (isset($where[2])) ? $where[2] : NULL;
			return $this->tables->{$table_name}->search($key, $value, $operator);
		}else{
			return $this->tables->{$table_name}->rows;
		}
	}

	public function insert($table_name, $values, $where=NULL){
		$this->loaded($table_name);
		if($where!=NULL) {
			$this->insert_where($table_name, $values, $where);
		}else{
			$this->tables->{$table_name}->add_row($values);
		}
	}

	public function delete($table_name, $where){
		$this->loaded($table_name);
		$key= (isset($where[0])) ? $where[0] : NULL;
		$value= (isset($where[1])) ? $where[1] : NULL;
		$operator= (isset($where[2])) ? $where[2] : NULL;
		$deleted_rows=$this->tables->{$table_name}->search($key, $value, $operator);
		foreach($deleted_rows as $dk=>$dv){
			$this->tables->{$table_name}->delete_row($dk);
		}
	}

	public function new_keys($table_name, $keys){
		$this->loaded($table_name);
		$deleted_rows=$this->tables->{$table_name}->add_column($keys);
	}

	private function insert_where($table_name, $values, $where){
		if(!isset($where[2]))$where[2]=NULL;
		$rows=$this->tables->{$table_name}->search($where[0], $where[1], $where[2]);
		foreach ($rows as $i=>$row){
			foreach($values as $k=>$v){
				$this->tables->{$table_name}->rows[$i][$k]=$v;
			}		
		}
	}

	private function loaded($table_name){
		if(!isset($this->tables->{$table_name})) $this->load_table($table_name);
	}
}

class ff_table{

	private $access_id;
	private $data_dir;
	private $ext;
	private $row_limiter;

	private $keys;
	public $rows;
	private $table_raw;
	private $file;

	private $index;
	private $index_key;

	public function __construct($access_request_id, $table_name=NULL){
		global $access_id;
		if($access_request_id!=md5($access_id)) return false;

		global $data_dir;
		global $ext;
		global $row_limiter;

		$this->access_id=$access_id;
		$this->data_dir=$data_dir;
		$this->ext=$ext;
		$this->row_limiter=$row_limiter;

		$this->keys=array();
		$this->rows=array();
		$this->table_raw=array();

		if(!$table_name==NULL) $this->set_table($table_name);
	}

	/**
	 * legge il file indicato e popola le variabili della classe
	 */
	public function set_table($table_name=NULL){
		$this->file=$this->data_dir.$table_name.$this->ext;
		$this->open_table();
	}

	/**
	 * aggiunge una colonna
	 */
	public function add_column($col){
		if(is_array($col)){
			foreach($col as $c){
				array_push($this->keys, $c);
			}
		}else{
			array_push($this->keys, $col);
		}
		$this->set_index();
	}

	/**
	 * aggiungere una riga
	 */
	public function add_row($row){
		$rr=array();
		foreach($this->keys as $key){
			if(isset($row[$key])) {
				$rr[$key]=$row[$key];
			}else{
				$rr[$key]=NULL;
			}
		}
		$rr[$this->index_key]=$this->index+1;
		$this->rows[]=$rr;
		//array_push($this->table_raw, serialize($rr).$this->row_limiter);
		$this->set_index();
	}

	/**
	 * rimuove una riga
	 */
	public function delete_row($row_index){
		unset($this->rows[$row_index]);
		$this->save();
	}

	/**
	 * modifica una riga
	 */
	public function modify_row($k, $data){
		foreach($data as $kk=>$vv){
			$this->rows[$k][$kk]=$vv;
		}
	}

	/**
	 * salva la tabella
	 */
	public function save(){
		$head=serialize($this->keys).'[/hr_row]';
		$body='';
		$this->update_table_raw();
		foreach ($this->table_raw as $row_raw){
			$body.=$row_raw;
		}
		$file=fopen($this->file, 'w');
		if(!$file) return false;
		$out=$head.$body;
		fwrite($file, $out);
		fclose($file);
	}

	/**
	 *	se il file di una tabella esiste restituisce il contenuto popolando le variabili
	 */
	private function open_table(){
		if(file_exists($this->file)){
			$file=file_get_contents($this->file);
			$ex_file=explode('[/hr_row]', $file);
			$this->keys=unserialize($ex_file[0]);
			$this->table_raw=array_filter(explode($this->row_limiter, $ex_file[1]));
			foreach($this->table_raw as $k=>$row_raw){
				$this->rows[$k]=unserialize($row_raw);
			}
			$this->set_index();	
		}
	}

	/**
	 * tiene aggiornato l'index corrente
	 */
	private function set_index(){
		if(!isset($idex_key)){
			$this->index_key=$this->keys[0];
		}
		$last=end($this->rows);
		$this->index=$last[$this->index_key];
	}

	/**
	 * aggiorna le righe che verranno scritte nel file
	 */
	private function update_table_raw(){
		$this->table_raw=array();
		foreach($this->rows as $k=>$row){
			$this->table_raw[$k]=serialize($row).$this->row_limiter;
		}
	}


	/************************************************************************************************/
	/*											 RICERCA											*/
	/************************************************************************************************/


	/**
	 * cerca nella tabella la riga o le righe corrispondenti alla query inserita
	 */
	public function search($key, $value, $operator=NULL){
		switch ($operator){
			case '==':
				return $res=$this->search_equal($key, $value);
				break;
			case '!=':
				return $res=$this->search_disequal($key, $value);
				break;
			case '>':
				return $res=$this->search_greater($key, $value);
				break;
			case '>=':
				return $res=$this->search_greater_equal($key, $value);
				break;
			case '<':
				return $res=$this->search_less($key, $value);
				break;
			case '<=':
				return $res=$this->search_less_equal($key, $value);
				break;
			case '%LIKE%':
				return $res=$this->search_similar($key, $value);
				break;
			default:
				return $res=$this->search_equal($key, $value);
		}
		return $res;
	}

	private function search_equal($k, $v){
		$res=array();
		foreach($this->rows as $n=>$row){
			if($row[$k]==$v) $res[$n]=$row;
		}
		return $res;
	}

	private function search_disequal($k, $v){
		$res=array();
		foreach($this->rows as $n=>$row){
			if($row[$k]!=$v) $res[$n]=$row;
		}
		return $res;
	}

	private function search_greater($k, $v){
		$res=array();
		foreach($this->rows as $n=>$row){
			if($row[$k]>$v) $res[$n]=$row;
		}
		return $res;
	}

	private function serach_greater_equal($k, $v){
		$res=array();
		foreach($this->rows as $n=>$row){
			if($row[$k]>=$v) $res[$n]=$row;
		}
		return $res;
	}

	private function search_less($k, $v){
		$res=array();
		foreach($this->rows as $n=>$row){
			if($row[$k]<$v) $res[$n]=$row;
		}
		return $res;
	}

	private function serach_less_equal($k, $v){
		$res=array();
		foreach($this->rows as $n=>$row){
			if($row[$k]<=$v) $res[$n]=$row;
		}
		return $res;
	}

	private function search_similar($k, $v){
		$res=array();
		foreach($this->rows as $n=>$row){
			if(strpos($row[$k], $v)) $res[$n]=$row;
		}
		return $res;
	}
}
?>