<?php

namespace App\Classes;

class Calculator
{

    protected string $from;
    protected string $to;
    protected string $symbol;
    protected float $count;
    protected array $variants;

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
        $orderBookArray = [];
        $binance = new Binance();

        // Можно обменять напрямую
        if($this->variants['level0']['status']){
            $orderBook = $binance->getOrderBook($this->symbol);
            $orderBookArray[$this->symbol] = $orderBook;
            $data['level0']['status'] = true;
            $data['level0']['data'] = $this->getTransactionsSum($orderBook, $this->symbol);

        // Можно обменять через 1 промежуточный коин
        }elseif( $this->variants['level1']['status']){

            foreach ($this->variants['level1']['data']['fromToGap'] as $symbol){
                $orderBook = $binance->getOrderBook($symbol);
                $arrayKey = $this->makeToKey($symbol);
                $orderBookArray[$arrayKey] = $orderBook;
                $array['fromToGap'][$arrayKey] = $this->getTransactionsSum($orderBook, $symbol);
            }
            
            foreach ($this->variants['level1']['data']['gapToEnd'] as $symbol){
                $orderBook = $binance->getOrderBook($symbol);
                $arrayKey = $this->makeFromKey($symbol);
                $orderBookArray[$arrayKey] = $orderBook;
                $array['gapToEnd'][$arrayKey] = $this->getTransactionsSum($orderBook, $symbol, $array['fromToGap'][$arrayKey], $arrayKey);
            }
            $data['level1']['status'] = true;
            $data['level1']['data'] = $array['gapToEnd'];

        // Можно обменять через 2 промежуточных коина
        // Не уверен, что такие ситуации есть. Будет очень прожорливая для расчета операция
        }else{
            //TODO: Написать расчет для 2х посредников
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
        $spend = 0;
        $convertToSum = 0;
        $history = [];
        $volumeFailed = true;
        $calculation = [];

        if(is_null($oldData)){

            $from = $this->from;
            $forSell = $this->count;
            $transactions = 0;
            $marketFEE = [];
            $volume = 'success';

        }else{
            
            $forSell = $oldData['convertToSum'];
            $transactions = $oldData['transactions'];
            $marketFEE[ $oldData['symbol'] ] = $oldData['marketFEE'][ $oldData['symbol'] ];
            $volume = $oldData['volume'];
            $history = $oldData['history'];

        }

        // Проверяем, обратная ли конвертация
        $isInvert = $this->isInvert($symbol, $from);

        $marketFEE[$symbol] = ['sum' => 0, 'symbol' => ''];
        $orders = $orderBook[$symbol]['bids'];

        // Учет ситуации, когда у нас обратная конвертация. Прим: символ ETH/BTC, а конвертируем BTC->ETH
        // Тогда расчет делаем не по покупкам, а по продажам
        if ($isInvert)
            $orders = $orderBook[$symbol]['asks'];
        

        foreach ($orders as $order){

            /*
             * $order Example
             * 0.05 BTC -> USDT
             * $symbol = USDT/BTC
             */
            $orderPrice = $order[0];
            $orderCount = $order[1];

            // Сколько максимум BTC мы можем получить на заявку
            $orderMax = $orderPrice*$orderCount; // BTC

            // Комиссия биржи
            $orderFee = $orderMax * Binance::getFee(); // BTC

            // Округляем в большую сторону на 6м знаке после точки
            $orderFee = $this->round_up($orderFee, 6); // 0.0007745913617 -> 0.000775

            // Инкремент числа транзакций
            $transactions++;
            
            // Проверка, нужна ли нам вся заявка
            $need = $forSell - $spend - $orderMax - $orderFee; // USDT

            if($need > 0){
                $iterationSpend = $orderCount*$orderPrice;
                $iterationFee = $orderFee;
                $needAllOrder = true;
            }else{
                $iterationSpend = $forSell - $spend;
                $iterationFee = $this->round_up($iterationSpend * Binance::getFee());
                $needAllOrder = false;
            }
            
            $spend += $iterationSpend+$iterationFee;
            $orderCountIncrement = $iterationSpend * $orderPrice - $iterationFee * $orderPrice;
            $convertToSum += $orderCountIncrement; //BTC

            // Заносим в массив для дальнейшего вывода суммы комиссии
            $marketFEE[$symbol]['sum'] += $iterationFee;
            $marketFEE[$symbol]['symbol'] = $from;


            $calculation[] = [
                'orderPrice' => $orderPrice,
                'orderCount' => $orderCount,
                'orderMax' => $orderMax,
                'iterationFee' => $iterationFee,
                'iterationSpend' => $iterationSpend,
                'orderCountIncrement' => $orderCountIncrement,
                'need' => $need,
                'spend' => $spend,
                'isInvert' => $isInvert,
                'needAllOrder' => $needAllOrder,
                'forSell' => $forSell,
                'convertToSum' => $convertToSum
            ];

            // Выходим из цикла, если ликвидности достаточно и сможем потратить всю исходную сумму
            if($need < 0){
                $volumeFailed = false;
                break;
            }

        }

        // Если объема в *Binance::ORDER_BOOK_LIMIT* (100) ордеров нам не достаточно, обменять не сможем.
        // Делать более 100, пожалуй, коммерческого смысла нет
        if( $volumeFailed )
            $volume = 'fail';

        // Пушим логи
        $history = [
            $symbol => [
                'from' => $from,
                'calculation'=> $calculation,
                'transactions' => $transactions,
                'convertToSum' => $convertToSum,
                'spend' => $spend,
                'fee' => $marketFEE[$symbol]['sum']
            ]
        ];

        // Учет ситуации, когда у нас обратная конвертация. Прим: символ ETH/BTC, а конвертируем BTC->ETH
        if ($isInvert && $convertToSum != 0){
            $convertToSum = $forSell * ($forSell/$convertToSum);
        }

        $convertToSum = $this->formatPrice($convertToSum);

        return [
            'volume' => $volume,
            'transactions' => $transactions,
            'spend' => $spend,
            'convertToSum' => $convertToSum,
            'marketFEE' => $marketFEE,
            'symbol' => $symbol,
            'history' => $history
        ];

    }


    /**
     * @param float $price Стоимость
     * @return float Форматированная стоимость
     */
    private function formatPrice(float $price): float
    {
        return $price;
        //TODO:: написать нормальный метод, который учитывал бы все варианты с необходимой точностью

    }

    /**
     * @param string $symbol Символ тикера
     * @param string $from В какой коин пытаемся конвертировать
     * @return bool В прямом или обратном порядке идет конвертация
     */
    private function isInvert(string $symbol, string $from): bool
    {
        return !str_starts_with($symbol, $from);
    }

    /**
     * @param float $number Сумма
     * @param int $digits Число знаков после запятой, которое округляется в большую сторону
     * @return float Округленная сумма
     */
    private function round_up(float $number, int $digits=5): float
    {

        $limit = pow(0.1, $digits);  // Значимая единица. По умолчанию 0.01
        $pow_limit = pow(10,$digits); // Обратное значение. По умолчанию 100
        $floor_nl = floor($number*$pow_limit)/$pow_limit; // Грубое округление
        $need_up = (($number - $floor_nl) > 0); // Флаг, нужен ли инкремент.

        if ($need_up) { $floor_nl+= $limit; }
        return $floor_nl;
    }

}