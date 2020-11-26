<?php
namespace Plugin\Model;
use Plugin\AlphaID\AlphaID;

/**
 * Трейт для генерации и работы с разными видами идентификаторов
 */
trait IdTrait {

  /**
   * Генерация ид с жизнью до 35 лет для signed и 70 unsigned
   *
   * @param int $id
   *   Идентификатор, по которому идет парцирование данных, если не указан,
   *   то выбирается случайное число
   * @return int
   */
  protected static function generateId($id = 0) {
    // Пытаемся получить метку и сгенерировать ид
    if ($epoch = config('common.epoch')) {
      return (floor(microtime(true) * 1000 - $epoch) << 23) + (mt_rand(0, 4095) << 13) + (($id ? $id : lcg_value() * 10000000) % 1024);
    }

    return 0;
  }

  /**
   * Получение альфа ид по идентификатору
   *
   * @param int $id
   * @return $this
   */
  public static function getByAlphaId($id) {
    return static::get(static::decodeId($id));
  }

  /**
   * Gjkextybt Alpha id текущего айтема
   *
   * @return string
   */
  public function getAlphaId($id = null) {
    return static::encodeId($id ?: $this->getId());
  }

  /**
   * Кодирование ида
   *
   * @param int $id
   * @return string
   */
  protected static function encodeId($id) {
    return AlphaID::encode($id, config('common.alphabet'));
  }

  /**
   * Декодирование ида
   *
   * @param string $id
   * @return int
   */
  protected static function decodeId($id) {
    return AlphaID::decode($id, config('common.alphabet'));
  }

  /**
   * @param array $ids
   * @return string
   */
  protected static function packIds(array $ids) {
    return implode(', ', $ids);
  }

  /**
   * @param string $id_string
   * @return array
   */
  protected static function unpackIds($id_string) {
    assert(is_string($id_string));
    return $id_string ? array_map('trim', explode(',', $id_string)) : [];
  }
}
