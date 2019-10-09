<?php
	/**
	 * Классы работы с вложенными множествами
	 * 
	 * NSTree - класс представления дерева
	 * NSForest - класс представления набора деревьев
	 * 
	 * @package NestedSets
	 * @version 1.21
	 * @author Vladimir Yegudin, Vivaldy <jegudin@gmail.com>
	 * @author Redkin Sergey, Terloger <rou.terra@gmail.com>
	 * @author Marat Komarov, Marat <marat@webta.net>
	 * @copyright CruiserDSG [http://cruiser.site] (c) 2001-2019
	 * @copyright VirAB [http://virab.info] (c) 2005-2019
	 */
	
	/**
	 * Класс работы с деревом
	 */
	class NSTree extends NestedSets {
		var $root_id = 1;					/** @var int ID корня дерева */
		
		function __construct($tableName, $fieldNames, $database) {
			$args = func_get_args();
			
			if (isset($args[3]) && is_numeric($args[3]))
				$this->root_id = (int) $args[3];
				
			parent::__construct($tableName, $fieldNames, $database);
			
			return $this;
		}
		
		public function select($id, $additionalData = array(), $axis = NSTREE_AXIS_DESCENDANT_OR_SELF, $amount = null, $order = "", $identy = array(), $advjoin = null, $option = array(), $show_query = FALSE) {
			return parent::get($this->root_id, $id, $additionalData, $axis, $amount, $order, $identy, $advjoin, $option, $show_query);
		}
	}

	/**
	 * Класс работы с набором деревьев
	 */
	class NSForest extends NestedSets {
		var $root_id;					/** @ignore int ID корня дерева */
		
		function __construct($tableName, $fieldNames, $database) {
			parent::__construct($tableName, $fieldNames, $database);
			
			return $this;
		}
		
		public function select($treeId, $id, $additionalData = array(), $axis = NSTREE_AXIS_DESCENDANT_OR_SELF, $amount = null, $order = "", $identy = array(), $advjoin = null, $option = array(), $show_query = FALSE) {
			return parent::get($treeId, $id, $additionalData, $axis, $amount, $order, $identy, $advjoin, $option, $show_query);
		}
	}
	
	/**
	 * Класс работы с вложенными множествами (Nested Set)
	 */
	class NestedSets {
		var $table;						/** @var string Table with Nested Sets implemented */
		var $id;						/** @var int Name of the id-auto_increment-field in the table */
		var $tid;						/** @var int Индекс дерева */
		var $left;						/** @var int Левый край */
		var $right;						/** @var int Правый край */
		var $level;						/** @var int Уровень */
		private $_db;					/** @var MyDB Экземпляр SQL хранилища*/
		private $_check_unique = true;	/** @var boolean Флаг проверки уникальности UID */
		
		/**
		 * Конструктор класса
		 * 
		 * @param string $tableName Имя таблицы с данными
		 * @param string $fieldNames массив с именами полей
		 * @param string $database экземпдяр database wrapper
		 */
		function __construct($tableName, $fieldNames, $database) {
			$this->table = $tableName;
			
			if (count($fieldNames) < 5)
				return FALSE;
			
			$tblFields = array('id', 'tid', 'left', 'right', 'level');
			foreach ($fieldNames as $k => $v)
				if (!in_array($k, $tblFields)) return FALSE;
				eval('$this->' . $k . '="' . $v . '";');
			
			$this->_db = $database;
		}
		
		/**************   Получение данных   **************/

		/**
		 * Общий (универсальный) запрос узлов дерева
		 *
		 * @param int $treeId Идентификатор дерева
		 * @param int $id Идентификатор записи относительно которой делается выборка
		 * @param array $additionalData Список полей данных, включаемых в результат
		 * @param int $axis Направление выборки
		 * @param string $amount Количество выбираемых записей
		 * @param string $order Поле по которому производится сортировка (если не указано, то сортировка делается по полю left структуры)
		 * @param array|string $identy Ограничения выборки данных. Либо массив (поле => значение), либо SQL-строка 
		 * @param array $advjoin Join дополнительных данных. Передается массив состоящий из двух секций: array('selectPart' : 'dd.name AS name', 'joinPart' : 'LEFT JOIN table AS dd ON dd.id = d.data_id')
		 * @param array $option array('no_order' => true) <- не применять ORDER BY
		 * @param bool $show_query
		 */
		public function get($treeId, $id, $additionalData = array(), $axis = NSTREE_AXIS_DESCENDANT_OR_SELF, $amount = null, $order = "", $identy = array(), $advjoin = null, $option = array(), $show_query = FALSE) {
			if (!is_numeric($treeId) || !is_numeric($id) || !is_numeric($axis) || $axis > 10)
				return FALSE;
			
			$treeId	= (int) $treeId;
			$id		= (int) $id;
			$axis	= (int) $axis;
			
			$sqlTreeIdent = "$this->tid = $treeId";
			$sqlIdent = $id ? "$this->id = $id" : "$this->left = 1";
			
			if (is_array($identy)) {
				if (count($identy))
					foreach ($identy as $identy_key => $identy_value) {
						$sqlIdent .= " AND " . $identy_key . " = '" . $identy_value . "'";
					}
			} elseif (is_string($identy))
				$sqlIdent .= " AND " . $identy;
			
			$sqlAdvSelect = "";
			if (is_array($additionalData) && !empty($additionalData)) {
				foreach ($additionalData as $k => $name) $additionalData[$k] = "t1.$name";
				$sqlAdvSelect = implode(', ', $additionalData);
				unset($additionalData);
			}
			
			$sqlSelect = "
				SELECT
					t1.$this->id										AS `id`,
					t1.$this->tid										AS `tid`,
					t1.$this->left										AS `left`,
					t1.$this->right										AS `right`,
					t1.$this->level										AS `level`,
					IF(t1.$this->left = t1.$this->right-1, '0', '1')	AS `has_children`,
					t3.id												AS `parent_id`
			";
			
			if ($axis == NSTREE_AXIS_SELF) {
				$sqlIdentTable = 't1';
				$sqlT2Injecton = "";
			} else {
				$sqlIdentTable = 't2';
				$sqlT2Injecton = " JOIN $this->table AS t2 ON (" . ($treeId ? "t2.$sqlTreeIdent AND " : "") . "%s) ";
			}
			
			$sqlParent = "LEFT JOIN $this->table AS t3 
												ON (	t3.$this->level = t1.$this->level - 1
													AND t3.$this->left < t1.$this->left
													AND t3.$this->right > t1.$this->right
													" . ($treeId ? " AND t3.$sqlTreeIdent " : "") . "
													)";
			$sqlFrom = "
										FROM $this->table AS t1 
						 					$sqlT2Injecton
						 					$sqlParent";
			$sqlWhere = "
									WHERE   $sqlIdentTable.$sqlIdent
											" . ($treeId ? " AND t1.$sqlTreeIdent " : "") . "
							";
			
			$stmts = array();
			switch ($axis) {
				case NSTREE_AXIS_CHILD:
				case NSTREE_AXIS_CHILD_OR_SELF:
				case NSTREE_AXIS_LEAF:
				case NSTREE_AXIS_DESCENDANT:
				case NSTREE_AXIS_DESCENDANT_OR_SELF:
					if ($axis == NSTREE_AXIS_CHILD) {
						$stmts[] = "t1.$this->level = t2.$this->level + 1";
					}
					if ($axis == NSTREE_AXIS_CHILD_OR_SELF) {
						$stmts[] = "t1.$this->level - t2.$this->level <= 1";
					}
					if ($axis == NSTREE_AXIS_LEAF) {
						$stmts[] = "t1.$this->left = t1.$this->right - 1";
					}
					if ($axis == NSTREE_AXIS_DESCENDANT_OR_SELF || $axis == NSTREE_AXIS_CHILD_OR_SELF) {
						$stmts[] = "(t1.$this->left BETWEEN t2.$this->left AND t2.$this->right)";
					} else {
						$stmts[] = "t1.$this->left > t2.$this->left AND t1.$this->right < t2.$this->right";
					}
					break;
				case NSTREE_AXIS_PARENT:
					$stmts[] = "t1.$this->level = t2.$this->level - 1";
				case NSTREE_AXIS_ANCESTOR:
				case NSTREE_AXIS_ANCESTOR_OR_SELF:
					if ($axis == NSTREE_AXIS_ANCESTOR_OR_SELF)
						$stmts[] = "t1.$this->left <= t2.$this->left AND t1.$this->right >= t2.$this->right";
					else
						$stmts[] = "t1.$this->left < t2.$this->left AND t1.$this->right > t2.$this->right";
					
					break;
				case NSTREE_AXIS_FOLLOWING_SIBLING:
				case NSTREE_AXIS_PRECENDING_SIBLING:
					if ($parentInfo = $this->getParentNodeInfo($id)) {
						$stmts[] = "t2.$this->level = t1.$this->level";
						$stmts[] = "t1.$this->left > {$parentInfo['left']}";
						$stmts[] = "t1.$this->right < {$parentInfo['right']}";
						if ($axis == NSTREE_AXIS_FOLLOWING_SIBLING)
							$stmts[] = "t1.$this->left > t2.$this->right";
						elseif ($axis == NSTREE_AXIS_PRECENDING_SIBLING)
							$stmts[] = "t1.$this->right < t2.$this->left";
					} else
						return false;
					
					break;
			}
			if ($stmts)
				$sqlFrom = sprintf($sqlFrom, join(' AND ', $stmts));
			
			if (!is_array($advjoin))
				$sql = $sqlSelect . ($sqlAdvSelect ? ", $sqlAdvSelect" : "") . $sqlFrom . $sqlWhere;
			else
				$sql = $sqlSelect . ($sqlAdvSelect ? ", $sqlAdvSelect" : "") . ($advjoin['selectPart'] ? ", " . $advjoin['selectPart'] : "") . $sqlFrom . ($advjoin['joinPart'] ? " " . $advjoin['joinPart'] : "") . $sqlWhere;
			
			if ( !isset($option['no_order']) || !$option['no_order'] )
				$sql .= " ORDER BY t1." . ($order ? $order : $this->left);

			if (!is_null($amount)) $sql .= " LIMIT " . $amount;
			
			// Запрос готов, получим данные
			$nodeSet = $this->_db->get_all($sql);
			
			// DEBUG
			if ($show_query) echo $sql;
			
			return $nodeSet;
		}
		
		/**
		 * Получение узла с данными
		 * 
		 * @param int $treeId Идентификатор дерева
		 * @param  int $id
		 * @param  string[] $additionalData
		 * @return array
		 */
		public function getNode($id, $additionalData = array()) {
			$nodeSet = $this->get(0, $id, $additionalData, NSTREE_AXIS_SELF);
			if (!empty($nodeSet))
				return $nodeSet[0];
			
			return FALSE;
		}
		
		/**
		 * Получение родительского узла с данными
		 *
		 * @param int $treeId Идентификатор дерева
		 * @param  int $id
		 * @param  string[] $additionalData
		 * @return array
		 */
		public function getParentNode($id, $additionalData = array ()) {
			$nodeSet = $this->get(0, $id, $additionalData, NSTREE_AXIS_PARENT);
			if (!empty($nodeSet)) return $nodeSet[0];
			return false;
		}
		
		/**
		 * Получение структуры узла
		 * 
		 * @param int $treeId Идентификатор дерева
		 * @param int $id
		 * @return array
		 */
		public function getNodeInfo($id) {
			return $this->getNode($id, array());
		}
		
		/**
		 * Получение структуры корневого узла первого дерева
		 * 
		 * @return array
		 */
		public function getRootNodeInfo() {
			return $this->getNodeInfo(0);
		}
		
		/**
		 * Получение структуры родительского узла
		 * 
		 * @param int $treeId Идентификатор дерева
		 * @param int $id
		 * @return array
		 */
		public function getParentNodeInfo($id) {
			return $this->getParentNode($id, array());
		}
		
		/**
		 * Получение дочерних узлов у которых нет дочерних (краевые - листья)
		 *
		 * @param int $treeId Идентификатор дерева
		 * @param  int $id
		 * @param  string[] $additionalData
		 * @return array
		 */
		public function enumLeafs($id, $additionalData) {
			return $this->get(0, $id, $additionalData, NSTREE_AXIS_LEAF);
		}
		
		/**************   Добавление данных   **************/
		
		/**
		 * Создание дерева
		 * 
		 * @param  array $data Список полей данных нового дочернего узла
		 */
		public function createTree($data, $savelog = FALSE) {
			if (!is_array($data)) $data = array();
			
			$treeId = $this->_db->insert($this->table, array_merge(
															array(	$this->tid => 0,
																	$this->left => 1, 
																	$this->right => 2, 
																	$this->level => 0), $data));
			$this->_db->update($this->table, array($this->tid => $treeId), array('id' => $treeId));
			
			return $treeId;
		}
		
		/**
		 * Добавление дочернего узла
		 * 
		 * @param  int $parentId Идентификатор узла к которому будет добавлен новый дочерний
		 * @param  array $data Список полей данных нового дочернего узла
		 * @param  boolean $savelog
		 * @return int
		 */
		public function appendChild($parentId, $data, $savelog = FALSE) {
			$parentId = intval($parentId);
			if (!is_array($data)) $data = array();
			
			if ($parentInfo = $this->getNodeInfo($parentId)) {
				$treeId = $parentInfo['tid'];
				$leftId = $parentInfo['left'];
				$rightId = $parentInfo['right'];
				$level = $parentInfo['level'];
				
				// creating a place for the record being inserted
				$this->_db->query("
									UPDATE $this->table
									SET
											$this->left  = IF($this->left  >  $rightId, $this->left  + 2, $this->left),
											$this->right = IF($this->right >= $rightId, $this->right + 2, $this->right)
									WHERE
											$this->right >= $rightId AND 
											$this->tid = $treeId
								");
			
				$result = $this->_db->insert($this->table, array_merge(
																array(	$this->tid => $treeId,
																		$this->left => $rightId, 
																		$this->right => $rightId + 1, 
																		$this->level => $level + 1), $data));
			
			return FALSE;
		}
		
		/**
		 * Добавление брата - узла справа или слева от указанного
		 * 
		 * @param  int $id Идентификатор узла справа или слева от которого будет добавлен новый узел на том же уровне
		 * @param  array $data Список полей данных нового узла
		 * @param  array $axis Добавление узла правее или левее указанного
		 * @param  boolean $savelog
		 * @return int
		 */
		public function appendSibling($id, $data, $axis = NSTREE_AXIS_FOLLOWING_SIBLING, $savelog = FALSE) {
			$id = intval($id);
			if (!is_array($data)) $data = array();
			
			if ($info = $this->getNodeInfo($id)) {
				list($treeId, $leftId, $rightId, $level) = array($info['tid'], $info['left'], $info['right'], $info['level']);
				
				// creating a place for the record being inserted
				$this->_pushBranch($treeId, $leftId, $rightId, 0, 0, $axis, 2);
				
				if ($axis == NSTREE_AXIS_FOLLOWING_SIBLING) {
					$new_leftId = $rightId + 1;
					$new_rightId = $rightId + 2;
				} else {
					$new_leftId = $leftId - 2;
					$new_rightId = $leftId - 1;
				}
			
				$result = $this->_db->insert($this->table, array_merge(
															array(	$this->tid => $treeId, 
																	$this->left => $new_leftId, 
																	$this->right => $new_rightId, 
																	$this->level => $level), $data));
				
				return $result;
			}
			
			return false;
		}
		
		/**************   Перемещение данных   **************/
		
		/**
		 * Смена родителя у узла
		 * 
		 * @param  int $id Идентификатор перемещаемого узла
		 * @param  int $newParentId Идентификатор нового родителя
		 * @param  boolean $savelog
		 * @return bool
		 */
		public function replaceParent($id, $newParentId, $savelog = FALSE) {
			if ($nodeInfo = $this->getNodeInfo($id)) {
				$parentInfo = $this->getParentNodeInfo($id);
				$newParentInfo = $this->getNodeInfo($newParentId);
				if ($newParentInfo && ($newParentInfo[$this->id] != $parentInfo[$this->id])) {
					list($treeId, $leftId, $rightId, $level) = array($nodeInfo['tid'], $nodeInfo['left'], $nodeInfo['right'], $nodeInfo['level']);
					list($treeIdP, $leftIdP, $rightIdP, $levelP) = array($newParentInfo['tid'], $newParentInfo['left'], $newParentInfo['right'], $newParentInfo['level']);
					
					if ($treeId == $treeIdP) {
						$delta_level = $levelP - $level + 1;
						$delta_index_branch = $rightId - $leftId + 1;
						$delta_index_move = $rightIdP - $leftId - ($rightIdP > $rightId ? $delta_index_branch : 0);
						$sql = "UPDATE $this->table
								SET
										$this->level = IF(
												$this->left BETWEEN $leftId AND $rightId,
												$this->level + $delta_level,
												$this->level
										),
										$this->left = IF(
												$this->left BETWEEN $leftId AND $rightId,
												$this->left + $delta_index_move,
												IF(
														$this->left < $rightIdP AND $this->left > $leftId,
														$this->left - $delta_index_branch,
														IF(
															$this->left > $rightIdP AND $this->left < $leftId,
															$this->left + $delta_index_branch,
															$this->left
														)
												)
										),
										$this->right = IF(
												$this->right BETWEEN $leftId AND $rightId,
												$this->right + $delta_index_move,
												IF(
														$this->right < $rightIdP AND $this->right > $rightId,
														$this->right - $delta_index_branch,
														IF(
															$this->right >= $rightIdP AND $this->right < $rightId,
															$this->right + $delta_index_branch,
															$this->right
														)
												)
										)
								WHERE $this->tid = $treeId
							";
					} else {
						// TODO:
					}
					
					echo 'replace <br> ' . $sql . '<br><br>';
					$result = $this->_db->query($sql);
					
					return $result;
				}
			}
			
			return false;
		}
		
		/**
		 * Смена братьев влево или вправо согласно axis
		 * 
		 * @param int $id
		 * @param $direction
		 *   NSTREE_AXIS_FOLLOWING_SIBLING
		 *   NSTREE_AXIS_PRECENDING_SIBLING
		 * @param boolean $savelog
		 */
		public function swapSiblings($id, $axis, $savelog = FALSE) {
			if ($nodeInfo = $this->getNodeInfo($id)) {
				list($treeId, $leftId, $rightId) = array($nodeInfo['tid'], $nodeInfo['left'], $nodeInfo['right']);
				$delta = $rightId - $leftId + 1;
				
				if ($siblingInfo = $this->_getSiblingInfo($treeId, $id, $axis)) {
					list($leftIdS, $rightIdS) = array($siblingInfo['left'], $siblingInfo['right']);
					$deltaS = $rightIdS - $leftIdS + 1;
					
					if ($axis == NSTREE_AXIS_FOLLOWING_SIBLING) {
						$branch_range = "$leftId AND $rightId";
						$main_range = "$leftId AND $rightIdS";
						$delta_branch = $deltaS;
						$delta_no_branch = $delta;
					} else {
						$branch_range = "$leftIdS AND $rightIdS";
						$main_range = "$leftIdS AND $rightId";
						$delta_branch = $delta;
						$delta_no_branch = $deltaS;
					}
					
					$sql = "UPDATE $this->table
							SET
									$this->left = IF(
											$this->left BETWEEN $branch_range,
											$this->left + $delta_branch,
											$this->left - $delta_no_branch
									),
									$this->right = IF(
											$this->right BETWEEN $branch_range,
											$this->right + $delta_branch,
											$this->right - $delta_no_branch
									)
							WHERE $this->left BETWEEN $main_range 
							  AND $this->tid = $treeId
					";
					
					$result = $this->_db->query($sql);
					
					return $result;
				}
			}
			return false;
		}
		
		/**
		 * Перемещение узла перед узлом с ID = beforeId (слева)
		 * 
		 * @param  int $id
		 * @param  int $beforeId
		 * @return bool
		 */
		public function moveBefore($id, $beforeId) {
			return $this->_replaceBrother($id, $beforeId, NSTREE_AXIS_PRECENDING_SIBLING);
		}
		
		/**
		 * Перемещение узла после узла с ID = afterId (справа)
		 * 
		 * @param  int $id
		 * @param  int $afterId
		 * @return bool
		 */
		public function moveAfter($id, $afterId) {
			return $this->_replaceBrother($id, $afterId, NSTREE_AXIS_FOLLOWING_SIBLING);
		}
			
		/**************   Редактирование данных   **************/
		
		/**
		 * Обновление записи узла
		 *
		 * @param  int $id Идентификатор обновляемого узла
		 * @param  array $data Список полей данных узла
		 * @param  boolean $savelog
		 * @return bool
		 */
		public function updateNode($id, $data, $savelog = FALSE) {
			if (!is_array($data)) return FALSE;
			if (!$id = intval($id)) return FALSE;
			
			if ($idInfo = $this->getNodeInfo($id)) {
			
				$result = $this->_db->update($this->table, $data, array('id' => $id), $savelog);
			
				return $result;
				
			} else return FALSE;
		}
		
		/**
		 * Удаление узла
		 *
		 * @param int $id Идентификатор удаляемого узла
		 * @param boolean $removeChildren Указание на необходимость удалять или не удалять дочерние узлы (если дочерние узлы не удалем то они подымаютс на уровень вверх)
		 * @param boolean $savelog
		 */
		public function removeNodes($id, $removeChildren = TRUE, $savelog = FALSE) {
			if ($info = $this->getNodeInfo($id)) {
				list($treeId, $leftId, $rightId, $level) = array($info['tid'], $info['left'], $info['right'], $info['level']);
				
				if ($removeChildren) {
					$childIds = $this->_db->get_vector("SELECT $this->id AS `id` FROM $this->table WHERE $this->left BETWEEN $leftId AND $rightId AND $this->tid = $treeId");

					if (is_array($childIds)) {
						$child = implode(',', $childIds);
						$count_ids = count($childIds);
						
						// Deleting record(s)
						$this->_db->query("DELETE FROM $this->table WHERE $this->id IN ($child)");
						
						// Clearing blank spaces in a tree
						$deltaId = ($rightId - $leftId) + 1;
						$result = $this->_db->query("
													UPDATE $this->table
													SET
															$this->left = IF($this->left > $leftId, $this->left - $deltaId, $this->left),
															$this->right = IF($this->right > $leftId, $this->right - $deltaId, $this->right)
													WHERE $this->right > $rightId
													  AND $this->tid = $treeId
											");
						
						return $result;
					}
					return false;
				} else {
					$this->_db->delete($this->table, array($this->id => $id));
					
					$result = $this->_db->query("
												UPDATE $this->table
												SET
														$this->left  = IF($this->left BETWEEN $leftId AND $rightId,  $this->left-1,  $this->left),
														$this->right = IF($this->right BETWEEN $leftId AND $rightId, $this->right-1, $this->right),
														$this->level = IF($this->left BETWEEN $leftId AND $rightId,  $this->level-1, $this->level),
														
														$this->left  = IF($this->left > $rightId,                    $this->left-2,  $this->left),
														$this->right = IF($this->right > $rightId,                   $this->right-2, $this->right)
												WHERE $this->right > $leftId
												  AND $this->tid = $treeId
										");
					
					return $result;
				}
			}
			
			return false;
		}
		
		/**
		 * Очистка дерева
		 * 
		 * @param  array $data
		 * @return int
		 */
		public function clear($treeId = 0, $data = array ()) {
			if (!$treeId)
				$this->_db->query("TRUNCATE {$this->table}");
			else
				$this->_db->delete($this->table, array($this->tid => $treeId));
			
			$id = $this->createTree($data);
			
			return $id;
		}
		
		private function _getSiblingInfo($treeId, $id, $axis) {
			if ($axis == NSTREE_AXIS_FOLLOWING_SIBLING)
				return $this->_getFollowingSiblingInfo($treeId, $id);
			elseif ($axis == NSTREE_AXIS_PRECENDING_SIBLING)
				return $this->_getPrecendingSiblingInfo($treeId, $id);
			
			return FALSE;
		}
		
		/**
		 * @param int $id
		 * @return array
		 */
		private function _getFollowingSiblingInfo($treeId, $id) {
			return $this->_getFollowingSibling($treeId, $id, array());
		}
		
		/**
		 * @param int $id
		 * @return array
		 */
		private function _getPrecendingSiblingInfo($treeId, $id) {
			return $this->_getPrecendingSibling($treeId, $id, array());
		}
		
		/**
		 * @param  int $id
		 * @param  string[] $additionalData
		 * @return array
		 */
		private function _getFollowingSibling($treeId, $id, $additionalData) {
			$nodeSet = $this->get($treeId, $id, $additionalData, NSTREE_AXIS_FOLLOWING_SIBLING, 1);
			if (!empty($nodeSet))
				return array_shift($nodeSet);
			
			return FALSE;
		}
		
		/**
		 * @param  int $id
		 * @param  string[] $additionalData
		 * @return array
		 */
		private function _getPrecendingSibling($treeId, $id, $additionalData) {
			$nodeSet = $this->get($treeId, $id, $additionalData, NSTREE_AXIS_PRECENDING_SIBLING, 1, "$this->left DESC");
			if (!empty($nodeSet))
				return array_shift($nodeSet);
			
			return FALSE;
		}
		
		/**
		 * Смена брата узла
		 */
		private function _replaceBrother($id, $broherId, $axis) {				
			if (!is_numeric($id) || !is_numeric($broherId) || !$id || !$broherId || $broherId == $id)
				return FALSE;
			
			if ($nodeInfo = $this->getNodeInfo($id)) {
				list($treeId, $leftId, $rightId, $level, $parentId) = array($nodeInfo['tid'], $nodeInfo['left'], $nodeInfo['right'], $nodeInfo['level'], $nodeInfo['parent_id']);
				
				if ($beforeInfo = $this->getNodeInfo($broherId)) {
					list($treeIdB, $leftIdB, $rightIdB, $levelB, $parentIdB) = array($beforeInfo['tid'], $beforeInfo['left'], $beforeInfo['right'], $beforeInfo['level'], $beforeInfo['parent_id']);
					
					// проверим что узел перед которым нужно поставить не в подмножестве ветки того который двигаем
					if ($treeIdB != $treeId || $leftIdB < $leftId || $leftIdB > $rightId + 1) {
						// creating a place for the branch being inserted
						$delta = $rightId - $leftId + 1;
						if ($treeId == $treeIdB)
							$this->_pushBranch($treeIdB, $leftIdB, $rightIdB, $leftId, $rightId, $axis, $delta);
						else
							$this->_pushBranch($treeIdB, $leftIdB, $rightIdB, 0, 0, $axis, $delta);
						
						// переносим ветку
						$delta_ind = ($axis == NSTREE_AXIS_FOLLOWING_SIBLING) ? $rightIdB + 1 - $leftId : $leftIdB - $leftId;
						$delta_lvl = $levelB - $level;
						$this->_db->query("
											UPDATE $this->table
											SET
													$this->left = $this->left + $delta_ind,
													$this->right = $this->right + $delta_ind,
													$this->level = $this->level + $delta_lvl,
													$this->tid = $treeIdB
											WHERE $this->left BETWEEN $leftId AND $rightId
											  AND $this->tid = $treeId
						");
						
						// задвигаем дерево откуда убрали ветку
						$this->_db->query("
											UPDATE $this->table
											SET
													$this->left = IF($this->left < $leftId, $this->left, $this->left - $delta),
													$this->right = $this->right - $delta
											WHERE $this->right > $rightId
											  AND $this->tid = $treeId
						");
					}
				}
			}
			return false;
		}
		
		/**
		 * Оттолкнуть ветвь (раздвинуть, чтоб можно было вставить запись или другую ветвь)
		 * 
		 * @param int $treeId Дерево
		 * @param int $leftId Левой индекс двигаемого узла
		 * @param int $rightId Правый индекс двигаемого узла
		 * @param int $exclude_leftId Исключив узлы ветки левый индекс которых от этого значени до $exclude_rightId
		 * @param int $exclude_rightId 
		 * @param int $axis Раздвигаем так, чтоб:
		 * 					- пространство было перед узлом (слева) - NSTREE_AXIS_PRECENDING_SIBLING
		 * 					- пространство было после узла (справа) - NSTREE_AXIS_FOLLOWING_SIBLING
		 * @param int $delta Количство освобождаемого пространства (индексы от левого до правого помещаемой ветви)
		 */
		private function _pushBranch($treeId, $leftId, $rightId, $exclude_leftId, $exclude_rightId, $axis, $delta) {
				if ($axis == NSTREE_AXIS_FOLLOWING_SIBLING) {
					$sqlIfForLeftSet = "$this->left > $rightId";
					$sqlWhere = "$this->right > $rightId";
				} else {
					$sqlIfForLeftSet = "$this->left >= $leftId";
					$sqlWhere = "($this->left >= $leftId OR $this->right > $rightId)";
				}
				
				$sqlExclude = ($exclude_leftId && $exclude_rightId) ? 
									"AND $this->left NOT BETWEEN $exclude_leftId AND $exclude_rightId)" : "";
				
				$this->_db->query("
									UPDATE $this->table
									SET
											$this->left  = IF($sqlIfForLeftSet, $this->left + $delta,  $this->left),
											$this->right = $this->right + $delta
									WHERE
											$sqlWhere
										AND $this->tid = $treeId
										$sqlExclude
								");
			
		}
	}