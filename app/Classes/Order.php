<?php

namespace App\Classes;

class Order
{
    /**
     * @var bool Признак того, что объёма недостаточно для конвертации
     */
    public bool $volumeFailed = true;

    /**
     * @var float Сумма комиссий
     */
    public float $marketFEE;

    /**
     * @var int Число транзакций
     */
    public int $transactions = 0;

    /**
     * @var float Сумма, в которую будет конвертировано
     */
    public float $convertToSum = 0.0;

    /**
     * @var float Потраченная сумма (сколько мы хотим обменять)
     */
    public float $spend = 0.0;

    /**
     * @var array Массив с логами калькуляции
     */
    public array $log = [];

    /**
     * @param array $orders Ордеры на покупку или продажу (не весь стакан)
     * @param float $forSell Сколько нужно потратить
     * @param bool $isInvert Признак обратного обмена. Прим. XEM/BTC, а меняем BTC -> XEM
     * @param bool $isStraight Признак прямой пары. Цена всегда указана в фиатах
     * @return void
     */
    public function calcOrder(array $orders, float $forSell, bool $isInvert, bool $isStraight): void
    {
        $spend = 0;
        $transactions = 0;
        $convertToSum = 0;
        $this->marketFEE = 0;

        foreach ($orders as $order){
            $orderPrice = $order[0];
            $orderCount = $order[1];
            $orderPriceReal = $orderPrice;

            if ($isInvert){
                if($isStraight){
                    $orderCount = $orderCount * $orderPrice;
                    $orderPrice = 1 / $orderPrice;
                }else{
                    $orderPrice = 1 / $orderPrice;
                    $orderCount = $orderCount * $orderPriceReal;
                }
                $orderPriceReal = 1 / $orderPrice;
            }

            // Комиссия биржи
            $orderFeeMax = $orderCount * Binance::getFee();

            // Округляем в большую сторону на self::PRECISION знаке после точки
            $orderFeeMax = self::roundUp($orderFeeMax); // 0.0007745913617 -> 0.000774592

            // Инкремент числа транзакций
            $transactions++;

            // Сколько осталось потратить
            $arrival = $forSell - $spend;

            // Проверка, нужна ли нам вся доступная заявка
            $need = $arrival - $orderCount - $orderFeeMax;

            // Массив для проверок в логах
            $needCalc = [$forSell, $spend, $orderCount, $orderFeeMax];

            if($need > 0){
                $iterationSpend = $orderCount;
                $iterationFee = $orderFeeMax;
                $needAllOrder = true;
                $spendIncrement = $iterationSpend + $orderFeeMax;
                $convertToSumIncrement = $orderPrice * $orderCount;
            }else{
                $iterationSpend = $forSell - $spend;
                $iterationFee = self::roundUp($iterationSpend * Binance::getFee());
                $needAllOrder = false;
                $spendIncrement = $iterationSpend;
                $iterationSpend -= $iterationFee;
                $convertToSumIncrement = $iterationSpend * $orderPrice;
            }

            $spend += self::roundUp($spendIncrement);
            $convertToSum += self::roundDown($convertToSumIncrement);
            $this->log[] = OrderLog::makeLogArray([
                    'spend' => $spend,
                    'convertToSum' => $convertToSum,
                    'forSell' => $forSell,
                    'isInvert' => $isInvert,
                    'arrival' => $arrival,
                    'orderPrice' => $orderPrice,
                    'orderCount' => $orderCount,
                    'orderPriceReal' => $orderPriceReal,
                    'iterationFee' => $iterationFee,
                    'iterationSpend' => $iterationSpend,
                    'need' => $need,
                    'needCalc' => $needCalc,
                    'needAllOrder' => $needAllOrder,
                    'order' => $order
                ]);

            // Заносим в массив для дальнейшего вывода суммы комиссии
            $this->marketFEE += $iterationFee;

            // Выходим из цикла, если ликвидности достаточно и сможем потратить всю исходную сумму
            if(!$needAllOrder){
                $this->volumeFailed = false;
                break;
            }
        }
        $this->spend = $spend;
        $this->transactions = $transactions;
        $this->convertToSum = $convertToSum;
    }

    /**
     * @param float $number Сумма
     * @return float Округленная сумма
     */
    static public function roundUp(float $number): float
    {
        return  round($number, Calculator::PRECISION);
    }

    /**
     * @param float $number Сумма
     * @return float Округленная сумма
     */
    static public function roundDown(float $number): float
    {
        return  round($number, Calculator::PRECISION, PHP_ROUND_HALF_DOWN);
    }
}
