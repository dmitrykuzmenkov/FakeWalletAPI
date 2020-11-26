<?php
namespace Plugin\Item;
use Plugin\Pagination\Pagination;
use Exception;

/**
 * Загрузчик сущностей, доступ через объект Entity
 *
 * @final
 * @package Core
 * @subpackage Fetcher
 */
class Fetcher extends Manager {
  /**
   * @property string $src_key
   * @property string $root_key
   *   Корневой ключ, откуда идет выборка $src_key (должен представлять собой массив)
   * @property string $dst_key
   */
  protected $src_key  = '';
  protected $root_key = '';
  protected $dst_key  = '';

  protected $Pagination = null;

  /**
   * Создание загрузчика данных и постановка первого задания
   *
   * @access public
   * @static
   * @param string $mapper
   *   Имя маппера, которые обязауется подгружать данные
   * @param string $src_key
   *   Индекс идентификатора
   * @param mixed $args
   *   Массив или строка/число - однозначный идентификатор
   * @param array $data
   *   Массив с результатами (если была уже агрегированная выборка)
   * @param array $batch
   *   Массив оппераций, которые будут выполнены в параллели
   * @return Fetcher
   */
  public static function create($mapper, $src_key, array $args = null, array &$data = [], array $batch = []) {
    $Self = new self;
    if (is_string($mapper)) {
      $model  = $mapper;
      $method = $args ? 'get' : 'getList';
    } elseif (is_array($mapper)) {
      list($model, $method) = $mapper;
    } else {
      throw new Exception('Mapper can be string or array with 2 elements');
    }

    $Self->model  = $model;
    $Self->method = $method;

    $Self->src_key    = strtok($src_key, ':');
    $Self->dst_key    = $Self->getDstKey($src_key);
    $Self->args       = $args;
    $Self->batch      = $batch;
    $Self->data       = &$data;
    return $Self;
  }

  /**
   * Установка корневого ключа, откуда будет браться src_key
   *
   * @param string $root_key
   * @return $this
   */
  public function setRootKey($root_key) {
    $this->root_key = $root_key;
    return $this;
  }

  /**
   * Получение ключа назначения (куда будут слиты данные)
   * Обычно передается ключ вида user_id, соответсвенно ключ будет user
   * Допускается передача с явным указанием клча назначения user_id:user (запишет в user)
   *
   * @access protected
   * @param string $key
   * @return string
   */
  protected function getDstKey($key) {
    if (false !== strpos($key, ':'))
      return explode(':', $key)[1];

    return substr($key, 0, strrpos($key, '_'));
  }

  /**
   * Инициализцаия постраничной выборки
   *
   * @access public
   * @param int $page
   * @param int $limit
   * @param int $total
   * @return $this
   */
  public function paginate($page, $limit, $total = 0) {
    $this->Pagination = Pagination::create([
      'page'      => $page,
      'limit'     => $limit,
      'total'     => $total,
    ]);
    return $this;
  }

  /**
   * Выполнение в последовательном режиме
   *
   * @access public
   * @return $this
   */
  public function dispatch() {
    if (!$this->data) { // Если данных не было передано, подгружаем
      $Obj = forward_static_call([$this->model, 'create']);
      if ($this->Pagination) {
        $Obj->setPagination($this->Pagination);
      }

      // Хапаем основные данные
      $this->data = call_user_func_array(
        [$Obj, $this->method],
        $this->args
      );

      if ($this->method === 'get') {
        $this->data = $this->data->getData();
      }

      // Refactor this shit later
      if ($this->Pagination) {
        $this->data = $this->Pagination->listResult($this->data);
      }
    }

    // Если нужно выполнить действия, зависимые от первого
    if ($this->data && $this->batch) {
      $prev = null;
      foreach ($this->batch as $Fetcher) {
        // Подразумевает обход и доступ многократный к свойствам, поэтому читерим ненмого
        $dk = $Fetcher->dst_key;
        $sk = $Fetcher->src_key;
        $rk = $Fetcher->root_key ? explode('.', $Fetcher->root_key) : [];

        $Obj = forward_static_call([$Fetcher->model, 'create']);

        // Возвращаемые данные списков могут быть просто данными
        // или же специальными постраничными списками, тогда данные находятся
        // в индексе items
        $is_list = false;
        if (isset($this->data['items']) && is_array($this->data['items'])) {
          $data = &$this->data['items'];
          $is_list = true;
        } else {
          $data = &$this->data;
        }

        // Если есть рутовый ключ
        if ($prev && $rk) {
          foreach ($rk as $key) {
            if (array_key_exists($key ,$data)) {
              $data = &$data[$key];
            }
          }
        }

        if (isset($data[0]))
          $is_list = true;

        // Массив выборки или один элемент
        if ($is_list) {
          $array = $data;
          if ($rk) {
            foreach ($rk as $key) {
              $array = array_column($array, $key);
            }
          }

          $ids = array_column($array, $sk);
          unset($array);

          if (isset($ids[0]) && is_array($ids[0]))
            $ids = call_user_func_array('array_merge', $ids);

          $items = $Obj->getByIds($ids);
          foreach ($data as &$item) {

            if ($rk) {
              eval('$row = &$item[\'' . implode('\'][\'', $rk) . '\'][\'' . $sk . '\'];');

            } else {
              $row = $item[$sk];
            }
            //$row  = $rk ? $item[$rk][$sk] : $item[$sk];

            if ($rk) {
              eval('$dest = &$item[\'' . implode('\'][\'', $rk) . '\'][\'' . $dk . '\'];');
              //$dest = &$item[$rk][$dk];
            } else {
              $dest = &$item[$dk];
            }

            $dest = is_array($row)
                  ? array_values(array_intersect_key($items ? $items : [], array_flip($row)))
                  : (isset($items[$row]) ? $items[$row] : null);
          }
        } else {
          $this->data[$dk] = is_array($this->data[$sk]) ? $Obj::getByIds($this->data[$sk]) : $Obj::get($this->data[$sk])->getData();
        }
        $prev = $sk;
      }

    }
    return $this;
  }
}
