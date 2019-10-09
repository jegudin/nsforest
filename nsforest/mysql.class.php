<?php
	/**
	 * Подключение к БД, упрощение запросов, кеширование, плейсхолдер
	 * 
	 * @package DB
	 * @version 2.1
	 * @author Vladimir Yegudin, Vivaldy <jegudin@gmail.com>
	 * @author Marat Komarov, Marat <marat@webta.net>
	 * @copyright CruiserDSG [http://cruiser.site] (c) 2001-2019
	 * @copyright VirAB [http://virab.info] (c) 2005-2019
	 */
	
	require_once "sql.placeholder.php";
	
	// Типы преобразований Timastamp в дату методом prepare_date()
	define('MYDB_DATEFORMAT_DATE',		1);
	define('MYDB_DATEFORMAT_TIME',		2);
	define('MYDB_DATEFORMAT_DATETIME',	3);
	define('MYDB_DATEFORMAT_YEAR',		4);
	define('MYDB_DATEFORMAT_MONTH',		5);
	define('MYDB_DATEFORMAT_DAY',		6);

	/**
	 * Класс обертка для работы с базой даннных MySQL (надстройка над классом mysqli).
	 * @package mysqlWrapperDB
	 */
	class mysqlWrapperDB extends mysqli {
		/** @var array Настройки логирования запросов к базе данных */
		public $_logging = false;

		/** @var array Лог выполнения */
		var $log = array();

		/** @var array Массив предкомпилированных запросов */
		private $precompile_sql;

		/**
		 * Подключение к базе данных
		 * 
		 * @param string $host 
		 * @param string $port
		 * @param string $username 
		 * @param string $password 
		 * @param string $database 
		 */
		public function __construct($host, $port, $username, $password, $database) {
			// Подключаемся
			parent::__construct($host, $username, $password, $database, $port);
			
			// Проверим подключение
			if (@parent::ping() !== true)
				die('<b>Error connecting to the database!</b> Check the connection settings to the database.<br />');
		}
		
		function sql_placeholder() {
			$args = func_get_args();
			return call_user_func_array("sql_placeholder", $args);
		}

		function sql_pholder() {
			$args = func_get_args();
			return call_user_func_array("sql_pholder", $args);
		}

		/**
		 * @return array of result
		 */
		function query($query) {
			$starttime = microtime(1);
			$executed = FALSE;
			$sel_function = '.query';

			$count_args = func_num_args();
			if ($count_args > 1) {
				$args = func_get_args();
				
				// уберем из запроса пробелы, табуляции, переходы на новую строку - получим индекс массива предкомпилированных запросов
				$str_sql_ws = $this->_cleanWhitespice($args[0]);
				
				// проверим компилировали ли уже этот запрос, если да то не будем делать повторно, а возьмем готовый результат, что значительно ускоряет работу, так как запрос тот же, просто отличаются подставляемые значения, если нет - компилируем
				if (isset($this->precompile_sql[$str_sql_ws]))
					$args[0] = $this->precompile_sql[$str_sql_ws];
				else
					$args[0] = $this->precompile_sql[$str_sql_ws] = sql_compile_placeholder($args[0]);
				
				// последний параметр
				$last_arg = $args[$count_args - 1];
				// опознаем функцию выборки
				if (in_array($last_arg, array('.g.r', '.g.a', '.g.ht', '.h.hds', '.g.v'))) {
					$sel_function = $last_arg;
					array_pop($args);
					$last_arg = $args[$count_args - 2];
				}
				
				$query = call_user_func_array(array(&$this, "sql_pholder"), $args);
				if (! $query)
					return FALSE;
			}
			
			// Выполним запрос
			$executed = TRUE;
			$result = @parent::query($query);
			
			if ($this->error)
				trigger_error("MySQL said: $this->error;. Query: $query", E_USER_WARNING);
			
			if ($this->_logging && $executed)
				$this->log[] = array($query, microtime(1) - $starttime, $this->affected_rows, $this->error);

			return $result;
		}
		
		/**
		 * Количество записей в результатах запроса из которого исключены сортировки и лимиты
		 *
		 * @param string $query
		 * @return {int|boolean}
		 */
		function query_total_without_limitandorder($query) {
			// Найдем и удалим крайнее правое вхождение сортировки в запрос (order by) такое после которого нет ключевого слова "from"
			preg_match_all('/[\s\n]+ORDER[\s\n]+BY/is', $query, $matches, PREG_OFFSET_CAPTURE);
			if (is_array($matches) && count($matches[0])) {
				$order_pos = $matches[0][count($matches[0]) - 1][1];
				if (!stripos(substr($query, $order_pos), 'from')) $query = substr($query, 0, $order_pos);
			}
			
			// Найдем и удалим крайнее правое вхождение лимита в запрос (limit) такое после которого нет ключевого слова "from"
			preg_match_all('/[\s\n]+LIMIT\s+\d+\s*/is', $query, $matches, PREG_OFFSET_CAPTURE);
			if (is_array($matches) && count($matches[0])) {
				$order_pos = $matches[0][count($matches[0]) - 1][1];
				if (!stripos(substr($query, $order_pos), 'from')) $query = substr($query, 0, $order_pos);
			}
			
			$query = "SELECT COUNT(*) FROM ({$query}) AS __countsubquery";

			return $this->get_one($query);
		}
		
		/**
		 * Получить первую строку из результирующего запроса
		 *
		 * @param string $sql
		 * @return {array|boolean}
		 */
		function get_row($sql) {
			$args = func_get_args();
			array_push($args, '.g.r');
			$rs = call_user_func_array(array(&$this, "query"), $args);
			if ($rs->num_rows) {
				$row = $rs->fetch_assoc();
				$rs->close();
					
				return $row;
			}
			
			return false;
		}

		/**
		 * Получить первое значение из результирующего набора
		 * 
		 * @param string $sql
		 * @return {array|boolean}
		 */
		function get_one($sql) {
			$args = func_get_args();
			$row = call_user_func_array(array(&$this, "get_row"), $args);
			if ($row)
				return array_shift($row);
			
			return false;
		}

		/**
		 * Получить весь результирующий набор
		 * 
		 * @param string $sql
		 * @return {array|boolean}
		 */
		function get_all($sql) {
			$args = func_get_args();
			array_push($args, '.g.a');
			$rs = call_user_func_array(array(&$this, "query"), $args);
			if ($rs->num_rows) {
				$all = array();
				while ($row = $rs->fetch_assoc()) {
					$all[] = $row;
				}
				$rs->close();

				return $all;
			}
			
			return false;
		}
		
		/**
		 * Получить массив в котором индекс - первое поле, значение - второе: array('первое_значение' => 'второе_значение')
		 * 		Пример: результат запроса		- array(array('id'=>'10', 'value'=>'345'), array('id'=>'12', 'value'=>'757'))
		 * 				результирующая выдача	- array('10'=>'345', '12'=>'757')
		 * 
		 * @param string $sql
		 * @return {array|boolean}
		 */
		function get_hashtable($sql) {
			$args = func_get_args();
			array_push($args, '.g.ht');
			$rs = call_user_func_array(array(&$this, "query"), $args);
			if ($rs->num_rows) {
				$hashtable = array();
				while ($row = $rs->fetch_assoc()) {
					$k = reset($row);
					$v = next($row);
					$hashtable[$k] = $v;
				}
				$rs->close();
				
				return $hashtable;
			}
			
			return false;
		}

		/**
		 * Получить массив в котором индексами являются значения первого поля, а значением сам массив результатов
		 * 		Пример: результат запроса		- array(array('id'=>'10', 'k'=>'1', 's'=>'2'), array('id'=>'12', 'k'=>'2', 's'=>'22'))
		 * 				результирующая выдача	- array('10' => array('id'=>'10', 'k'=>'1', 's'=>'2'), '12' => array('id'=>'12', 'k'=>'2', 's'=>'22'))
		 *
		 * @param string $sql
		 * @return {array|boolean}
		 */
		function get_hash_dataset($sql) {
			$args = func_get_args();
			array_push($args, '.g.hds');
			$rs = call_user_func_array(array(&$this, "query"), $args);
			if ($rs->num_rows) {
				$hashtable = array();
				while ($row = $rs->fetch_assoc()) {
					$k = reset($row);
					$hashtable[$k] = $row;
				}
				$rs->close();
				
				return $hashtable;
			}
			
			return false;
		}

		/**
		 * Получить массив из значений первого поля
		 * 		Пример: результат запроса		- array(array('id'=>'10', 'k'=>'1'), array('id'=>'2', 'k'=>'2'), array('id'=>'7', 'k'=>'5'))
		 * 				результирующая выдача	- array('10', '2', '7')
		 * 
		 * @param string $sql
		 * @return {array|boolean}
		 */
		function get_vector($sql) {
			$args = func_get_args();
			array_push($args, '.g.v');
			$rs = call_user_func_array(array(&$this, "query"), $args);
			if ($rs->num_rows) {
				$ret = array();
				while ($row = $rs->fetch_assoc()) {
					$ret[] = array_shift($row);
				}
				$rs->close();
				
				return $ret;
			}
			
			return false;
		}

		/**
		 * Получить результат запроса в виде XML-данных
		 *  
		 * @param string $sql
		 */
		function get_xml($sql) {
			$args = func_get_args();
			$data = call_user_func_array(array($this, "get_all"), $args);

			$result = "<xml>\r\n<recordset>\r\n";
			foreach ($data as $row) {
				$result .= "<item>\r\n";
				foreach ($row as $k => $m)
					$result .= "<$k>$m</$m>\r\n";
				$result .= "</item>\r\n";
			}
			$result .= "</recordset>\r\n</xml>";

			return $result;
		}

		/**
		 * Вставка новой записи
		 *  
		 * @param string $table
		 * @param array $data
		 * @param boolean $savelog
		 * @return {int|boolean}
		 *
		 * TODO вставка структур типа NOW()
		 */
		function insert($table, $data, $savelog = FALSE) {
			$this->query("INSERT INTO `$table` SET ?%", $data);
			$new_id = $this->insert_id;
			
			return $new_id;
		}

		/**
		 * Массовая вставка значений из ассоциативного массива.
		 * 		Пример массива для вставки: array(array('id' => 1, 'name' => 'test'), array('id' => 2, 'name' => 'test2'))
		 * 		При этом будет сформирован запрос вида: INSERT INTO table ( id, name ) VALUES ( '1', 'test' ), ( '2', 'test2' )
		 *
		 * @param string $table
		 * @param array $data
		 * @param boolean $savelog
		 */
		public function massInsert($table, $data = array(), $savelog = FALSE) {
			if (is_array($data[0])) {
				$names = array();
				foreach ($data[0] as $name => $val)
					$names[] = $name;
				
				$massValues = array();
				foreach ($data as $dataVal) {
					$vals = array();
					foreach ($dataVal as $dvalue)
						$vals[] = $dvalue;

					$massValues[] = _psql("(?@)", $vals);
				}
				
				return $this->query("INSERT INTO `$table` (" . join(',', $names) . ") VALUES " . join(',', $massValues));
			}
			return false;
		}

		/**
		 * Изменение записи согласно условию в identy
		 *  
		 * @param string $table
		 * @param array $data
		 * @param array $identy
		 * @param boolean $savelog
		 */
		function update($table, $data, $identy, $savelog = FALSE) {
			$sql_where = _psql(" WHERE ?*", $identy);
			$sql = _psql("UPDATE `$table` SET ?%" . $sql_where, $data);
			
			$result = $this->query($sql);
					
			return $result;
		}

		/**
		 * Удаление записи
		 *  
		 * @param string $table
		 * @param array $identy
		 * @param boolean $savelog
		 */
		function delete($table, $identy = array(), $savelog = FALSE) {
			$sql_where = empty($identy) ? "" : _psql(" WHERE ?*", $identy);
			
			return $this->query("DELETE FROM `$table`" . $sql_where);
			
			return false;
		}

		/**
		 * Очистка таблицы
		 *  
		 * @param string $table
		 */
		function truncate($table) {
			return $this->query("TRUNCATE TABLE `$table`");
		}

		/**
		 * Сохранение записи (если запись есть то изменится, если нет то добавится)
		 *  
		 * @param string $table
		 * @param array $data
		 * @param array $identy
		 * @param boolean $savelog
		 */
		function save($table, $data, $identy, $savelog = FALSE) {
			$rs = $this->query("SELECT * FROM `$table` WHERE ?*", $identy);
			if ($rs->num_rows)
				return $this->update($table, $data, $identy, $savelog);
			else
				return $this->insert($table, $data, $savelog);
		}

		/**
		 * Список полей таблицы
		 *  
		 * @param string $table
		 */
		function get_metadata($table) {
			$sql= "SHOW FIELDS FROM $table";
			$data = $this->get_all($sql);
			foreach ($data as $row)
				$result[array_shift($row)] = $row;
			
			return $result;
		}

		/**
		 * Преобразование даты и времени Timastamp в формат понятный MySQL согласно указанному виду
		 *
		 * @param string $sql
		 */
		function prepare_date($timestamp, $field_type = MYDB_DATEFORMAT_DATETIME) {
			if (!$timestamp===false && $timestamp > 0) {
				switch ($field_type) {
					case MYDB_DATEFORMAT_DATE:
						return date('Y-m-d', $timestamp);
					case MYDB_DATEFORMAT_TIME:
						return date('H:i:s', $timestamp);
					case MYDB_DATEFORMAT_YEAR:
						return date('Y', $timestamp);
					case MYDB_DATEFORMAT_MONTH:
						return date('m', $timestamp);
					case MYDB_DATEFORMAT_DAY:
						return date('d', $timestamp);
					case MYDB_DATEFORMAT_DATETIME:
					default:
						return date('Y-m-d H:i:s', $timestamp);
				}
			}
			return false;
		}
		
		private function _cleanWhitespice($str) {
			return str_replace(array(chr(10), chr(13), chr(9), chr(32)), '', $str);
		}

	}