<?php
namespace Plugin\Item;
/**
 * Абстрактный класс с базовыми возможностями для объекта управления элементами
 *
 * @abstract
 * @package Core
 * @subpackage ItemManager
 */
abstract class Manager {
  /**
    * @property string $model
    * @property string $method
    * @property array $calls
    * @property array $states Состояния моделей, получаемых данные
    * @property array $ids
    * @property array $batch Список задач для выполнения, после выполнений текущей (последовательно)
    * @property array $data Данные, которые используются, или куда пишется результат
    */
  protected
  $model    = '',
  $method   = '',
  $ids      = [],
  $batch    = [],
  $data     = null;
}
