<?php
namespace Plugin\Model;
use Plugin\DB\DB;
use Plugin\Cache\Cache;
use App;
/**
 * Трейт реализует доступ к SQL-базе данных через функцию обертку db() (Mysql)
 */
trait DatabaseTrait {
  /**
   * @property int $shard_id Текущий идентификатор шарда, использующийся для коннекта
   * @property string $table статическая переменная с таблицей
   * @property array $fields Все поля для данной таблицы
   */
  protected static
    $shard_id     = 0,
    $table        = '',
    $id_field     = 'id',
    $fields       = [],
    $incremental_id = false;

  /**
   * Получение строки для sql-строки с параметрами, поддерживает как
   * ассоциативный, так и обычный индексный массив
   *
   * @access protected
   * @param array $params список параметров с данными
   * @param string $sep разделитель при объединении параметров
   * @param bool $incremental
   * @return string подготовленная строка для передачи в запрос
   */
  protected static function dbGetSqlStringByParams(array $params, $sep = ',', $incremental = false) {
    $data = []; // массив данных для объединения
    foreach ($params as $param => $value) {
      if (is_string($param)) {
        $data[] = '`' . $param . '` = ' . ($incremental ? '`' . $param . '` + ' : '' ) . ' :' . $param;
      } else {
        $data[] = '`' . $value . '`';
      }
    }

    return implode(' ' . $sep . ' ', $data);
  }

  public static function table() {
    if (!static::$table) {
      static::$table = strtolower(str_replace(chr(92), '_', get_class_name(static::class)));

      // Инициализация таблицы
      if (static::table()[0] !== '`')
        static::$table = '`' . static::table() . '`';
    }
    return static::$table;
  }

  /**
   * @param bool $with_value Include mysql default values or no
   * @return array
   */
  public static function fields($with_values = false) {
    static $keys = [];
    static $fields = [];
    if (!$fields) {
      $func = function () {
        $incremental_id = false;
        $fields = [];
        if ($data = static::dbQuery('DESCRIBE ' . static::table())) {
          $fields = array_column($data, 'Default', 'Field');
          if ($data[0]['Field'] === static::$id_field && $data[0]['Extra'] === 'auto_increment') {
            $incremental_id = true;
          }
        }
        return [$fields, $incremental_id];
      };
      list($fields, static::$incremental_id) = 
        App::$debug ? $func() : Cache::get('db:scheme:' . static::table(), $func);
      $keys = array_keys($fields);
    }
    return $with_values ? $fields : $keys;
  }

  protected function isIncrementalId() {
    return static::$incremental_id;
  }


  /**
   * Выполнение запросов к базе данных
   *
   * @access protected
   * @param string $query
   * @param array $params
   * @return mixed
   * @throws Exception
   */
  protected static function dbQuery($query, array $params = []) {
    return DB::query($query, static::adaptParams($params), static::dbShardId($params));
  }

  protected static function adaptParams(array $params) {
    return array_combine(
      array_map(function ($key) {
        if (false !== stripos($key, ':')) {
          return strtok($key, ':');
        }
        return $key;
      }, array_keys($params)),
      $params
    );
  }

  /**
   * Логика определния идентификатора шарда по передаваемым параметрам в запрос
   *
   * @param array $params
   * @return int
   */
  protected static function dbShardId(array $params = null) {
    return 0;
  }

  /**
   * Выполнение запроса вставки в базу данных
   *
   * @uses self::dbGetSqlStringByParams()
   * @uses Database::execute()
   *
   * @access protected
   * @param array $params список параметров для передачи в запрос
   * @return bool
   */
  protected function dbInsert(array $params) {
    $q = 'INSERT INTO ' . static::table()
      . ' SET ' . self::dbGetSqlStringByParams($params, ',');
    return static::dbQuery($q, $params);
  }

  /**
   * Get last inserted id in case of auto_increment
   */
  protected function dbInsertId() {
    $result = static::dbQuery('SELECT LAST_INSERT_ID() AS `' . static::$id_field . '`');
    if ($result && isset($result[0])) {
      return $result[0][static::$id_field];
    }

    return 0;
  }
  
  /**
   * Формирование условия WHERE по передаваемым параметрам
   *
   * @param array &$conditions
   * @return array
   */

  protected function dbGetWhere(array &$conditions) {
    $where = [];
    if ($conditions) {
      $params = $conditions;
      $i = 0;
      foreach ($params as $k => $c) {
        // If we have typed field
        $t = '';
        if (false !== stripos($k, ':')) {
          [$k, $t] = explode(':', $k);
        }
        if (is_array($c)) {
          if ($c) {
            // Собриаем параметры с идентификаторами
            $id_params = [];
            foreach ($c as $v) {
              $id_params[] = sprintf('ID%d', ++$i);
            }

            $conditions = array_merge($conditions, array_combine($id_params, $c));
            if ($t === 'range') {
              $where[] = ' (`' . $k . '` BETWEEN :' . implode(' AND :', $id_params) . ') ';
            } else {
              $where[] = ' `' . $k . '` IN (:' . implode(', :', $id_params) . ') ';
            }
          } else {
            $where[] = ' `'. $k . '` = NULL ';
          }
          unset($conditions[$k]);
        } else {
          // Bash like style
          // See bash scripting
          $field = $k . '_' . $t;
          switch ($t) {
            case 'gt':
              $op = '>';
              break;
            case 'ge':
              $op = '>=';
              break;
            case 'lt':
              $op = '<';
              break;
            case 'le':
              $op = '<=';
              break;
            case 'ne':
              $op = '!=';
              break;
            default:
              $op = '=';
              $field = $k;
          }
          $where[] = ' `' . $k . '` ' . $op  . ' :' . $field . ' ';
          if ($field !== $k) {
            $conditions[$k . '_' . $t] = $conditions[$k . ':' . $t];
            unset($conditions[$k . ':' . $t]);
          }
        }
      }
    }
    return $where;
  }

  /**
   * Выполнение SELECT-запроса из базы данных
   *
   * @uses self::dbGetSqlStringByParams()
   * @uses Database::query()
   *
   * @access protected
   * @param array $fields список полей для выборки
   * @param array $conditions список условий
   * @param array $order условия сортировки
   * @param int $offset офсет для лимитной выборки
   * @param int $limit лимит на выборку, если нужне, 0 - если безлимитная выборка
   * @return Database::query()
   */
  protected function dbSelect(array $fields, array $conditions = null, array $order = null, $offset = null, $limit = null) {
    // Если нужно формировать строку сортировки
    $order_string = '';

    if ($order) {
      foreach ($order as $field => $sort) {
        $order_string .= ', `' . $field . '` ' . strtoupper($sort);
      }
      $order_string = trim($order_string, ', ');
    }

    // Строка условия - special logic :)
    $where = $conditions ? $this->dbGetWhere($conditions) : null;
    /*
    // Данные трейта TPagination, исключаем, чтобы избавиться от зависимости
    if (!isset($offset) || !isset($limit)) {
      $limit  = $this->limit;
      $offset = $this->offset;
    }
    */
    $q = 'SELECT ' . self::dbGetSqlStringByParams($fields)
      . ' FROM ' . static::table()
      . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
      . ($order_string ? ' ORDER BY ' . $order_string : '')
      . ($limit ? ' LIMIT ' . (int) $offset . ', ' . (int) $limit : '');

    return self::dbQuery($q, $conditions);
  }

  /**
   * Получение количества элементов, согласно выборке с условием
   *
   * @access protected
   * @param array $conditions
   * @return int
   */
  protected function dbCount(array $conditions = []) {
    $where = $conditions ? $this->dbGetWhere($conditions) : null;
    $q = 'SELECT COUNT(*) AS `count` FROM ' . static::table()
      . ($where ? ' WHERE ' . implode('AND', $where) : '')
      . ' LIMIT 1';
    $rows = static::dbQuery($q, $conditions);
    $count = 0;
    if (isset($rows[0])) {
      $count = (int) $rows[0]['count'];
    }
    return $count;
  }

  /**
   * Получение одной строки (LIMIT 1)
   *
   * @see self::dbSelect
   */
  protected function dbGet(array $fields, array $conditions = null, array $order = null) {
    $rows = $this->dbSelect($fields, $conditions, $order, 'AND', 0, 1);
    return isset($rows[0]) ? $rows[0] : [];
  }

  /**
   * Выполнение UPDATE-запроса в базу данных
   *
   * @uses self::dbGetSqlStringByParams()
   * @uses Database::execute()
   *
   * @access protected
   * @param array $params список параметров для установки в запросе
   * @param array $conditions список условий
   * @return Database::execute()
   */
  protected function dbUpdate(array $params, array $conditions, $incremental = false) {
    $q = 'UPDATE ' . static::table()
      . ' SET ' . self::dbGetSqlStringByParams($params, ',', $incremental)
      . ' WHERE ' . self::dbGetSqlStringByParams($conditions, ' AND ');
    return static::dbQuery($q, array_merge($params, $conditions));
  }

  /**
   * @access protected
   * @param array $params
   * @param array $ids
   * @param bool $incremental
   * @return Database::execute()
   */
  protected function dbUpdateByIds(array $params, array $ids, $incremental = false) {
    // Собриаем параметры с идентификаторами
    $id_params = [];
    $i = 0;
    foreach ($ids as $id) {
      $id_params[] = sprintf('ID%d', ++$i);
    }
    $q = 'UPDATE ' . static::table()
      . ' SET ' . self::dbGetSqlStringByParams($params, ',', $incremental)
      . ' WHERE `' . static::$id_field . '` IN (:' . implode(', :', $id_params) . ')';

    return static::dbQuery($q, array_merge($params, array_combine($id_params, $ids)));
  }

  /**
   * Выполнение DELETE-запроса в базе данных
   *
   * @uses self::dbGetSqlStringByParams()
   * @uses Database::execute()
   *
   * @param array $conditions список условий
   * @return Database::execute()
   */
  protected function dbDelete(array $conditions) {
    $q = 'DELETE FROM ' . static::table()
      . ' WHERE ' . self::dbGetSqlStringByParams($conditions, ' AND ');
    return static::dbQuery($q, $conditions);
  }

  /**
   * Удаление по праймери
   *
   * @param array $ids
   * @return Database::execute()
   */
  protected function dbDeleteByIds(array $ids) {
    return $this->dbDeleteByRowValues(static::$id_field, $ids);
  }

  /**
   * @param string $row
   * @param array $values
   * @return Database::execute()
   */
  protected function dbDeleteByRowValues($row, array $values) {
    $q = 'DELETE FROM ' . static::table()
      . ' WHERE `' . $row . '` IN (' . trim(str_repeat('?,', sizeof($values)), ',') . ')';
    return static::dbQuery($q, $values);
  }

  /**
   * @param string $row
   * @param mixed $value
   * @return self::dbDeleteByRowValues()
   */
  protected function dbDeleteByRowValue($row, $value) {
    return $this->dbDeleteByRowValues($row, [$value]);
  }

  /**
   * Получение элементов по всем указанным ID и кэширование при необходимости
   *
   * @uses self::dbGetSqlStringByParams()
   * @uses Database::query()
   *
   * @param array $fields список полей для выборки из таблицы
   * @param array $ids список все необходимых ID (primary key)
   * @return Database::query() результат выборки
   */
  protected function dbGetByIds(array $fields, array $ids) {

    return $this->dbGetByFields($fields, static::$id_field, $ids);
  }

  /**
   * Получение данных из таблиы по одному ид
   *
   * @uses self::getByIds()
   *
   * @param array $fields поля для выборки из таблицы
   * @param int $id
   * @return array данные выборки
   */
  protected function dbGetById(array $fields, $id) {
    return $this->dbGetByField($fields, static::$id_field, $id);
  }

  /**
   * @param array $fields
   * @param string $row
   * @param array $values
   * @return array [id => data, ...]
   */
  protected function dbGetByFields(array $fields, $row, array $values) {
    assert(sizeof($values) > 0);

    $q = 'SELECT ' . self::dbGetSqlStringByParams($fields)
      . ' FROM ' . static::table()
      . ' WHERE `' . $row . '` IN (' . trim(str_repeat('?, ', sizeof($values)), ', ') . ')';
      ;
    return ($data = self::dbQuery($q, $values))
      ? array_combine(array_column($data, static::$id_field), $data)
      : $data;
  }


  /**
   * @see self::dbGetByFields()
   */
  protected function dbGetByField(array $fields, $row, $value) {
    $rows = $this->dbGetByFields($fields, $row, [$value]);
    return array_shift($rows);
  }


  /**
   * Переключение состояния у поля в базе
   *
   * @param string $field
   * @param int $id
   * @param int $prev_value Предыдущее значение поля
   * @return int
   */
  protected function dbToggleField($field, $id, $prev_value = null) {
    $q = 'UPDATE ' . static::table()
      . ' SET `' . $field . '` = IF (`' . $field . '` = 1, 0, 1)'
      . ' WHERE `' . static::$id_field . '` = :id'
      . (isset($prev_value) ? ' AND `' . $field . '` = :prev_value' : '');

    $params = [static::$id_field => $id];

    if (isset($prev_value))
      $params['prev_value'] = $prev_value;

    return static::dbQuery($q, $params);
  }

  protected function dbGetPaginated($query, array $params = []) {
    assert(is_string($query));
    $total = $this->Pagination ? $this->Pagination->getTotal() : 0;
    $query = 'SELECT %s FROM ' . static::table() . ' ' . $query . ' LIMIT %d, %d';

    if (!$total) {
      $row = self::dbQuery(sprintf($query, ...['COUNT(*) AS `count`', 0, 1]), $params);
      $total = $row ? $row[0]['count'] : 0;
    }

    $offset = null;
    $limit = null;
    if ($this->Pagination) {
      $this->Pagination->setTotal($total);
      $offset = $this->Pagination->getOffset();
      $limit = $this->Pagination->getLimit();
    }

    $result = $total ? self::dbQuery(sprintf($query, ...['*', $offset, $limit]), $params) : [];
    array_walk($result, [$this, 'prepare']);
    return $result;
  }

  /**
   * Получение всего списка с данными или списка по условию
   *
   * @see self::getList( );
   */
  public function getList(array $conditions = [], array $order = []) {
    $total = $this->Pagination ? $this->Pagination->getTotal() : 0;

    if (!$total)
      $total = $this->dbCount($conditions);

    $offset = null;
    $limit = null;
    if ($this->Pagination) {
      $this->Pagination->setTotal($total);
      $offset = $this->Pagination->getOffset();
      $limit = $this->Pagination->getLimit();
    }
    return static::getByIds(
      $total ? array_column($this->dbSelect([static::$id_field], $conditions, $order, $offset, $limit), static::$id_field) : []
    );
  }
}
