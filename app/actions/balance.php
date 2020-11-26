<?php
/**
 * @route balance
 * @param array $addresses
 */

use App\Model\Tx;
$balances = [];
if ($addresses) {
  $balances = Tx::create()->getAddressBalances($addresses);

  // Generate random data if not have
  if (!$balances) {
    foreach ($addresses as $address) {
      for ($i = 0; $i < mt_rand(1, 15); ++$i) {
        $item = [
          'tx' => bin2hex(random_bytes(32)),
          'address' => $address,
          'amount' => bcdiv(mt_rand(-10 ** 9, 10 ** 9), 10 ** 8, 8)
        ];
        $data[] = $item;
        Tx::create()->save($item);
      }
    }
    $balances = Tx::create()->getAddressBalances($addresses);
  }
}

return View::fromString(json_encode($balances));
