<?php
namespace App\Model;

class Tx extends \Plugin\Model\Model {
  protected static $id_field = 'tx';

  public function getAddressBalances(array $addresses) {
    $addr_params = array_map(function ($k) {
      return 'addr' . $k;
    }, array_keys($addresses));
    $in_str = implode(', ', preg_filter('/^/', ':', $addr_params));
    $params = array_combine($addr_params, $addresses);
    $q = 'SELECT address, SUM(amount) AS balance FROM ' . static::table() . ' WHERE address IN (' . $in_str . ') GROUP BY address';
    return $this->dbQuery($q, $params);
  }
}