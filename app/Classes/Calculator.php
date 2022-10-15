<?php

namespace App\Classes;

class Calculator
{
    /**
     * @var string Из какой валюты конвертируем
     */
    protected string $from;

    /**
     * @var string В какую валюту конвертируем
     */
    protected string $to;

    /**
     * @var string Символ тикера
     */
    protected string $symbol;

    /**
     * @var float Сколько мы хотим сконвертировать валюты
     */
    protected float $count;

    /**
     * @var array Массив с вариантами конвертации
     */
    protected array $variants;

    /**
     * Точность чисел
     */
    const PRECISION = 8;

    /**
     * Сеттер исходных данных
     * @param string $from Из какой монеты конвертируем
     * @param string $to В какую монету конвертируем
     * @param float $count Количество, которое хотим обменять
     * @return void
     */
    public function setExchangeData(string $from, string $to, float $count):void
    {
        $this->from = $from;
        $this->to = $to;
        $this->count = $count;
        $binance = new Binance();
        $tickers = $binance->getBinanceTickers();
        $symbol = $from.'/'.$to;
        $this->symbol = (array_key_exists($symbol, $tickers['data'])) ? $symbol : $to.'/'.$from;
    }

    /**
     * Сеттер доступных вариантов для конвертации
     * @param array $variants Массив доступных вариантов
     * @return void
     */
    public function setVariants(array $variants):void
    {
        $this->variants = $variants;
    }

    /**
     * @return array Массив с результатами калькуляции
     */
    public function getPrice(): array
    {
        $array = [];
        $data = config('app.prices_array');
        $binance = new Binance();

        // Можно обменять напрямую
        if($this->variants['level0']['status']){
            $orderBook = $binance->getOrderBook($this->symbol);
            $data['level0']['status'] = true;
            $data['level0']['data'] = $this->getTransactionsSum($orderBook, $this->symbol);

        // Можно обменять через 1 промежуточный коин
        }elseif( $this->variants['level1']['status']){

            foreach ($this->variants['level1']['data']['fromToGap'] as $symbol){
                $orderBook = $binance->getOrderBook($symbol);
                $arrayKey = $this->makeToKey($symbol);
                $array['fromToGap'][$arrayKey] = $this->getTransactionsSum($orderBook, $symbol);
            }

            foreach ($this->variants['level1']['data']['gapToEnd'] as $symbol){
                $orderBook = $binance->getOrderBook($symbol);
                $arrayKey = $this->makeFromKey($symbol);
                $array['gapToEnd'][$arrayKey] = $this->getTransactionsSum($orderBook, $symbol, $array['fromToGap'][$arrayKey], $arrayKey);
            }
            $data['level1']['status'] = true;
            $data['level1']['data'] = $array['gapToEnd'];
        }

        return $data;
    }

    /**
     * @param string $symbol Символ тикера
     * @return string Символ коина, в который конвертируется
     */
    private function makeToKey(string $symbol): string
    {
        return str_replace($this->from.'/', '', str_replace('/'.$this->from, '', $symbol));
    }

    /**
     * @param string $symbol Символ тикера
     * @return string Символ коина, из которого конвертируется
     */
    private function makeFromKey(string $symbol): string
    {
        return str_replace($this->to.'/', '', str_replace('/'.$this->to, '', $symbol));
    }

    /**
     * @param array $orderBook Массив заявок (стакан)
     * @param string $symbol Символ тикера
     * @param array|null $oldData Данные предыдущих этапов конвертации
     * @param string|null $from Из какого коина конвертируем
     * @return array Массив с расчетами конвертации
     */
    private function getTransactionsSum(array $orderBook, string $symbol, array $oldData = null, string $from = null): array
    {
        $history = [];

        if(is_null($oldData)){
            $from = $this->from;
            $forSell = $this->count;
            $volume = 'success';
            $transactions = 0;
        }else{
            $forSell = $oldData['convertToSum'];
            $transactions = $oldData['transactions'];
            $volume = $oldData['volume'];
            $history = $oldData['history'];
        }

        // Проверяем, обратная ли конвертация
        $isInvert = Binance::isInvert($symbol, $from);
        $isStraight = Binance::isStraight($symbol);
        $realFromSymbol = $from;
        $realToSymbol = Binance::getToSymbol($symbol, $realFromSymbol);
        $orders = $orderBook[$symbol]['bids'];

        if($isInvert){
            // Учет ситуации, когда у нас обратная конвертация. Прим: символ ETH/BTC, а конвертируем BTC->ETH
            // Тогда расчет делаем не по покупкам, а по продажам
            $orders = $orderBook[$symbol]['asks'];
            $tickerFromSymbol = $realToSymbol;
            $tickerToSymbol = $realFromSymbol;
        }else{
            $tickerFromSymbol = $realFromSymbol;
            $tickerToSymbol = $realToSymbol;
        }

        $order = new Order();
        $order->calcOrder($orders, $forSell, $isInvert, $isStraight);

        // Если объема в *Binance::ORDER_BOOK_LIMIT* (100) ордеров нам не достаточно, обменять не сможем.
        // Делать более 100, пожалуй, коммерческого смысла нет
        if( $order->volumeFailed )
            $volume = 'fail';

        $transactions += $order->transactions;

        // Пушим логи
        $history[] = [
            $symbol => [
                'from' => $from,
                'calculation'=> $order->log,
                'transactions' => $order->transactions,
                'convertToSum' => $order->convertToSum,
                'spend' => $order->spend,
                'fee' => $order->marketFEE,
                'realFromSymbol' => $realFromSymbol,
                'realToSymbol' => $realToSymbol,
                'tickerFromSymbol' => $tickerFromSymbol,
                'tickerToSymbol' => $tickerToSymbol,
                'isStraight' => $isStraight,
                'isInvert' => $isInvert
            ]
        ];

        return [
            'volume' => $volume,
            'transactions' => $transactions,
            'spend' => is_null($oldData) ? $order->spend : self::formatPrice($order->spend),
            'convertToSum' => is_null($oldData) ? $order->convertToSum : self::formatPrice($order->convertToSum),
            'isInvert' => $isInvert,
            'forSell' => $forSell,
            'marketFEE' => $order->marketFEE,
            'symbol' => $symbol,
            'history' => $history
        ];
    }


    /**
     * @param float $number Стоимость
     * @return float Форматированная стоимость
     */
    static public function formatPrice(float $number): float
    {
        $precision = self::PRECISION;
        $separator = '.';
        $numberParts = explode($separator, $number);
        $response = $numberParts[0];
        if (count($numberParts)>1 && $precision > 0) {
            $response .= $separator;
            $response .= substr($numberParts[1], 0, $precision);
        }
        return $response;
    }

    /**
     * @param float $number Сумма
     * @return float Округленная сумма
     */
    static public function roundUp(float $number): float
    {
        return  round($number, self::PRECISION);
    }

    /**
     * @param float $number Сумма
     * @return float Округленная сумма
     */
    static public function roundDown(float $number): float
    {
        return  round($number, self::PRECISION, PHP_ROUND_HALF_DOWN);
    }
}