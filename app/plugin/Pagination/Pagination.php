<?php
namespace Plugin\Pagination;
/**
 * Класс для работы с системой постраничной навигации
 *
 * @final
 * @package Core
 * @subpackage Pagination
 */
class Pagination {
  /**
   * @property int $limit Per page limit
   * @property int $total All count of items to be paginated
   * @property int $page Current page
   */
  private
  $limit        = 1000,
  $total        = 0,
  $page         = 1;

  private static $Instance = null;

  /**
   * @static
   * @access public
   * @return Pagination
   *
   * <code>
   * $list = Pagination::create([
   *  'page'  => 1,
   *  'limit' => 15,
   *  'total' => 10,
   * ])->listResult($items_from_database);
   * </code>
   */
  public static function create(array $conf = []) {
    $Obj = new static;
    foreach ($conf as $k => $v) {
      $Obj->$k = $v;
    }

    return $Obj;
  }

  /**
   * Получение текущей страницы
   *
   * @return int
   */
  public function getCurrentPage() {
    $page = $this->page;
    if ($page < 1) {
      $page = 1;
    }
    // Hot fix ffs
    return $page > ($last_page = $this->getLastPage()) ? $last_page : $page;
  }

  public function getMaxPage() {
    return $this->getTotal() ? (int) ceil($this->getTotal() / $this->limit) : 1;
  }

  public function getOffset() {
    return ($this->getCurrentPage() - 1) * $this->getLimit();
  }

  public function getLimit() {
    return $this->limit ?: $this->total;
  }

  public function setTotal($total) {
    $this->total = $total;
    return $this;
  }

  public function getTotal() {
    return $this->total;
  }

  /**
   * Получение номера последней страницы
   *
   * @return int
   */
  public function getLastPage() {
    return ($this->total && $this->limit)
      ? (int) ceil($this->total / $this->limit)
      : 1;
  }

  /**
   * Получение итогового массива страниц для отображения
   *
   * @return array [param_name, current, last, next_url, prev_url, pages[[page, url]]
   */
  public function getArray() {
    $data = [];
    $cur_page   = $this->getCurrentPage();
    $last_page  = $this->getLastPage();

    $data['has_pages']  = $last_page > 1;
    $data['current']    = $cur_page;
    $data['last']       = $last_page;

    $data['prev_page'] = '';
    if ($cur_page > 1) {
      $data['prev_page'] = $cur_page - 1;
    }

    $data['next_page'] = '';
    if ($cur_page !== $last_page) {
      $data['next_page'] = $cur_page + 1;
    }

    // @TODO: сформировать страницы
    for ($i = 1; $i <= $last_page; $i++) {
      $data['pages'][] = ['page' => $i, 'current' => $i === $cur_page];
    }
    return $data;
  }

  /**
   * Проверка возвращаемых данных на предмет унификации
   * Добавление необходимых переменных лимитов и постраничного вывода
   *
   * @access protected
   * @param array $result
   *   Набор данных, которые нужно трансформировать в список
   * @return array
   */
  public function listResult(array $result) {
    // Ничего нет? Ну и к черту :D
    if (!$result)
      return [];

    return [
      'items'   => array_values($result),
      'total'   => $this->getTotal(),
      'offset'  => $this->getOffset(),
      'limit'   => $this->getLimit(),
      'page'    => $this->getCurrentPage(),
      'max_page'      => $this->getLastPage(),
      'has_items'     => !!$result,
      'has_no_items'  => !$result,
      'pagination' => $this->getArray()
    ];
  }
}
